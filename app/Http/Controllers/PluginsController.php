<?php

namespace App\Http\Controllers;

use App\Plugins\PluginRegistry;
use App\Services\PluginStoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class PluginsController extends Controller
{
    public function index(Request $request): Response
    {
        $plugins = PluginRegistry::installed();

        $pluginsList = array_map(function ($p) {
            $bannerUrl = null;
            if (! empty($p['banner'])) {
                $bannerUrl = URL::route('plugins.asset', ['slug' => $p['slug'], 'path' => $p['banner']]);
            }
            return [
                'slug' => $p['slug'],
                'name' => $p['name'],
                'version' => $p['version'],
                'description' => $p['description'] ?? null,
                'author' => $p['author'] ?? null,
                'type' => $p['type'] ?? null,
                'banner_url' => $bannerUrl,
                'category' => $p['category'] ?? 'outros',
                'is_registered' => $p['is_registered'] ?? false,
                'is_enabled' => $p['is_enabled'],
                'is_core_bundled' => PluginRegistry::isCoreBundled($p['slug']),
                'settings_url' => $p['routes'] ? '/'.$p['slug'] : null,
            ];
        }, $plugins);

        $store = app(PluginStoreService::class);
        $pluginStore = [
            'store_url' => rtrim((string) config('getfy.plugin_store_url', 'https://store.getfy.cloud'), '/'),
            'submit_url' => $store->getSubmitPluginUrl(),
        ];
        $bundled = PluginRegistry::bundledPluginsPath();
        $installRoot = PluginRegistry::userInstallRoot();
        $pluginsBundledResolved = (is_dir($bundled) ? realpath($bundled) : null) ?: $bundled;
        $pluginsInstallResolved = (is_dir($installRoot) ? realpath($installRoot) : null) ?: $installRoot;

        $installed = array_values(array_filter($plugins, fn ($p) => ! empty($p['is_registered'])));
        $installedPluginSlugs = array_map(fn ($p) => $p['slug'], $installed);
        $installedPluginNames = array_map(fn ($p) => (string) ($p['name'] ?? ''), $installed);

        return Inertia::render('Plugins/Index', [
            'plugins' => $pluginsList,
            'installedPluginSlugs' => $installedPluginSlugs,
            'installedPluginNames' => $installedPluginNames,
            'storePlugins' => [],
            'pluginStore' => $pluginStore,
            'pluginsPath' => $pluginsInstallResolved,
            'plugins_bundled_path' => $pluginsBundledResolved,
            'plugins_install_path' => $pluginsInstallResolved,
        ]);
    }

    /**
     * GET /gerenciar-plugins/store-plugins-list — JSON para o frontend carregar a lista da loja sob demanda.
     */
    public function storePluginsList(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $store = app(PluginStoreService::class);
        if (! $store->isConfigured()) {
            return response()->json(['data' => [], 'error' => 'Loja de plugins indisponível.']);
        }
        $response = $store->listPlugins(
            $request->query('category') ?: null,
            $request->query('search') ?: null
        );
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $error = $response['error'] ?? null;

        return response()->json(['data' => $data, 'error' => $error]);
    }

    /**
     * POST /gerenciar-plugins/register-plugin/{slug} - Registra plugin que está na pasta mas não no DB (ex.: extraído manualmente). Roda migrations se houver.
     */
    public function registerPlugin(string $slug): RedirectResponse
    {
        if (! preg_match('/^[a-z0-9\-_]+$/i', $slug)) {
            return redirect()->route('plugins.index')->with('error', 'Slug inválido.');
        }

        $installed = collect(PluginRegistry::installed())->keyBy('slug');
        if (! $installed->has($slug)) {
            return redirect()->route('plugins.index')->with('error', 'Plugin não encontrado na pasta de plugins.');
        }

        if ($installed->get($slug)['is_registered'] ?? false) {
            return redirect()->route('plugins.index')->with('info', 'Plugin já está instalado.');
        }

        $plugin = $installed->get($slug);

        if (! PluginRegistry::register($slug)) {
            return redirect()->route('plugins.index')->with('error', 'Não foi possível registrar o plugin.');
        }
        if (! PluginRegistry::runPluginMigrations($plugin)) {
            return redirect()->route('plugins.index')->with('error', 'Plugin registrado, mas as migrations falharam. Verifique os logs.');
        }

        return redirect()->route('plugins.index')->with('success', 'Plugin instalado.');
    }
}
