<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DuplicatePreventionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{groupId:int,partyId:int,productId:int} */
    private function setupData(): array
    {
        $groupId = DB::table('group_heads')->insertGetId(['name' => 'Pipes', 'created_at' => now(), 'updated_at' => now()]);
        $partyId = DB::table('parties')->insertGetId(['name' => 'Azad Traders', 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId(['sku' => 'PIPE-100', 'group_name' => 'Pipes', 'name' => 'MS Pipe', 'unit' => 'Ton', 'stock' => 10, 'reorder_level' => 1, 'cost_cents' => 10000, 'created_at' => now(), 'updated_at' => now()]);

        return compact('groupId', 'partyId', 'productId');
    }

    public function test_duplicate_names_products_saudas_and_orders_are_rejected(): void
    {
        ['groupId' => $groupId, 'partyId' => $partyId, 'productId' => $productId] = $this->setupData();
        $this->post('/masters/parties', ['name' => 'azad traders'])->assertSessionHasErrors('name');
        $productPayload = ['group_name' => 'Pipes', 'product_name' => 'ms pipe', 'weight' => 1, 'purchase_rate' => 100, 'sale_rate' => 120, 'opening_qty' => 0, 'opening_rate' => 100, 'minimum_qty' => 0];
        $this->post('/inventory/products', $productPayload)->assertSessionHasErrors('product_name');
        DB::table('group_heads')->insert(['name' => 'Other Group', 'created_at' => now(), 'updated_at' => now()]);
        $productPayload['group_name'] = 'Other Group';
        $productPayload['product_name'] = 'MS PIPE';
        $this->post('/inventory/products', $productPayload)->assertSessionHasErrors('product_name');

        $saudaPayload = ['type' => 'purchase', 'party_id' => $partyId, 'product_id' => $productId, 'quantity' => '2.000', 'rate' => '100.00', 'date' => '2026-11-01'];
        $this->post('/saudas', $saudaPayload)->assertRedirect();
        $this->post('/saudas', $saudaPayload)->assertSessionHasErrors('quantity');

        $orderPayload = ['group_head_id' => $groupId, 'party_id' => $partyId, 'quantity' => '2.000', 'rate' => '100.00', 'date' => '2026-11-01'];
        $this->post('/purchase-orders/orders', $orderPayload)->assertRedirect();
        $this->post('/purchase-orders/orders', $orderPayload)->assertSessionHasErrors('items');
    }

    public function test_exact_duplicate_stock_entry_for_one_sauda_and_date_is_rejected(): void
    {
        ['partyId' => $partyId, 'productId' => $productId] = $this->setupData();
        $saudaId = DB::table('saudas')->insertGetId(['reference' => 'SDA-PUR-1', 'type' => 'purchase', 'party_id' => $partyId, 'product_id' => $productId, 'quantity' => 4, 'fulfilled_quantity' => 0, 'rate_cents' => 10000, 'date' => '2026-11-01', 'status' => 'Open', 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['sauda_id' => $saudaId, 'quantity' => '2.000', 'date' => '2026-11-01'];
        $this->post('/purchase', $payload)->assertRedirect();
        $this->post('/purchase', $payload)->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('records', 1);
    }
}
