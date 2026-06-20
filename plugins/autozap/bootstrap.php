<?php

require_once __DIR__ . '/src/AutoZapEventSubscriber.php';
require_once __DIR__ . '/src/Providers/AutoZapProviderInterface.php';
require_once __DIR__ . '/src/Providers/ZApiProvider.php';
require_once __DIR__ . '/src/Providers/EvolutionApiProvider.php';
require_once __DIR__ . '/src/Providers/MenuiaProvider.php';

return function ($app, \Illuminate\Contracts\Events\Dispatcher $events): void {
    // Register event subscriber when plugin is enabled.
    $events->subscribe(\Plugins\AutoZap\AutoZapEventSubscriber::class);
};

