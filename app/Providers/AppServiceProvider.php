<?php

namespace App\Providers;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Listeners\SendAccessEmailOnOrderCompleted;
use App\Listeners\SendPanelPushOnBoletoGenerated;
use App\Listeners\SendPanelPushOnOrderCompleted;
use App\Listeners\SendPanelPushOnPixGenerated;
use App\Listeners\CademiEventSubscriber;
use App\Listeners\SpedyEventSubscriber;
use App\Listeners\UtmifyEventSubscriber;
use App\Listeners\SendApiApplicationWebhookListener;
use App\Listeners\RevokeAccessOnOrderRefunded;
use App\Listeners\SendMetaPurchaseCapiOnOrderCompleted;
use App\Events\OrderRefunded;
use App\Listeners\WebhookEventSubscriber;
use App\Support\DockerSetupState;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Plugins\PluginRegistry;
use App\Services\MemberAreaResolver;
use App\Support\SocialPreviewOpenGraph;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Override de URL pública (ngrok, cloudflared, tunnels de dev) — quando setada, força
        // todas as URLs (url(), route(), redirect(), asset/Vite) e o cookie de sessão a usarem
        // esse host. Útil quando o tunnel reescreve o header Host (ex.: ngrok --host-header)
        // e o Apache local atende por outro vhost (ex.: getfy-opensource.test). Sem isso o
        // navegador é redirecionado de volta para o vhost local.
        $publicOverride = trim((string) env('PUBLIC_URL_OVERRIDE', ''));
        if ($publicOverride !== '') {
            $publicOverride = rtrim($publicOverride, '/');
            URL::forceRootUrl($publicOverride);
            if (str_starts_with($publicOverride, 'https://')) {
                URL::forceScheme('https');
            }
            $publicHost = parse_url($publicOverride, PHP_URL_HOST);
            if (is_string($publicHost) && $publicHost !== '') {
                config(['session.domain' => $publicHost]);
            }

            // O disk "public" do Laravel resolve sua URL via APP_URL no momento de boot do
            // config (config/filesystems.php → 'url' => env('APP_URL').'/storage'). Como aqui
            // a gente força o domínio público depois disso, Storage::url() / asset() ainda
            // emitem URLs com APP_URL local — gerando Mixed Content quando a página é
            // servida via HTTPS pelo tunnel. Sobrescrevemos para acompanhar o override.
            // Também sincronizamos config('app.url') para que helpers que leem dele direto
            // (ex.: alguns geradores de URL de cdns/imagens) também respeitem o tunnel.
            config([
                'app.url' => $publicOverride,
                'filesystems.disks.public.url' => $publicOverride . '/storage',
            ]);
        }

        // Gera links absolutos (Vite, asset, route) em HTTPS quando APP_URL já é https — evita
        // mistura http/https atrás de proxy que não envia X-Forwarded-Proto (ex.: domínio custom na cloud).
        $appUrl = (string) config('app.url', '');
        if ($appUrl !== '' && str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }

        $this->ensureRuntimeDirectories();
        $this->fallbackRedisToDatabase();
        $this->fallbackInvalidQueueConnectionToSync();
        $this->bootCloudFolder();
        $this->bootRouteBindings();
        $this->bootSocialPreviewOpenGraph();
        if (DockerSetupState::isDocker() && class_exists(\Illuminate\Support\Facades\Vite::class)) {
            \Illuminate\Support\Facades\Vite::useHotFile(storage_path('framework/vite.hot'));
        }

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $checkoutProcessPerMinute = max(1, (int) config('checkout_security.rate.process_per_minute', 10));
        RateLimiter::for('checkout-process', function (Request $request) use ($checkoutProcessPerMinute) {
            return Limit::perMinute($checkoutProcessPerMinute)->by($request->ip());
        });

        $checkoutCardPerMinute = max(1, (int) config('checkout_security.rate.card_per_minute', 5));
        RateLimiter::for('checkout-card', function (Request $request) use ($checkoutCardPerMinute) {
            $method = strtolower((string) $request->input('payment_method', ''));
            if ($method !== 'card') {
                return Limit::none();
            }

            return Limit::perMinute($checkoutCardPerMinute)->by($request->ip());
        });

        // Limite específico para geração de PIX no checkout (por IP).
        // Só conta quando payment_method === 'pix'. Para outros métodos não impõe limite extra.
        $checkoutPixPer5Min = max(1, (int) config('checkout_security.rate.pix_per_5_minutes', 6));
        RateLimiter::for('checkout-pix', function (Request $request) use ($checkoutPixPer5Min) {
            $method = strtolower((string) $request->input('payment_method', ''));
            if ($method !== 'pix') {
                return Limit::none();
            }

            return Limit::perMinutes(5, $checkoutPixPer5Min)->by($request->ip());
        });

        $checkoutEmailPerHour = max(1, (int) config('checkout_security.rate.email_per_hour', 15));
        RateLimiter::for('checkout-email', function (Request $request) use ($checkoutEmailPerHour) {
            $email = strtolower(trim((string) $request->input('email', '')));
            if ($email === '') {
                return Limit::none();
            }

            return Limit::perHour($checkoutEmailPerHour)->by(sha1($email));
        });

        $checkoutProductIpPerHour = max(1, (int) config('checkout_security.rate.product_ip_per_hour', 15));
        RateLimiter::for('checkout-product-ip', function (Request $request) use ($checkoutProductIpPerHour) {
            $productId = (string) $request->input('product_id', '');
            if ($productId === '') {
                return Limit::none();
            }

            return Limit::perHour($checkoutProductIpPerHour)->by($request->ip().'|'.$productId);
        });

        $checkoutShowPerMinute = max(30, (int) config('checkout_security.rate.show_per_minute', 120));
        RateLimiter::for('checkout-show', function (Request $request) use ($checkoutShowPerMinute) {
            return Limit::perMinute($checkoutShowPerMinute)->by($request->ip());
        });

        $payoutPerMinute = max(1, (int) config('commissions.payout_rate_limit_per_minute', 3));
        RateLimiter::for('payout', function (Request $request) use ($payoutPerMinute) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute($payoutPerMinute)->by('payout|'.$key);
        });

        Queue::after(function (): void {
            Cache::put('queue_heartbeat', now()->toIso8601String(), now()->addMinutes(5));
        });

        Event::listen(OrderCompleted::class, SendPanelPushOnOrderCompleted::class, 100);
        Event::listen(PixGenerated::class, SendPanelPushOnPixGenerated::class, 100);
        Event::listen(BoletoGenerated::class, SendPanelPushOnBoletoGenerated::class, 100);
        Event::listen(OrderCompleted::class, \App\Listeners\AllocateCommissionsOnOrderCompleted::class);
        Event::listen(OrderCompleted::class, SendAccessEmailOnOrderCompleted::class);
        Event::listen(OrderCompleted::class, SendMetaPurchaseCapiOnOrderCompleted::class);
        Event::listen(OrderCompleted::class, \App\Listeners\InvalidateDashboardCacheOnOrderCompleted::class);
        Event::listen(OrderRefunded::class, RevokeAccessOnOrderRefunded::class);
        Event::subscribe(WebhookEventSubscriber::class);
        Event::subscribe(SendApiApplicationWebhookListener::class);
        Event::subscribe(UtmifyEventSubscriber::class);
        Event::subscribe(SpedyEventSubscriber::class);
        Event::subscribe(CademiEventSubscriber::class);

        VerifyEmail::toMailUsing(function (object $notifiable, string $verificationUrl) {
            [$appName, $logoUrl] = $this->resolveWhiteLabelEmailBranding($notifiable);

            return (new MailMessage)
                ->from((string) config('mail.from.address'), $appName)
                ->markdown('notifications::email', ['logoUrl' => $logoUrl, 'appName' => $appName])
                ->subject('Confirme seu e-mail')
                ->greeting('Olá!')
                ->line('Clique no botão abaixo para confirmar seu endereço de e-mail.')
                ->action('Confirmar e-mail', $verificationUrl)
                ->line('Se você não criou uma conta, nenhuma ação é necessária.');
        });

        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $params = [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ];
            $redirect = app()->bound('password_reset_redirect') ? app('password_reset_redirect') : null;
            if ($redirect !== null) {
                $params['redirect'] = $redirect;
            }
            $url = url(route('password.reset', $params, false));
            $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
            [$appName, $logoUrl] = $this->resolveWhiteLabelEmailBranding($notifiable);

            return (new MailMessage)
                ->from((string) config('mail.from.address'), $appName)
                ->markdown('notifications::email', ['logoUrl' => $logoUrl, 'appName' => $appName])
                ->subject('Redefinição de senha')
                ->greeting('Olá!')
                ->line('Você está recebendo este e-mail porque recebemos uma solicitação de redefinição de senha da sua conta.')
                ->action('Redefinir senha', $url)
                ->line('Este link expira em '.$expire.' minutos.')
                ->line('Se você não solicitou a redefinição de senha, nenhuma ação é necessária.');
        });
    }

    /**
     * @return array{0: string, 1: string|null} [appName, logoUrl]
     */
    private function resolveWhiteLabelEmailBranding(object $notifiable): array
    {
        $defaultName = (string) config('app.name', 'Power On');
        $defaultLogo = '/brand/logo-full.png';

        try {
            $enabled = collect(PluginRegistry::enabled())->contains(fn ($p) => ($p['slug'] ?? null) === 'white-label');
            if (! $enabled) {
                return [$defaultName, $defaultLogo];
            }
        } catch (\Throwable) {
            return [$defaultName, $defaultLogo];
        }

        if (! class_exists(\Plugins\WhiteLabel\WhiteLabelSetting::class) || ! class_exists(\Plugins\WhiteLabel\ApplyWhiteLabelConfig::class)) {
            return [$defaultName, $defaultLogo];
        }

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('white_label_settings')) {
                return [$defaultName, $defaultLogo];
            }
        } catch (\Throwable) {
            return [$defaultName, $defaultLogo];
        }

        $tenantId = null;
        try {
            $tenantId = $notifiable->tenant_id ?? null;
        } catch (\Throwable) {
            $tenantId = null;
        }

        try {
            $global = \Plugins\WhiteLabel\WhiteLabelSetting::query()->whereNull('tenant_id')->first();
            $tenant = $tenantId !== null
                ? \Plugins\WhiteLabel\WhiteLabelSetting::query()->where('tenant_id', $tenantId)->first()
                : null;

            $globalData = is_array($global?->data) ? $global->data : [];
            $tenantData = is_array($tenant?->data) ? $tenant->data : [];
            $branding = \Plugins\WhiteLabel\ApplyWhiteLabelConfig::mergeLayers($globalData, $tenantData);

            $appName = trim((string) ($branding['app_name'] ?? ''));
            if ($appName === '') {
                $appName = $defaultName;
            }

            $logoUrl = null;
            $logoRaw = trim((string) ($branding['app_logo'] ?? ''));
            if ($logoRaw !== '' && filter_var($logoRaw, FILTER_VALIDATE_URL)) {
                $logoUrl = $logoRaw;
            } else {
                $logoUrl = $defaultLogo;
            }

            return [$appName, $logoUrl];
        } catch (\Throwable) {
            return [$defaultName, $defaultLogo];
        }
    }

    private function bootCloudFolder(): void
    {
        if (! is_dir(base_path('cloud'))) {
            return;
        }

        $bootstrap = base_path('cloud/bootstrap.php');
        if (! is_file($bootstrap)) {
            return;
        }

        try {
            $register = require $bootstrap;
            if (is_callable($register)) {
                $register($this->app);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function ensureRuntimeDirectories(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $paths = [
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Se Redis estiver configurado mas indisponível, usa database para cache, sessão e fila.
     */
    private function fallbackRedisToDatabase(): void
    {
        $usesRedis = config('cache.default') === 'redis'
            || config('session.driver') === 'redis'
            || config('queue.default') === 'redis';

        if (! $usesRedis) {
            return;
        }

        try {
            Redis::connection()->ping();
        } catch (\Throwable $e) {
            if (config('cache.default') === 'redis') {
                config(['cache.default' => 'database']);
            }
            if (config('session.driver') === 'redis') {
                config(['session.driver' => 'database']);
            }
            if (config('queue.default') === 'redis') {
                config(['queue.default' => 'database']);
            }
        }
    }

    private function fallbackInvalidQueueConnectionToSync(): void
    {
        $default = (string) config('queue.default', 'sync');
        $connections = config('queue.connections', []);
        if (! is_array($connections) || $connections === []) {
            config(['queue.default' => 'sync']);
            return;
        }
        if (! array_key_exists($default, $connections)) {
            config(['queue.default' => 'sync']);
        }
    }

    private function bootSocialPreviewOpenGraph(): void
    {
        View::composer('app', function ($view): void {
            $data = $view->getData();
            if (! empty($data['openGraph'])) {
                return;
            }

            $request = request();
            $path = $request->path();
            $isCheckout = str_starts_with($path, 'c/')
                || str_starts_with($path, 'checkout')
                || str_starts_with($path, 'api-checkout');
            if ($isCheckout) {
                return;
            }

            $isMemberArea = str_starts_with($path, 'm/') || $request->attributes->get('member_area_slug');
            if ($isMemberArea) {
                $product = $request->route('product') ?? $request->attributes->get('member_area_product');
                if (! $product instanceof Product) {
                    $resolved = app(MemberAreaResolver::class)->resolve($request);
                    $product = $resolved['product'] ?? null;
                }
                if ($product instanceof Product && $product->type === Product::TYPE_AREA_MEMBROS) {
                    $view->with('openGraph', SocialPreviewOpenGraph::forMemberArea($product, $request));
                }

                return;
            }

            $view->with('openGraph', SocialPreviewOpenGraph::forPlatform($request));
        });
    }

    private function bootRouteBindings(): void
    {
        Route::bind('payout', function (string $value) {
            $user = auth()->user();
            if (! $user) {
                abort(403);
            }

            return PayoutRequest::query()
                ->where('tenant_id', $user->tenant_id)
                ->whereKey($value)
                ->firstOrFail();
        });
    }
}
