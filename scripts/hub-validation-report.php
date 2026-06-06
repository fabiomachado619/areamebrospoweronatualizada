<?php

/**
 * Script de validação manual da Fase 1 HUB — executar via:
 * docker exec getfy-hub-test php scripts/hub-validation-report.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\User;
use App\Services\MemberHubService;
use Illuminate\Support\Facades\DB;

$hub = Product::where('checkout_slug', 'hub-validacao')->first();
if (! $hub) {
    echo "ERRO: Hub hub-validacao não encontrado.\n";
    exit(1);
}

$hubService = app(MemberHubService::class);
$scenarios = [
    'aluno-zero@test.local' => 'Aluno sem cursos',
    'aluno-um@test.local' => 'Aluno com 1 curso',
    'aluno-varios@test.local' => 'Aluno com vários cursos',
];

echo "=== Relatório Fase 1 HUB ===\n";
echo "Hub: {$hub->name} (/m/{$hub->checkout_slug})\n";
echo "is_member_hub: ".($hub->isMemberHub() ? 'sim' : 'não')."\n\n";

foreach ($scenarios as $email => $label) {
    $user = User::where('email', $email)->first();
    if (! $user) {
        echo "[{$label}] USUÁRIO NÃO ENCONTRADO\n\n";
        continue;
    }

    $access = $hub->hasMemberAreaAccess($user);
    $myCourses = $hubService->buildMyCoursesSection($hub, $user);
    $enrolledIds = $user->products()->pluck('products.id')->flip()->all();

    $vitrineModules = $hub->memberSections()
        ->where('section_type', 'products')
        ->with('modules.relatedProduct')
        ->get()
        ->flatMap(fn ($s) => $s->modules)
        ->map(function ($m) use ($hub, $hubService, $enrolledIds) {
            $payload = [
                'related_product_id' => $m->related_product_id,
                'title' => $m->title,
                'related_product' => $m->relatedProduct ? ['type' => $m->relatedProduct->type] : null,
            ];

            return $hubService->filterVitrineModule($hub, $payload, $enrolledIds);
        })
        ->filter()
        ->values();

    echo "--- {$label} ({$email}) ---\n";
    echo "Acesso ao HUB: ".($access ? 'SIM' : 'NÃO')."\n";
    echo "Meus Cursos: ".($myCourses ? count($myCourses['modules']).' curso(s)' : 'seção oculta (vazio)')."\n";
    if ($myCourses) {
        foreach ($myCourses['modules'] as $mod) {
            $url = $mod['related_product']['member_area_url'] ?? 'N/A';
            echo "  - {$mod['title']} → {$url}\n";
        }
    }
    echo "Vitrine (não liberados): {$vitrineModules->count()} item(ns)\n";
    foreach ($vitrineModules as $vm) {
        echo "  - {$vm['title']} (product_id: {$vm['related_product_id']})\n";
    }
    echo "\n";
}

$course = Product::where('checkout_slug', 'curso-alpha')->first();
if ($course) {
    $user = User::where('email', 'aluno-um@test.local')->first();
    echo "--- Curso individual (/m/curso-alpha) ---\n";
    echo "URL: /m/{$course->checkout_slug}\n";
    echo "Aluno-um tem acesso: ".($course->hasMemberAreaAccess($user) ? 'SIM' : 'NÃO')."\n";
    $lessons = $course->memberSections()->with('modules.lessons')->get()->sum(fn ($s) => $s->modules->sum(fn ($m) => $m->lessons->count()));
    echo "Aulas no curso: {$lessons}\n\n";
}

$pivotCount = DB::table('product_user')->count();
echo "--- product_user ---\n";
echo "Total de matrículas (product_user): {$pivotCount}\n";
echo "Fluxo de matrícula: inalterado (pivot existente)\n";
