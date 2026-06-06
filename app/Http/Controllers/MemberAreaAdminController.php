<?php

namespace App\Http\Controllers;

use App\Services\MemberAreaAdminService;
use App\Services\MemberHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemberAreaAdminController extends Controller
{
    public function __construct(
        protected MemberAreaAdminService $adminService,
        protected MemberHubService $memberHubService,
    ) {}

    public function index(Request $request): Response
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $tab = $this->resolveTab($request->query('tab', 'cursos'));

        $payload = $this->adminService->buildPageData($tenantId, $request);

        $inertiaProps = array_merge($payload, [
            'tab' => $tab,
        ]);

        if ($tab === 'alunos') {
            $inertiaProps = array_merge($inertiaProps, app(AlunosController::class)->memberAreaIndexProps($request));
        }

        if ($tab === 'webhooks') {
            $inertiaProps = array_merge($inertiaProps, app(\App\Services\EnrollmentWebhookAdminService::class)->buildTabPayload($tenantId));
        }

        if ($tab === 'pwa') {
            $inertiaProps = array_merge($inertiaProps, app(\App\Services\MemberAreaPwaAdminService::class)->buildTabPayload($tenantId));
        }

        return Inertia::render('MemberAreaAdmin/Index', $inertiaProps);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $hub = $this->memberHubService->ensureHubForTenant($tenantId);

        $validated = $request->validate([
            'my_courses_title' => ['nullable', 'string', 'max:120'],
            'my_courses_cover_mode' => ['nullable', 'string', 'in:vertical,horizontal'],
            'email_subject' => ['nullable', 'string', 'max:255'],
            'email_body_text' => ['nullable', 'string', 'max:10000'],
        ]);

        $config = $hub->member_area_config ?? [];
        $config['my_courses'] = array_merge($config['my_courses'] ?? [], [
            'enabled' => true,
            'title' => $validated['my_courses_title'] ?? ($config['my_courses']['title'] ?? 'Meus Cursos'),
            'cover_mode' => $validated['my_courses_cover_mode'] ?? ($config['my_courses']['cover_mode'] ?? 'vertical'),
        ]);
        $hub->member_area_config = $config;

        if (array_key_exists('email_subject', $validated) || array_key_exists('email_body_text', $validated)) {
            $checkoutConfig = $hub->checkout_config ?? [];
            $emailTemplate = is_array($checkoutConfig['email_template'] ?? null) ? $checkoutConfig['email_template'] : [];
            if (array_key_exists('email_subject', $validated)) {
                $emailTemplate['subject'] = $validated['email_subject'];
            }
            if (array_key_exists('email_body_text', $validated)) {
                $emailTemplate['body_text'] = $validated['email_body_text'];
            }
            $checkoutConfig['email_template'] = $emailTemplate;
            $hub->checkout_config = $checkoutConfig;
        }

        $hub->save();

        return response()->json([
            'message' => 'Configurações salvas.',
            'member_area' => app(MemberAreaAdminService::class)->buildPageData($tenantId, $request)['member_area'],
        ]);
    }

    public function updatePwa(Request $request): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'short_name' => ['nullable', 'string', 'max:32'],
            'favicon' => ['nullable', 'string', 'max:2048'],
            'theme_color' => ['nullable', 'string', 'max:20'],
            'background_color' => ['nullable', 'string', 'max:20'],
            'push_enabled' => ['nullable', 'boolean'],
        ]);

        $result = app(\App\Services\MemberAreaPwaAdminService::class)->updatePwa($tenantId, $validated);

        $response = [
            'message' => 'Configurações PWA salvas.',
            'pwa_settings' => $result['pwa_settings'],
        ];

        if ($result['warning'] !== null) {
            $response['warning'] = $result['warning'];
        }

        return response()->json($response);
    }

    public function uploadPwaIcon(Request $request): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $maxKb = (int) config('member_builder_uploads.image_max_kb', 10240);

        $request->validate([
            'file' => ['required', 'file', 'image', 'max:'.$maxKb],
        ], [
            'file.required' => 'Nenhum arquivo enviado.',
            'file.image' => 'O arquivo deve ser uma imagem (JPG, PNG, GIF ou WebP).',
            'file.max' => 'A imagem deve ter no máximo '.(int) max(1, floor($maxKb / 1024)).' MB.',
        ]);

        $uploaded = app(\App\Services\MemberAreaPwaAdminService::class)->uploadIcon($tenantId, $request->file('file'));

        return response()->json([
            'message' => 'Ícone atualizado.',
            'url' => $uploaded['url'],
            'pwa_settings' => app(\App\Services\MemberAreaPwaAdminService::class)->buildTabPayload($tenantId)['pwa_settings'],
        ]);
    }

    private function resolveTab(?string $tab): string
    {
        $allowed = ['cursos', 'vitrine', 'alunos', 'webhooks', 'pwa', 'configuracoes'];

        return in_array($tab, $allowed, true) ? $tab : 'cursos';
    }
}
