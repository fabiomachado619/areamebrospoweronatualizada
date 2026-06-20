<?php

namespace Tests\Feature;

use App\Events\AccessDeliveryReady;
use App\Models\Order;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Plugins\AutoZap\AutoZapEventSubscriber;
use Plugins\AutoZap\Jobs\AutoZapRunFlowJob;
use Plugins\AutoZap\Models\AutoZapConnection;
use Plugins\AutoZap\Models\AutoZapFlow;
use Plugins\AutoZap\Services\AutoZapPayload;
use Plugins\AutoZap\Services\AutoZapTemplate;
use Tests\TestCase;

class AutoZapBundledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessAutoZapPresent();
        $this->loadAutoZapClasses();
    }

    private function skipUnlessAutoZapPresent(): void
    {
        if (! is_dir(base_path('plugins/autozap'))) {
            $this->markTestSkipped('Plugin autozap ausente.');
        }
    }

    private function loadAutoZapClasses(): void
    {
        require_once base_path('plugins/autozap/src/AutoZapEventSubscriber.php');
        require_once base_path('plugins/autozap/src/AutoZapEventUtils.php');
        require_once base_path('plugins/autozap/src/Jobs/AutoZapRunFlowJob.php');
        require_once base_path('plugins/autozap/src/Models/AutoZapConnection.php');
        require_once base_path('plugins/autozap/src/Models/AutoZapFlow.php');
        require_once base_path('plugins/autozap/src/Models/AutoZapFlowRun.php');
        require_once base_path('plugins/autozap/src/Services/AutoZapPayload.php');
        require_once base_path('plugins/autozap/src/Services/AutoZapTemplate.php');
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalGraph(string $eventClass): array
    {
        return [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'data' => ['event_class' => $eventClass]],
                ['id' => 'end', 'type' => 'end', 'data' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'end'],
            ],
        ];
    }

    private function adminUser(int $tenantId = 1): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_autozap_is_discovered_as_core_bundled_plugin(): void
    {
        $this->assertTrue(PluginRegistry::isCoreBundled('autozap'));

        $installed = collect(PluginRegistry::installed())->firstWhere('slug', 'autozap');
        $this->assertNotNull($installed);
        $this->assertSame('AutoZap', $installed['name']);
        $this->assertSame('integration', $installed['type']);
    }

    public function test_core_bundled_autozap_auto_registers_on_boot(): void
    {
        PluginRegistry::ensureCoreBundledRegistered();

        $record = Plugin::find('autozap');
        $this->assertNotNull($record);
        $this->assertTrue($record->is_enabled);
    }

    public function test_core_bundled_autozap_runs_pending_migrations_on_register(): void
    {
        Schema::dropIfExists('autozap_flow_runs');
        Schema::dropIfExists('autozap_flows');
        Schema::dropIfExists('autozap_connections');
        Plugin::where('slug', 'autozap')->delete();
        DB::table('migrations')->where('migration', 'like', '%autozap%')->delete();

        PluginRegistry::ensureCoreBundledRegistered();

        $this->assertNotNull(Plugin::find('autozap'));
        $this->assertTrue(Schema::hasTable('autozap_connections'));
        $this->assertTrue(Schema::hasTable('autozap_flows'));
        $this->assertTrue(Schema::hasTable('autozap_flow_runs'));
    }

    public function test_autozap_migrations_created_expected_tables(): void
    {
        $this->assertTrue(Schema::hasTable('autozap_connections'));
        $this->assertTrue(Schema::hasTable('autozap_flows'));
        $this->assertTrue(Schema::hasTable('autozap_flow_runs'));

        $this->assertContains('tenant_id', Schema::getColumnListing('autozap_connections'));
        $this->assertContains('graph_json', Schema::getColumnListing('autozap_flows'));
    }

    public function test_autozap_integration_and_product_panel_slots_when_enabled(): void
    {
        Plugin::updateOrCreate(
            ['slug' => 'autozap'],
            ['name' => 'AutoZap', 'version' => '1.0.0', 'is_enabled' => true]
        );

        $apps = PluginRegistry::getIntegrationApps();
        $autozapApp = collect($apps)->firstWhere('id', 'autozap');
        $this->assertNotNull($autozapApp);
        $this->assertSame('Plugin/AutoZap/IntegrationsSidebar', $autozapApp['component']);

        $panels = PluginRegistry::getProductPanels();
        $autozapPanel = collect($panels)->firstWhere('id', 'autozap');
        $this->assertNotNull($autozapPanel);
        $this->assertSame('Plugin/AutoZap/ProductPanel', $autozapPanel['component']);
    }

    public function test_autozap_routes_require_auth(): void
    {
        $this->get('/autozap/connection')->assertRedirect();

        $user = $this->adminUser();
        $this->actingAs($user)->get('/autozap/connection')->assertOk();
    }

    public function test_connection_api_persists_and_masks_credentials(): void
    {
        $user = $this->adminUser();

        $save = $this->actingAs($user)->postJson('/autozap/connection', [
            'provider' => 'zapi',
            'is_active' => true,
            'credentials' => [
                'base_url' => 'https://api.z-api.io',
                'instance_id' => 'inst-123',
                'token' => 'secret-token-xyz',
                'client_token' => 'client-secret-abc',
            ],
        ]);

        $save->assertOk()->assertJson(['ok' => true]);

        $get = $this->actingAs($user)->getJson('/autozap/connection');
        $get->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('provider', 'zapi')
            ->assertJsonPath('safe_credentials.has_token', true)
            ->assertJsonPath('safe_credentials.instance_id', 'inst-123');

        $this->assertDatabaseHas('autozap_connections', [
            'tenant_id' => $user->tenant_id,
            'provider' => 'zapi',
            'is_active' => 1,
        ]);

        $conn = AutoZapConnection::forTenant($user->tenant_id)->first();
        $this->assertNotNull($conn);
        $this->assertSame('secret-token-xyz', $conn->credentialsForProvider('zapi')['token'] ?? null);
    }

    public function test_flows_api_crud_scoped_by_tenant_and_product(): void
    {
        $user = $this->adminUser();
        $product = $this->createTestProduct(['tenant_id' => $user->tenant_id]);
        $eventClass = AccessDeliveryReady::class;

        $create = $this->actingAs($user)->postJson('/autozap/flows', [
            'name' => 'Envio de acesso',
            'product_id' => (string) $product->id,
            'trigger_event' => $eventClass,
            'is_active' => true,
            'graph_json' => $this->minimalGraph($eventClass),
        ]);

        $create->assertCreated()->assertJson(['ok' => true]);
        $flowId = (int) $create->json('id');
        $this->assertGreaterThan(0, $flowId);

        $list = $this->actingAs($user)->getJson('/autozap/flows?product_id='.urlencode((string) $product->id));
        $list->assertOk();
        $this->assertCount(1, $list->json('flows'));
        $this->assertSame($eventClass, $list->json('flows.0.trigger_event'));

        $update = $this->actingAs($user)->putJson("/autozap/flows/{$flowId}", [
            'is_active' => false,
        ]);
        $update->assertOk();

        $this->assertFalse((bool) AutoZapFlow::find($flowId)?->is_active);

        $destroy = $this->actingAs($user)->deleteJson("/autozap/flows/{$flowId}");
        $destroy->assertOk();
        $this->assertDatabaseMissing('autozap_flows', ['id' => $flowId]);
    }

    public function test_flow_graph_validation_requires_trigger_node(): void
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user)->postJson('/autozap/flows', [
            'name' => 'Inválido',
            'trigger_event' => AccessDeliveryReady::class,
            'graph_json' => [
                'nodes' => [['id' => 'end', 'type' => 'end', 'data' => []]],
                'edges' => [],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_event_subscriber_queues_job_for_active_matching_flow(): void
    {
        Queue::fake();

        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99,
            'currency' => 'BRL',
            'email' => 'buyer@example.com',
            'phone' => '+5565999999999',
            'gateway' => 'pix',
        ]);

        AutoZapFlow::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => (string) $product->id,
            'trigger_event' => AccessDeliveryReady::class,
            'name' => 'Acesso WhatsApp',
            'is_active' => true,
            'graph_json' => $this->minimalGraph(AccessDeliveryReady::class),
        ]);

        (new AutoZapEventSubscriber)->handleEvent(new AccessDeliveryReady($order, [
            'link' => 'https://area.test/acesso',
            'email' => 'buyer@example.com',
            'password' => 'senha123',
        ]));

        Queue::assertPushed(AutoZapRunFlowJob::class, function (AutoZapRunFlowJob $job) use ($product) {
            return $job->tenantId === (int) $product->tenant_id
                && $job->eventClass === AccessDeliveryReady::class;
        });
    }

    public function test_payload_and_template_render_access_fields(): void
    {
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'aluno@test.com',
            'phone' => '(65) 99299-9999',
            'gateway' => 'pix',
        ]);

        $payload = AutoZapPayload::fromEvent(new AccessDeliveryReady($order, [
            'link' => 'https://area.test/login',
            'email' => 'aluno@test.com',
            'password' => 'abc123',
        ]));

        $this->assertSame('https://area.test/login', $payload['access']['link'] ?? null);
        $this->assertSame('65992999999', AutoZapPayload::resolvePhone($payload));

        $text = AutoZapTemplate::render(
            'Olá {{customer.name}}! Acesso: {{access.link}} / {{access.password}}',
            $payload
        );
        $this->assertStringContainsString('https://area.test/login', $text);
        $this->assertStringContainsString('abc123', $text);
    }

    public function test_core_bundled_autozap_cannot_be_disabled_or_uninstalled(): void
    {
        $this->assertFalse(PluginRegistry::disable('autozap'));
        $this->assertFalse(PluginRegistry::uninstall('autozap', base_path('plugins/autozap')));
    }

    public function test_disable_and_uninstall_routes_reject_autozap(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post(route('integrations.plugins.disable', ['slug' => 'autozap']))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->delete(route('integrations.plugins.uninstall', ['slug' => 'autozap']))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_plugin_asset_route_serves_autozap_icon(): void
    {
        $response = $this->get('/plugins/autozap/assets/icone.png');
        $response->assertOk();
    }

    public function test_validate_command_for_autozap(): void
    {
        $this->artisan('plugin:validate', ['slug' => 'autozap'])->assertExitCode(0);
    }
}
