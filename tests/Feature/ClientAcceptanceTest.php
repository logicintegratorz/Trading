<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientAcceptanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_primary_module_and_create_screen_opens(): void
    {
        foreach (['/', '/masters/group-heads', '/masters/parties', '/inventory', '/inventory/products/create', '/saudas', '/saudas/create', '/purchase', '/sale', '/dispatch', '/purchase-orders', '/purchase-orders/create', '/sale-orders', '/sale-orders/create', '/reports'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_dashboard_shortcuts_open_the_correct_workflows(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('href="/purchase?create=1"', false)
            ->assertSee('href="/sale?create=1"', false)
            ->assertSee('href="'.route('orders.create', 'purchase-orders').'"', false);
    }

    public function test_order_action_buttons_have_expected_enabled_and_placeholder_states(): void
    {
        $groupId = DB::table('group_heads')->insertGetId(['name' => 'Pipes', 'created_at' => now(), 'updated_at' => now()]);
        $partyId = DB::table('parties')->insertGetId(['name' => 'Azad Traders', 'created_at' => now(), 'updated_at' => now()]);
        $orderId = DB::table('records')->insertGetId([
            'reference' => 'PO-TEST-1', 'type' => 'purchase-orders', 'group_head_id' => $groupId, 'party_id' => $partyId,
            'party' => 'Azad Traders', 'product_id' => null, 'quantity' => 1, 'unit_price_cents' => 10000,
            'total_cents' => 10000, 'date' => '2026-10-30', 'status' => 'Open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->get('/purchase-orders/'.$orderId);

        $response->assertOk()->assertSeeInOrder(['Add', 'Edit', 'Previous', 'Next', 'Move', 'Exit', 'Save'])
            ->assertSee('title="No previous voucher"', false)
            ->assertSee('data-open-move', false);
    }

    public function test_master_renames_stay_synchronised_with_products_and_transactions(): void
    {
        $groupId = DB::table('group_heads')->insertGetId(['name' => 'Old Group', 'created_at' => now(), 'updated_at' => now()]);
        $partyId = DB::table('parties')->insertGetId(['name' => 'Old Party', 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId(['sku' => 'SYNC-1', 'group_name' => 'Old Group', 'name' => 'Sync Product', 'unit' => 'Ton', 'stock' => 1, 'reorder_level' => 0, 'cost_cents' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('records')->insert(['reference' => 'SYNC-REC', 'type' => 'purchase-orders', 'group_head_id' => $groupId, 'party_id' => $partyId, 'party' => 'Old Party', 'product_id' => $productId, 'quantity' => 1, 'unit_price_cents' => 100, 'total_cents' => 100, 'date' => '2026-10-30', 'status' => 'Open', 'created_at' => now(), 'updated_at' => now()]);

        $this->put('/masters/group-heads/'.$groupId, ['name' => 'New Group'])->assertRedirect();
        $this->put('/masters/parties/'.$partyId, ['name' => 'New Party'])->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $productId, 'group_name' => 'New Group']);
        $this->assertDatabaseHas('records', ['reference' => 'SYNC-REC', 'party' => 'New Party']);
    }
}
