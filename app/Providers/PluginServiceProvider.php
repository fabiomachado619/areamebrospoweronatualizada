<?php

namespace App\Providers;

use App\Plugins\PluginApiRouteRegistrar;
use App\Plugins\PluginPublicRouteRegistrar;
use App\Plugins\PluginRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    /** @var list<string> */
    private const ALLOWED_MIDDLEWARE = [
        'web',
        'auth',
        'throttle:60,1',
        'throttle:120,1',
        'role:admin|infoprodutor',
        'verified',
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        PluginRegistry::migrateLegacyPluginInstallDirectories();
        PluginRegistry::ensureCoreBundledRegistered();

        $plugins = $this->getPluginsToLoad();
        foreach ($plugins as $plugin) {
            $this->loadPluginBootstrap($plugin);
            $this->loadPluginMigrations($plugin);
            $this->loadPluginEvents($plugin);
            $this->loadPluginCommands($plugin);
            $this->loadPluginSchedule($plugin);
            $this->loadPluginRoutes($plugin);
            $this->loadPluginPublicRoutes($plugin);
            $this->loadPluginApiRoutes($plugin);
            $this->loadPluginViews($plugin);
            $this->loadPluginMiddleware($plugin);
        }
    }

    /**
     * @return array<int, array{slug: string, path: string, menu?: array, routes?: string|array, events?: array}>
     */
    private function getPluginsToLoad(): array
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('plugins')) {
                return PluginRegistry::enabled();
            }
        } catch (\Throwable) {
        }

        return $this->fallbackInstalledFromDisk();
    }

    private function fallbackInstalledFromDisk(): array
    {
        return PluginRegistry::fallbackRowsWithoutDatabase();
    }

    private function loadPluginMigrations(array $plugin): void
    {
        $migrationsPath = $plugin['migrations'] ?? null;
        if (! is_string($migrationsPath) || $migrationsPath === '') {
            return;
        }
        $fullPath = $plugin['path'].DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $migrationsPath);
        if (! is_dir($fullPath)) {
            return;
        }
        $this->loadMigrationsFrom($fullPath);
    }

    private function loadPluginBootstrap(array $plugin): void
    {
        $bootstrap = $plugin['path'].DIRECTORY_SEPARATOR.'bootstrap.php';
        if (! is_file($bootstrap)) {
            return;
        }
        $register = require $bootstrap;
        if (is_callable($register)) {
            $register($this->app, Event::getFacadeRoot());
        }
    }

    private function loadPluginEvents(array $plugin): void
    {
        $events = $plugin['events'] ?? null;
        if (! is_array($events)) {
            return;
        }
        foreach ($events as $eventClass => $listeners) {
            if (! is_string($eventClass) || $eventClass === '') {
                continue;
            }
            if (! is_array($listeners)) {
                $listeners = [$listeners];
            }
            foreach ($listeners as $listener) {
                if (is_string($listener) && $listener !== '' && class_exists($listener)) {
                    Event::listen($eventClass, $listener);
                }
            }
        }
    }

    private function loadPluginCommands(array $plugin): void
    {
        $commands = $plugin['commands'] ?? null;
        if (! is_array($commands)) {
            return;
        }
        $resolved = [];
        foreach ($commands as $command) {
            if (is_string($command) && $command !== '' && class_exists($command)) {
                $resolved[] = $command;
            }
        }
        if ($resolved !== []) {
            $this->commands($resolved);
        }
    }

    private function loadPluginSchedule(array $plugin): void
    {
        $scheduleDecl = $plugin['schedule'] ?? null;
        if (! is_array($scheduleDecl) || $scheduleDecl === []) {
            return;
        }
        $this->app->booted(function () use ($scheduleDecl) {
            $schedule = $this->app->make(Schedule::class);
            foreach ($scheduleDecl as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $command = $entry['command'] ?? $entry['artisan'] ?? null;
                if (! is_string($command) || $command === '') {
                    continue;
                }
                $event = $schedule->command($command);
                $cron = $entry['cron'] ?? $entry['expression'] ?? null;
                if (is_string($cron) && $cron !== '') {
                    $event->cron($cron);
                } elseif (! empty($entry['daily'])) {
                    $event->daily();
                } elseif (! empty($entry['hourly'])) {
                    $event->hourly();
                }
            }
        });
    }

    private function loadPluginRoutes(array $plugin): void
    {
        $routesDecl = $plugin['routes'] ?? null;
        $pluginPath = $plugin['path'];
        $slug = $plugin['slug'];

        $routesFile = null;
        if (is_string($routesDecl) && $routesDecl !== '') {
            $routesFile = $pluginPath.DIRECTORY_SEPARATOR.$routesDecl;
        } elseif ($routesDecl === null || $routesDecl === true) {
            $default = $pluginPath.DIRECTORY_SEPARATOR.'routes.php';
            if (is_file($default)) {
                $routesFile = $default;
            }
        }
        if ($routesFile === null || ! is_file($routesFile)) {
            return;
        }

        $middleware = ['web', 'auth', 'role:admin|infoprodutor'];
        $extra = $plugin['middleware'] ?? null;
        if (is_array($extra)) {
            foreach ($extra as $mw) {
                if (is_string($mw) && in_array($mw, self::ALLOWED_MIDDLEWARE, true)) {
                    $middleware[] = $mw;
                }
            }
        }

        Route::middleware(array_values(array_unique($middleware)))
            ->prefix($slug)
            ->group($routesFile);
    }

    private function loadPluginPublicRoutes(array $plugin): void
    {
        PluginPublicRouteRegistrar::register($plugin);
    }

    private function loadPluginApiRoutes(array $plugin): void
    {
        PluginApiRouteRegistrar::register($plugin);
    }

    private function loadPluginViews(array $plugin): void
    {
        $viewsPath = $plugin['path'].DIRECTORY_SEPARATOR.'views';
        if (! is_dir($viewsPath)) {
            return;
        }
        $slug = preg_replace('/[^a-z0-9_\-]/', '_', (string) ($plugin['slug'] ?? 'plugin'));
        $this->loadViewsFrom($viewsPath, 'plugin.'.$slug);
    }

    private function loadPluginMiddleware(array $plugin): void
    {
        // Middleware extra já aplicado em loadPluginRoutes via manifest.middleware.
    }
}
