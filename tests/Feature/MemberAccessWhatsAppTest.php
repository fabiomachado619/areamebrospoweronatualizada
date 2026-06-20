<?php

namespace Tests\Feature;

use App\Events\MemberAccessGranted;
use App\Models\Plugin;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Plugins\AutoZap\AutoZapEventSubscriber;
use Plugins\AutoZap\Jobs\AutoZapRunFlowJob;
use Plugins\AutoZap\Models\AutoZapFlow;
use Plugins\AutoZap\Services\AutoZapPayload;
use Tests\TestCase;

class MemberAccessWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_dir(base_path('plugins/autozap'))) {
            $this->markTestSkipped('Plugin autozap ausente.');
        }
        require_once base_path('plugins/autozap/src/AutoZapEventSubscriber.php');
        require_once base_path('plugins/autozap/src/Jobs/AutoZapRunFlowJob.php');
        require_once base_path('plugins/autozap/src/Models/AutoZapFlow.php');
        require_once base_path('plugins/autozap/src/Services/AutoZapPayload.php');
    }

    public function test_send_for_user_product_dispatches_member_access_granted(): void
    {
        Event::fake([MemberAccessGranted::class]);
        Mail::fake();

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_config' => [
                'email_template' => Product::defaultEmailTemplate(),
            ],
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => $product->tenant_id,
            'phone' => '+5565998887777',
        ]);
        $user->products()->attach($product->id);

        app(AccessEmailService::class)->sendForUserProduct($user, $product, 'senha123');

        Event::assertDispatched(MemberAccessGranted::class, function (MemberAccessGranted $event) use ($user, $product) {
            return $event->user->id === $user->id
                && (string) $event->product->id === (string) $product->id
                && ($event->access['password'] ?? '') === 'senha123';
        });
    }

    public function test_autozap_subscriber_queues_job_on_member_access_granted(): void
    {
        Queue::fake();

        Plugin::updateOrCreate(
            ['slug' => 'autozap'],
            ['name' => 'AutoZap', 'version' => '1.0.0', 'is_enabled' => true]
        );

        $product = $this->createTestProduct(['type' => Product::TYPE_AREA_MEMBROS]);
        $user = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => $product->tenant_id,
            'phone' => '65998887777',
        ]);

        AutoZapFlow::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => (string) $product->id,
            'trigger_event' => MemberAccessGranted::class,
            'name' => 'Acesso matrícula',
            'is_active' => true,
            'graph_json' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger', 'data' => []],
                    ['id' => 'end', 'type' => 'end', 'data' => []],
                ],
                'edges' => [['from' => 'trigger', 'to' => 'end']],
            ],
        ]);

        (new AutoZapEventSubscriber)->handleEvent(new MemberAccessGranted($user, $product, [
            'link' => 'https://area.test/login',
            'email' => $user->email,
            'password' => 'abc',
        ]));

        Queue::assertPushed(AutoZapRunFlowJob::class);
    }

    public function test_payload_includes_phone_from_user_for_member_access(): void
    {
        $product = $this->createTestProduct(['type' => Product::TYPE_AREA_MEMBROS]);
        $user = User::factory()->create([
            'tenant_id' => $product->tenant_id,
            'phone' => '(65) 99288-7777',
        ]);

        $payload = AutoZapPayload::fromEvent(new MemberAccessGranted($user, $product, [
            'link' => 'https://area.test',
            'email' => $user->email,
            'password' => '',
        ]));

        $this->assertSame('65992887777', AutoZapPayload::resolvePhone($payload));
        $this->assertSame('https://area.test', $payload['access']['link'] ?? null);
    }
}
