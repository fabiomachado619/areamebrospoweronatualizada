<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Artisan;
use App\Exceptions\ExistingPixCheckoutRedirect;
use App\Support\PendingPixCheckoutResolver;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_AWS_ELB);

        // Redirect de convidados para /login (evita RouteNotFoundException quando route('login') não está disponível, ex.: cache de rotas)
        $middleware->redirectGuestsTo(fn () => url('/login'));

        // Webhooks recebem POST de gateways externos sem CSRF token
        $middleware->validateCsrfTokens(except: [
            'webhooks/gateways/*',
            'webhooks/inbound/*',
            // Alias documentado por integradores CajuPay (POST sem token CSRF)
            'checkout/cajupay/webhook',
        ]);

        $middleware->web(prepend: [
            \App\Http\Middleware\ForceHttpsWhenForwardedProto::class,
            \App\Http\Middleware\EnsureDockerSetup::class,
            \App\Http\Middleware\EnsureInstalled::class,
        ], append: [
            \App\Http\Middleware\ApplyWhiteLabelBranding::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\PreventCacheForHtml::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\RunScheduleFallback::class,
        ]);
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'team.permission' => \App\Http\Middleware\EnsureTeamPermission::class,
            'audit.log' => \App\Http\Middleware\AuditLogMiddleware::class,
            'guest' => \App\Http\Middleware\EnsureGuest::class,
            'api.application' => \App\Http\Middleware\AuthenticateApiApplication::class,
            'member.area.resolve' => \App\Http\Middleware\ResolveMemberAreaProduct::class,
            'member.area.resolve.by.host' => \App\Http\Middleware\ResolveMemberAreaByHost::class,
            'member.area.access' => \App\Http\Middleware\EnsureMemberAreaAccess::class,
            'member.area.signed' => \App\Http\Middleware\SignedOrMemberAreaRedirect::class,
            'admin.tenant' => \App\Http\Middleware\EnsureAdminHasTenant::class,
            'checkout.abuse' => \App\Http\Middleware\PreventCheckoutAbuse::class,
            'checkout.reuse-pix' => \App\Http\Middleware\ReusePendingPixCheckout::class,
            'partner.product' => \App\Http\Middleware\EnsurePartnerProductAccess::class,
            'partner.panel' => \App\Http\Middleware\EnsurePartnerPanel::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->header('X-Inertia')) {
                return redirect()->to('/login')->with('error', 'Sessão expirada. Tente fazer login novamente.');
            }

            return null;
        });

        $exceptions->render(function (ExistingPixCheckoutRedirect $e, Request $request) {
            return PendingPixCheckoutResolver::redirectToPixPage($e->order, $e->request);
        });

        $exceptions->render(function (ThrottleRequestsException|TooManyRequestsHttpException $e, Request $request) {
            $path = trim($request->path(), '/');
            $isCheckout = str_starts_with($path, 'checkout')
                || str_starts_with($path, 'api-checkout')
                || $path === 'renovar';
            if (! $isCheckout) {
                return null;
            }

            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
            $message = $e->getMessage() !== '' && $e->getMessage() !== 'Too Many Attempts.'
                ? $e->getMessage()
                : 'Aguarde um momento antes de tentar novamente.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'retry_after' => is_numeric($retryAfter) ? (int) $retryAfter : null,
                ], 429);
            }

            if ($request->header('X-Inertia')) {
                return back()->with('error', $message);
            }

            return null;
        });

        // Fallback: se der erro por tabela/view inexistente e APP_AUTO_MIGRATE=true, roda migrate e redireciona para tentar de novo
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->expectsJson() && filter_var(config('getfy.auto_migrate', false), FILTER_VALIDATE_BOOLEAN)) {
                $message = $e->getMessage();
                $isTableMissing = $e instanceof QueryException
                    || str_contains($message, '42S02')
                    || str_contains($message, 'Base table or view not found')
                    || str_contains($message, "doesn't exist");
                $previous = $e->getPrevious();
                if (! $isTableMissing && $previous instanceof \Throwable) {
                    $message = $previous->getMessage();
                    $isTableMissing = str_contains($message, '42S02')
                        || str_contains($message, 'Base table or view not found')
                        || str_contains($message, "doesn't exist");
                }
                if ($isTableMissing) {
                    try {
                        Artisan::call('migrate', ['--force' => true]);
                        $url = $request->fullUrl();
                        if ($request->header('X-Inertia')) {
                            return redirect()->to($url)->with('success', 'Migrações executadas automaticamente. Página recarregada.');
                        }

                        return redirect()->to($url)->with('success', 'Migrações executadas automaticamente. Recarregue a página se necessário.');
                    } catch (\Throwable $migrateEx) {
                        report($migrateEx);
                    }
                }
            }

            return null;
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new \App\Jobs\SendSubscriptionRemindersJob)->dailyAt('09:00');
        $schedule->command('checkout:fire-abandoned-cart-webhooks')->everyTenMinutes();
        $schedule->command('checkout:send-cart-recovery-emails')->everyMinute();
        $schedule->command('email-campaign:process')->everyMinute();
        $schedule->command('payments:reconcile-pending --limit=200 --days=45')->everyTwoMinutes();
        $schedule->command('orders:cancel-stale-pending')->hourly();
        $schedule->command('commissions:release')->hourly();
        $schedule->command('payouts:reconcile')->everyTenMinutes();
        $schedule->command('coproducers:expire-invites')->daily();
        $schedule->command('schedule:heartbeat')->everyMinute();
        $schedule->job(new \App\Jobs\QueueHeartbeatJob)->everyMinute();
    })
    ->create();
