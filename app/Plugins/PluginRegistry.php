<?php

namespace App\Plugins;

use App\Models\Plugin as PluginModel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PluginRegistry
{
    /**
     * Plugins versionados com o repositório (ex.: example-gateway).
     */
    public static function bundledPluginsPath(): string
    {
        return rtrim(base_path('plugins'), '/\\');
    }

    /**
     * Plugins nativos versionados no repositório — sempre ativos e não desinstaláveis.
     *
     * @return list<string>
     */
    public static function coreBundledSlugs(): array
    {
        return ['autozap'];
    }

    public static function isCoreBundled(string $slug): bool
    {
        return in_array($slug, self::coreBundledSlugs(), true);
    }

    /**
     * Manifestos no disco dos plugins nativos (independente do registro no banco).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function coreBundledDiskPlugins(): array
    {
        $bySlug = self::collectDiskPluginsBySlug();
        $rows = [];
        foreach (self::coreBundledSlugs() as $slug) {
            if (isset($bySlug[$slug])) {
                $rows[] = $bySlug[$slug];
            }
        }

        return $rows;
    }

    /**
     * Garante registro, ativação e migrations pendentes dos plugins nativos presentes em plugins/.
     */
    public static function ensureCoreBundledRegistered(): void
    {
        if (! self::tableExists()) {
            return;
        }
        foreach (self::coreBundledDiskPlugins() as $plugin) {
            $slug = (string) ($plugin['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            PluginModel::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $plugin['name'] ?? $slug,
                    'version' => $plugin['version'] ?? '1.0.0',
                    'is_enabled' => true,
                ]
            );
            if (self::hasPendingPluginMigrations($plugin)) {
                self::runPluginMigrations($plugin);
            }
        }
    }

    /**
     * Caminho relativo ao projeto da pasta de migrations do plugin, ou null.
     *
     * @param  array<string, mixed>  $plugin
     */
    public static function pluginMigrationsRelativePath(array $plugin): ?string
    {
        $migrationsPath = $plugin['migrations'] ?? null;
        if (! is_string($migrationsPath) || $migrationsPath === '') {
            return null;
        }
        $fullPath = ($plugin['path'] ?? '').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $migrationsPath);
        if (! is_dir($fullPath)) {
            return null;
        }
        $base = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, base_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $full = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);

        return str_replace('\\', '/', Str::after($full, $base));
    }

    /**
     * @param  array<string, mixed>  $plugin
     */
    public static function hasPendingPluginMigrations(array $plugin): bool
    {
        $relativePath = self::pluginMigrationsRelativePath($plugin);
        if ($relativePath === null) {
            return false;
        }
        try {
            $migrator = app('migrator');
            $fullPath = base_path(str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
            if (! is_dir($fullPath)) {
                return false;
            }
            $files = $migrator->getMigrationFiles([$fullPath]);
            if (! is_array($files) || $files === []) {
                return false;
            }
            $ran = $migrator->getRepository()->getRan();
            if (! is_array($ran)) {
                $ran = [];
            }
            foreach (array_keys($files) as $name) {
                if (! in_array($name, $ran, true)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    /**
     * Executa migrations pendentes de um plugin (mesmo fluxo do botão Instalar).
     *
     * @param  array<string, mixed>  $plugin
     */
    public static function runPluginMigrations(array $plugin): bool
    {
        $relativePath = self::pluginMigrationsRelativePath($plugin);
        if ($relativePath === null) {
            return true;
        }
        try {
            Artisan::call('migrate', ['--path' => $relativePath, '--force' => true]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Getfy: migrations do plugin falharam.', [
                'slug' => $plugin['slug'] ?? null,
                'path' => $relativePath,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Pasta persistente para instalações via ZIP/loja.
     *
     * Em Docker (GETFY_DOCKER=true): `.docker/plugins-installed` — fica no volume `getfy_env` montado em `.docker/`,
     * independente de `storage/` (útil quando o update do contentor recria ou substitui dados em storage).
     * Fora de Docker: {@see storage_path}('app/plugins-installed').
     * Override absoluto: GETFY_PLUGINS_USER_PATH no .env.
     */
    public static function userInstallRoot(): string
    {
        $configured = config('plugins.user_install_path');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/\\');
        }

        if (config('plugins.docker_mode')) {
            return rtrim(base_path('.docker/plugins-installed'), '/\\');
        }

        return rtrim(storage_path('app/plugins-installed'), '/\\');
    }

    /**
     * Migra pastas de plugins de locais antigos para {@see userInstallRoot()}.
     * Marcadores: storage/app (raiz do projeto) e .docker/ (cópia desde storage quando em Docker).
     */
    public static function migrateLegacyPluginInstallDirectories(): void
    {
        try {
            $destRoot = self::userInstallRoot();
            if (! is_dir($destRoot)) {
                File::makeDirectory($destRoot, 0755, true);
            }

            $markerProjectRoot = storage_path('app/.getfy-migrated-plugins-from-project-root');
            if (! is_file($markerProjectRoot)) {
                self::migratePluginSubdirsIfPresent(rtrim(base_path('plugins-installed'), '/\\'), $destRoot);
                @file_put_contents($markerProjectRoot, (string) time());
            }

            if (config('plugins.docker_mode')) {
                $markerFromStorage = base_path('.docker/.getfy-migrated-plugins-from-storage-app');
                if (! is_file($markerFromStorage)) {
                    self::migratePluginSubdirsIfPresent(rtrim(storage_path('app/plugins-installed'), '/\\'), $destRoot);
                    @file_put_contents($markerFromStorage, (string) time());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Getfy: migração de pasta legacy de plugins falhou.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Move cada subpasta com plugin.json de $legacy para $destRoot (não sobrescreve destinos já existentes).
     */
    private static function migratePluginSubdirsIfPresent(string $legacy, string $destRoot): void
    {
        if ($legacy === '' || ! is_dir($legacy)) {
            return;
        }
        $legacyReal = realpath($legacy);
        $destReal = realpath($destRoot);
        if ($legacyReal === false) {
            return;
        }
        $destResolved = $destReal !== false ? $destReal : $destRoot;
        if ($legacyReal === $destResolved || str_starts_with($destResolved, $legacyReal.DIRECTORY_SEPARATOR)) {
            return;
        }

        $items = @scandir($legacy);
        if (! is_array($items)) {
            return;
        }
        foreach (array_diff($items, ['.', '..']) as $name) {
            $from = $legacy.DIRECTORY_SEPARATOR.$name;
            if (! is_dir($from) || ! is_file($from.DIRECTORY_SEPARATOR.'plugin.json')) {
                continue;
            }
            $to = $destRoot.DIRECTORY_SEPARATOR.$name;
            if (is_dir($to)) {
                continue;
            }
            File::moveDirectory($from, $to);
        }

        $left = @scandir($legacy);
        if (is_array($left) && count(array_diff($left, ['.', '..'])) === 0) {
            @rmdir($legacy);
        }
    }

    /**
     * @return list<string> Raízes na ordem: bundled → instalações do utilizador → extras (.env).
     *                      O mesmo slug em raízes posteriores sobrepõe manifestos anteriores.
     */
    public static function discoveryRoots(): array
    {
        $roots = [];
        $roots[] = self::bundledPluginsPath();
        $user = self::userInstallRoot();
        if ($user !== '' && ! in_array($user, $roots, true)) {
            $roots[] = $user;
        }
        $extras = config('plugins.extra_scan_paths', []);
        if (is_array($extras)) {
            foreach ($extras as $extra) {
                if (! is_string($extra)) {
                    continue;
                }
                $e = rtrim(trim($extra), '/\\');
                if ($e !== '' && ! in_array($e, $roots, true)) {
                    $roots[] = $e;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Garante a pasta de instalação persistente e devolve o caminho canónico quando possível.
     */
    public static function ensureUserInstallRoot(): string
    {
        $path = self::userInstallRoot();
        if (! is_dir($path)) {
            File::makeDirectory($path, 0755, true);
        }

        return realpath($path) ?: $path;
    }

    /**
     * @deprecated Utilize userInstallRoot() ou discoveryRoots(). Mantido: destino de escrita por omissão.
     */
    public static function pluginsPath(): string
    {
        return self::userInstallRoot();
    }

    /**
     * Diretório absoluto do plugin no disco (bundled ou persistente), ou null.
     */
    public static function resolvePluginDirectory(string $slug): ?string
    {
        foreach (self::installed() as $p) {
            if (($p['slug'] ?? '') === $slug) {
                $dir = $p['path'] ?? null;
                if (is_string($dir) && is_dir($dir)) {
                    return $dir;
                }
            }
        }

        return null;
    }

    /**
     * Quando a tabela `plugins` ainda não existe: carregar todos os manifestos do disco como ativos.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fallbackRowsWithoutDatabase(): array
    {
        $rows = [];
        foreach (self::collectDiskPluginsBySlug() as $row) {
            $rows[] = array_merge($row, [
                'is_registered' => false,
                'is_enabled' => true,
            ]);
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function collectDiskPluginsBySlug(): array
    {
        $bySlug = [];
        foreach (self::discoveryRoots() as $root) {
            if ($root === '' || ! is_dir($root)) {
                continue;
            }
            $dirs = array_filter(glob($root.DIRECTORY_SEPARATOR.'*'), 'is_dir');
            foreach ($dirs as $dir) {
                $manifestFile = $dir.DIRECTORY_SEPARATOR.'plugin.json';
                if (! is_file($manifestFile)) {
                    continue;
                }
                $manifest = self::readManifest($dir);
                if (! $manifest) {
                    continue;
                }
                $slug = $manifest['slug'] ?? basename($dir);
                $row = [
                    'slug' => $slug,
                    'name' => $manifest['name'] ?? $slug,
                    'version' => $manifest['version'] ?? '1.0.0',
                    'path' => $dir,
                    'type' => $manifest['type'] ?? null,
                    'banner' => ! empty($manifest['banner']) ? $manifest['banner'] : null,
                    'category' => ! empty($manifest['category']) ? $manifest['category'] : 'outros',
                    'menu' => $manifest['menu'] ?? null,
                    'routes' => $manifest['routes'] ?? null,
                    'public_routes' => $manifest['public_routes'] ?? null,
                    'events' => $manifest['events'] ?? [],
                    'migrations' => $manifest['migrations'] ?? null,
                    'description' => $manifest['description'] ?? null,
                    'author' => $manifest['author'] ?? null,
                    'settings_tab' => $manifest['settings_tab'] ?? null,
                    'integration_app' => $manifest['integration_app'] ?? null,
                    'product_panel' => $manifest['product_panel'] ?? null,
                    'frontend' => $manifest['frontend'] ?? null,
                    'checkout_builder_templates' => $manifest['checkout_builder_templates'] ?? null,
                    'product_card_actions' => $manifest['product_card_actions'] ?? null,
                    'product_form_sections' => $manifest['product_form_sections'] ?? null,
                    'dashboard_widgets' => $manifest['dashboard_widgets'] ?? null,
                    'member_area_panels' => $manifest['member_area_panels'] ?? null,
                    'order_fulfillment_providers' => $manifest['order_fulfillment_providers'] ?? null,
                    'vendas_row_actions' => $manifest['vendas_row_actions'] ?? null,
                    'order_detail_panels' => $manifest['order_detail_panels'] ?? null,
                    'financeiro_tabs' => $manifest['financeiro_tabs'] ?? null,
                    'api_routes' => $manifest['api_routes'] ?? null,
                    'commerce_scopes' => $manifest['commerce_scopes'] ?? null,
                    'commands' => $manifest['commands'] ?? null,
                    'schedule' => $manifest['schedule'] ?? null,
                    'middleware' => $manifest['middleware'] ?? null,
                ];

                // Uma instalação persistente (ex.: ZIP) no mesmo slug sobrescreve a pasta bundled.
                // Se o plugin.json da cópia for minimalista, preservar blocos de UI já lidos
                // de uma deteção anterior (ex.: integração / painel no produto).
                if (isset($bySlug[$slug])) {
                    $prev = $bySlug[$slug];
                    foreach (['integration_app', 'product_panel', 'settings_tab'] as $uiKey) {
                        $val = $row[$uiKey] ?? null;
                        if ($val === null || (is_array($val) && $val === [])) {
                            $p = $prev[$uiKey] ?? null;
                            if ($p !== null && (! is_array($p) || $p !== [])) {
                                $row[$uiKey] = $p;
                            }
                        }
                    }
                }
                $bySlug[$slug] = $row;
            }
        }

        return $bySlug;
    }

    /**
     * List all plugins found on disk (with valid plugin.json).
     * Merges with DB state for is_enabled when table exists.
     *
     * @return array<int, array{slug: string, name: string, version: string, path: string, is_enabled: bool, menu?: array, routes?: string|array, events?: array}>
     */
    public static function installed(): array
    {
        $dbPlugins = [];
        if (self::tableExists()) {
            $dbPlugins = PluginModel::all()->keyBy('slug')->all();
        }

        $result = [];
        foreach (self::collectDiskPluginsBySlug() as $slug => $row) {
            $record = $dbPlugins[$slug] ?? null;
            $isCoreBundled = self::isCoreBundled($slug);
            $isRegistered = $record !== null || $isCoreBundled;
            $isEnabled = $isCoreBundled
                ? true
                : ($record ? (bool) $record->is_enabled : false);

            $result[] = array_merge($row, [
                'is_registered' => $isRegistered,
                'is_enabled' => $isEnabled,
                'is_core_bundled' => $isCoreBundled,
            ]);
        }

        return $result;
    }

    /**
     * Abas extra em Configurações declaradas no plugin.json (plugins ativos).
     *
     * @return array<int, array{id: string, label: string, component: string}>
     */
    public static function getSettingsTabs(): array
    {
        $items = [];
        foreach (self::enabled() as $plugin) {
            $tab = $plugin['settings_tab'] ?? null;
            if (! is_array($tab)) {
                continue;
            }
            $id = trim((string) ($tab['id'] ?? ''));
            $label = trim((string) ($tab['label'] ?? ''));
            $component = trim((string) ($tab['component'] ?? ''));
            if ($id === '' || $label === '' || $component === '') {
                continue;
            }
            if (! self::isValidUiComponentDeclaration($plugin, $component, 'settings')) {
                continue;
            }
            $items[] = PluginExtensionRegistry::enrichUiSlotItem($plugin, [
                'id' => $id,
                'label' => $label,
                'component' => $component,
            ], 'settings');
        }

        return $items;
    }

    /**
     * Apps extras na página de Integrações declarados no plugin.json (plugins ativos).
     *
     * @return array<int, array{id: string, name: string, description?: string, image?: string, component: string}>
     */
    public static function getIntegrationApps(): array
    {
        $items = [];
        foreach (self::enabled() as $plugin) {
            $app = $plugin['integration_app'] ?? null;
            if (! is_array($app)) {
                continue;
            }
            $id = trim((string) ($app['id'] ?? $plugin['slug'] ?? ''));
            $name = trim((string) ($app['name'] ?? $plugin['name'] ?? $id));
            $component = trim((string) ($app['component'] ?? ''));
            if ($id === '' || $name === '' || $component === '') {
                continue;
            }
            if (! self::isValidUiComponentDeclaration($plugin, $component, 'integrations')) {
                continue;
            }
            $description = isset($app['description']) ? trim((string) $app['description']) : '';
            $image = isset($app['image']) ? trim((string) $app['image']) : '';

            // If plugin declares a relative image path, serve from /plugins/{slug}/assets/{path}.
            if ($image !== '' && ! str_contains($image, '://') && ! str_starts_with($image, '/')) {
                try {
                    $image = URL::route('plugins.asset', ['slug' => $plugin['slug'], 'path' => $image]);
                } catch (\Throwable) {
                    $image = '';
                }
            }
            $items[] = PluginExtensionRegistry::enrichUiSlotItem($plugin, [
                'id' => $id,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'image' => $image !== '' && $image !== null ? $image : null,
                'component' => $component,
            ], 'integrations');
        }

        return $items;
    }

    /**
     * Painéis extras na edição de produto declarados no plugin.json (plugins ativos).
     *
     * @return array<int, array{id: string, label: string, component: string}>
     */
    public static function getProductPanels(): array
    {
        $items = [];
        foreach (self::enabled() as $plugin) {
            $panel = $plugin['product_panel'] ?? null;
            if (! is_array($panel)) {
                continue;
            }
            $id = trim((string) ($panel['id'] ?? $plugin['slug'] ?? ''));
            $label = trim((string) ($panel['label'] ?? $plugin['name'] ?? $id));
            $component = trim((string) ($panel['component'] ?? ''));
            if ($id === '' || $label === '' || $component === '') {
                continue;
            }
            if (! self::isValidUiComponentDeclaration($plugin, $component, 'product_panel')) {
                continue;
            }
            $items[] = PluginExtensionRegistry::enrichUiSlotItem($plugin, [
                'id' => $id,
                'label' => $label,
                'component' => $component,
            ], 'product_panel');
        }

        return $items;
    }

    /**
     * UI legado (Plugin/...) ou runtime (frontend.exports no manifest + dist).
     */
    private static function isValidUiComponentDeclaration(array $plugin, string $component, string $slot): bool
    {
        if ($component === '') {
            return false;
        }
        if (str_starts_with($component, 'Plugin/')) {
            return true;
        }
        if (PluginExtensionRegistry::hasRuntimeFrontend($plugin)) {
            $export = PluginExtensionRegistry::resolveExportForSlot($plugin, $slot);

            return $export !== null && $export !== '';
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfig(string $slug, array $default = []): array
    {
        if (! self::tableExists()) {
            return $default;
        }
        $record = PluginModel::find($slug);
        if (! $record) {
            return $default;
        }
        $config = $record->config;
        if (! is_array($config)) {
            return $default;
        }

        return array_replace($default, $config);
    }

    public static function setConfig(string $slug, array $config): bool
    {
        if (! self::tableExists()) {
            return false;
        }
        $record = PluginModel::find($slug);
        if (! $record) {
            return false;
        }
        $record->update(['config' => $config]);

        return true;
    }

    /**
     * @return array<int, string>
     */
    public static function validatePluginPackage(string $pluginPath): array
    {
        $errors = [];
        $manifest = self::readManifest($pluginPath);
        if ($manifest === null) {
            return ['plugin.json ausente ou inválido.'];
        }
        $slug = (string) ($manifest['slug'] ?? basename($pluginPath));
        if ($slug === '') {
            $errors[] = 'slug obrigatório no manifest.';
        }
        $bootstrap = $pluginPath.DIRECTORY_SEPARATOR.'bootstrap.php';
        if (is_file($bootstrap)) {
            $syntax = self::checkPhpSyntax($bootstrap);
            if ($syntax !== null) {
                $errors[] = 'bootstrap.php: '.$syntax;
            }
        }
        $plugin = array_merge($manifest, ['slug' => $slug, 'path' => $pluginPath]);
        $errors = array_merge($errors, PluginExtensionRegistry::validateFrontend($plugin));
        $errors = array_merge($errors, PluginPublicRouteRegistrar::validatePublicRoutePrefixes());
        $errors = array_merge($errors, PluginApiRouteRegistrar::validateApiRoutePrefixes());
        $errors = array_merge($errors, self::validateDistSize($pluginPath));
        $errors = array_merge($errors, self::validateApiRoutesFile($pluginPath, $manifest));
        $errors = array_merge($errors, self::validateCommerceScopes($manifest));
        $routes = $manifest['routes'] ?? null;
        if (is_string($routes) && $routes !== '') {
            $routesFile = $pluginPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $routes);
            if (! is_file($routesFile)) {
                $errors[] = "Arquivo de rotas não encontrado: {$routes}";
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    private static function validateApiRoutesFile(string $pluginPath, array $manifest): array
    {
        $decl = $manifest['api_routes'] ?? null;
        if ($decl === null || $decl === '' || $decl === []) {
            return [];
        }
        $file = is_array($decl) ? ($decl['file'] ?? $decl['path'] ?? 'routes-api.php') : $decl;
        if (! is_string($file) || $file === '') {
            return ['api_routes: arquivo inválido.'];
        }
        $routesFile = $pluginPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
        if (! is_file($routesFile)) {
            return ["api_routes: arquivo não encontrado: {$file}"];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    private static function validateCommerceScopes(array $manifest): array
    {
        $scopes = $manifest['commerce_scopes'] ?? null;
        if ($scopes === null) {
            return [];
        }
        if (! is_array($scopes)) {
            return ['commerce_scopes deve ser um array.'];
        }
        $allowed = ['catalog:read', 'cart:write', 'checkout:start', 'orders:read'];
        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! in_array($scope, $allowed, true)) {
                return ['commerce_scopes contém valor inválido: '.(string) $scope];
            }
        }

        return [];
    }

    private static function validateDistSize(string $pluginPath): array
    {
        $dist = $pluginPath.DIRECTORY_SEPARATOR.'dist';
        if (! is_dir($dist)) {
            return [];
        }
        $maxBytes = (int) config('plugins.max_dist_bytes', 15 * 1024 * 1024);
        $total = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }
        if ($total > $maxBytes) {
            return ['dist/ excede tamanho máximo ('.round($total / 1024 / 1024, 1).' MB).'];
        }

        return [];
    }

    private static function checkPhpSyntax(string $file): ?string
    {
        $phpBin = (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '')
            ? PHP_BINARY
            : 'php';
        $output = [];
        $code = 0;
        @exec($phpBin.' -l '.escapeshellarg($file).' 2>&1', $output, $code);
        if ($code === 0) {
            return null;
        }
        $msg = trim(implode(' ', $output));
        if ($msg !== '' && (str_contains(strtolower($msg), 'não é reconhecido') || str_contains(strtolower($msg), 'not recognized'))) {
            return null;
        }

        return $msg ?: 'erro de sintaxe PHP';
    }

    /**
     * Only plugins that are enabled (for loading bootstrap and routes).
     *
     * @return array<int, array{slug: string, name: string, version: string, path: string, menu?: array, routes?: string|array, events?: array}>
     */
    public static function enabled(): array
    {
        $installed = self::installed();

        return array_values(array_filter($installed, fn ($p) => $p['is_enabled']));
    }

    public static function enable(string $slug): bool
    {
        self::syncFromDisk();
        if (! self::tableExists()) {
            return false;
        }
        $plugin = PluginModel::find($slug);
        if (! $plugin) {
            $plugin = PluginModel::create([
                'slug' => $slug,
                'name' => $slug,
                'version' => '1.0.0',
                'is_enabled' => true,
            ]);
        } else {
            $plugin->update(['is_enabled' => true]);
        }
        self::clearRouteCacheIfCached();

        return true;
    }

    public static function disable(string $slug): bool
    {
        if (self::isCoreBundled($slug)) {
            return false;
        }
        if (! self::tableExists()) {
            return false;
        }
        $plugin = PluginModel::find($slug);
        if ($plugin) {
            $plugin->update(['is_enabled' => false]);
            self::clearRouteCacheIfCached();

            return true;
        }

        return false;
    }

    private static function isPluginDirUnderAllowedRoots(string $pluginDirReal): bool
    {
        $sep = DIRECTORY_SEPARATOR;
        foreach (self::discoveryRoots() as $root) {
            if ($root === '' || ! is_dir($root)) {
                continue;
            }
            $base = realpath($root);
            if ($base === false) {
                continue;
            }
            if ($pluginDirReal === $base) {
                return false;
            }
            $prefix = $base.$sep;
            if (str_starts_with($pluginDirReal, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uninstall plugin: delete plugin directory from disk, then remove from DB.
     * Pass $pluginPath (from installed()['path']) when the folder name differs from slug.
     */
    public static function uninstall(string $slug, ?string $pluginPath = null): bool
    {
        if (self::isCoreBundled($slug)) {
            return false;
        }

        $pluginDir = $pluginPath !== null && $pluginPath !== ''
            ? realpath($pluginPath)
            : realpath(self::userInstallRoot().DIRECTORY_SEPARATOR.$slug);

        if ($pluginDir === false || ! is_dir($pluginDir)) {
            $pluginDir = realpath(self::bundledPluginsPath().DIRECTORY_SEPARATOR.$slug);
        }

        if ($pluginDir !== false && is_dir($pluginDir)) {
            if (! self::isPluginDirUnderAllowedRoots($pluginDir)) {
                return false;
            }
            if (! self::deletePluginDirectory($pluginDir)) {
                return false;
            }
        }

        if (self::tableExists()) {
            PluginModel::where('slug', $slug)->delete();
        }
        self::clearRouteCacheIfCached();

        return true;
    }

    /**
     * Recursively delete a directory. Makes files/dirs writable first to avoid failures on Windows.
     */
    private static function deletePluginDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                if (! is_link($full) && ! self::deletePluginDirectory($full)) {
                    return false;
                }
            } else {
                @chmod($full, 0777);
                if (! @unlink($full) && file_exists($full)) {
                    return false;
                }
            }
        }
        @chmod($dir, 0777);
        if (! @rmdir($dir) && is_dir($dir)) {
            return false;
        }

        return true;
    }

    /**
     * Read and validate plugin.json. Returns manifest array or null.
     *
     * @return array<string, mixed>|null
     */
    public static function readManifest(string $pluginPath): ?array
    {
        $manifestFile = rtrim($pluginPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plugin.json';
        if (! is_file($manifestFile)) {
            return null;
        }
        $raw = file_get_contents($manifestFile);
        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return null;
        }
        if (empty($manifest['name'])) {
            $manifest['name'] = basename($pluginPath);
        }
        if (empty($manifest['slug'])) {
            $manifest['slug'] = basename($pluginPath);
        }
        if (empty($manifest['version'])) {
            $manifest['version'] = '1.0.0';
        }

        return $manifest;
    }

    /**
     * Menu items for the sidebar: aggregate from all enabled plugins that have "menu" in manifest.
     * Format: [{ name, href, icon? }, ...]
     *
     * @return array<int, array{name: string, href: string, icon?: string}>
     */
    public static function getMenuItems(): array
    {
        $items = [];
        foreach (self::enabled() as $plugin) {
            $menu = $plugin['menu'] ?? null;
            if (! is_array($menu)) {
                continue;
            }
            foreach ($menu as $entry) {
                if (empty($entry['label']) || empty($entry['href'])) {
                    continue;
                }
                $items[] = [
                    'name' => $entry['label'],
                    'href' => $entry['href'],
                    'icon' => $entry['icon'] ?? null,
                ];
            }
        }

        return $items;
    }

    /**
     * Register a plugin that is on disk but not yet in DB (e.g. extracted manually).
     * Creates the DB record and returns true. Caller should run migrations after.
     */
    public static function register(string $slug): bool
    {
        $installed = collect(self::installed())->keyBy('slug');
        $plugin = $installed->get($slug);
        if (! $plugin) {
            return false;
        }
        if (! self::tableExists()) {
            return false;
        }
        PluginModel::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $plugin['name'],
                'version' => $plugin['version'],
                'is_enabled' => true,
            ]
        );
        self::clearRouteCacheIfCached();

        return true;
    }

    /**
     * Sync DB from disk: insert any new plugin dirs as enabled by default; do not disable existing.
     */
    public static function syncFromDisk(): void
    {
        if (! self::tableExists()) {
            return;
        }
        foreach (self::installed() as $p) {
            $record = PluginModel::firstOrCreate(
                ['slug' => $p['slug']],
                [
                    'name' => $p['name'],
                    'version' => $p['version'],
                    'is_enabled' => true,
                ]
            );
            if (self::isCoreBundled($p['slug']) && ! $record->is_enabled) {
                $record->update(['is_enabled' => true]);
            }
        }
    }

    private static function tableExists(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('plugins');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rotas de plugins são registradas no boot conforme o que está habilitado no banco.
     * Com `php artisan route:cache`, a lista fica congelada até limpar o cache.
     */
    private static function clearRouteCacheIfCached(): void
    {
        try {
            if (app()->routesAreCached()) {
                Artisan::call('route:clear');
            }
        } catch (\Throwable) {
            //
        }
    }
}
