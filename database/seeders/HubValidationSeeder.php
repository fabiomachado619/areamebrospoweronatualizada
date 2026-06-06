<?php

namespace Database\Seeders;

use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberHubService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class HubValidationSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 1;
        $password = 'hub-test-123';

        $infoprodutor = User::query()->firstOrCreate(
            ['email' => 'hub-admin@test.local'],
            [
                'name' => 'Admin HUB Teste',
                'password' => Hash::make($password),
                'role' => User::ROLE_INFOPRODUTOR,
                'tenant_id' => $tenantId,
            ]
        );

        $hub = Product::query()->updateOrCreate(
            ['checkout_slug' => 'hub-validacao'],
            [
                'tenant_id' => $tenantId,
                'name' => 'HUB Validação',
                'slug' => 'hub-validacao',
                'type' => Product::TYPE_AREA_MEMBROS,
                'billing_type' => Product::BILLING_ONE_TIME,
                'price' => 0,
                'currency' => 'BRL',
                'is_active' => true,
                'is_member_hub' => false,
            ]
        );

        app(MemberHubService::class)->designateHub($hub->fresh());

        $courses = [];
        foreach ([
            ['slug' => 'curso-alpha', 'name' => 'Curso Alpha'],
            ['slug' => 'curso-beta', 'name' => 'Curso Beta'],
            ['slug' => 'curso-gamma', 'name' => 'Curso Gamma'],
        ] as $i => $meta) {
            $course = Product::query()->updateOrCreate(
                ['checkout_slug' => $meta['slug']],
                [
                    'tenant_id' => $tenantId,
                    'name' => $meta['name'],
                    'slug' => $meta['slug'],
                    'type' => Product::TYPE_AREA_MEMBROS,
                    'billing_type' => Product::BILLING_ONE_TIME,
                    'price' => 97,
                    'currency' => 'BRL',
                    'is_active' => true,
                    'member_hub_product_id' => $hub->id,
                ]
            );

            $section = MemberSection::query()->firstOrCreate(
                ['product_id' => $course->id, 'title' => 'Conteúdo'],
                ['position' => 1, 'section_type' => 'courses']
            );

            $module = MemberModule::query()->firstOrCreate(
                ['member_section_id' => $section->id, 'title' => 'Módulo 1'],
                ['product_id' => $course->id, 'position' => 1]
            );

            MemberLesson::query()->firstOrCreate(
                ['member_module_id' => $module->id, 'title' => 'Aula intro'],
                [
                    'product_id' => $course->id,
                    'position' => 1,
                    'type' => MemberLesson::TYPE_TEXT,
                    'content_text' => '<p>Aula de validação do HUB.</p>',
                ]
            );

            $courses[] = $course;
        }

        $vitrineSection = MemberSection::query()->firstOrCreate(
            ['product_id' => $hub->id, 'title' => 'Vitrine'],
            ['position' => 1, 'section_type' => 'products']
        );

        foreach ($courses as $pos => $course) {
            MemberModule::query()->firstOrCreate(
                [
                    'member_section_id' => $vitrineSection->id,
                    'related_product_id' => $course->id,
                ],
                [
                    'product_id' => $hub->id,
                    'title' => $course->name,
                    'position' => $pos + 1,
                    'access_type' => 'paid',
                ]
            );
        }

        $alunoZero = User::query()->firstOrCreate(
            ['email' => 'aluno-zero@test.local'],
            [
                'name' => 'Aluno Zero Cursos',
                'password' => Hash::make($password),
                'role' => User::ROLE_ALUNO,
                'tenant_id' => $tenantId,
            ]
        );

        $alunoUm = User::query()->firstOrCreate(
            ['email' => 'aluno-um@test.local'],
            [
                'name' => 'Aluno Um Curso',
                'password' => Hash::make($password),
                'role' => User::ROLE_ALUNO,
                'tenant_id' => $tenantId,
            ]
        );
        $courses[0]->users()->syncWithoutDetaching([$alunoUm->id]);

        $alunoVarios = User::query()->firstOrCreate(
            ['email' => 'aluno-varios@test.local'],
            [
                'name' => 'Aluno Vários Cursos',
                'password' => Hash::make($password),
                'role' => User::ROLE_ALUNO,
                'tenant_id' => $tenantId,
            ]
        );
        $courses[0]->users()->syncWithoutDetaching([$alunoVarios->id]);
        $courses[1]->users()->syncWithoutDetaching([$alunoVarios->id]);

        $this->command?->info('HUB Validação pronto.');
        $this->command?->info('Hub URL: /m/hub-validacao');
        $this->command?->info('Senha alunos/admin: '.$password);
    }
}
