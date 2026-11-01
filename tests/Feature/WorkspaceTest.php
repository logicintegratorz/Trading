<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(): int
    {
        return DB::table('products')->insertGetId(['sku' => 'TEST-1', 'name' => 'Test item', 'unit' => 'pcs', 'stock' => 5, 'reorder_level' => 2, 'cost_cents' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function entry(string $type, int $id, int $qty = 2, string $rate = '12.50'): array
    {
        $partyId = DB::table('parties')->insertGetId(['name' => 'Test customer '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $saudaId = DB::table('saudas')->insertGetId([
            'reference' => 'SDA-'.uniqid(), 'type' => $type, 'party_id' => $partyId, 'product_id' => $id,
            'quantity' => $qty, 'fulfilled_quantity' => 0, 'rate_cents' => (int) ((float) $rate * 100),
            'date' => today()->toDateString(), 'status' => 'Open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['sauda_id' => $saudaId, 'quantity' => $qty, 'date' => today()->toDateString()];
    }

    private function productPayload(array $overrides = []): array
    {
        DB::table('group_heads')->insertOrIgnore(['name' => $overrides['group_name'] ?? 'Fasteners', 'created_at' => now(), 'updated_at' => now()]);

        return array_merge([
            'group_name' => 'Fasteners',
            'product_name' => 'Stainless Steel Bolt',
            'weight' => '0.250',
            'purchase_rate' => '12.50',
            'sale_rate' => '16.75',
            'opening_qty' => '5.250',
            'opening_rate' => '11.25',
            'minimum_qty' => '2.500',
        ], $overrides);
    }

    public function test_empty_dashboard_and_modules_render(): void
    {
        foreach (['/', '/purchase', '/sale', '/inventory', '/purchase-orders', '/sale-orders'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_sale_changes_stock_and_stores_exact_cents(): void
    {
        $id = $this->product();
        $this->post('/sale', $this->entry('sale', $id))->assertRedirect('/sale');
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 3]);
        $this->assertDatabaseHas('records', ['type' => 'sale', 'total_cents' => 2500]);
    }

    public function test_overselling_rolls_back(): void
    {
        $id = $this->product();
        $this->post('/sale', $this->entry('sale', $id, 6))->assertSessionHasErrors('quantity');
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 5]);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_orders_do_not_change_stock(): void
    {
        $id = $this->product();
        $groupHeadId = DB::table('group_heads')->insertGetId(['name' => 'Metals', 'created_at' => now(), 'updated_at' => now()]);
        $partyId = DB::table('parties')->insertGetId(['name' => 'Test customer', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['/purchase-orders', '/sale-orders'] as $path) {
            $this->post($path.'/orders', ['group_head_id' => $groupHeadId, 'party_id' => $partyId, 'quantity' => 2, 'rate' => '12.50', 'date' => today()->toDateString()])->assertRedirect();
        } $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 5]);
        $this->assertDatabaseCount('records', 2);
    }

    public function test_purchase_updates_weighted_cost(): void
    {
        $id = $this->product();
        $this->post('/purchase', $this->entry('purchase', $id, 5))->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 10, 'cost_cents' => 1125]);
    }

    public function test_invalid_input_does_not_create_records(): void
    {
        $id = $this->product();
        $data = $this->entry('sale', $id);
        $data['quantity'] = 0;
        $this->post('/sale', $data)->assertSessionHasErrors(['quantity']);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_search_is_scoped_to_module(): void
    {
        $id = $this->product();
        $this->post('/purchase-orders', $this->entry('purchase', $id));
        $this->get('/sale?q=Test')->assertDontSee('Test customer');
    }

    public function test_add_product_page_renders_all_requested_fields(): void
    {
        $response = $this->get('/inventory/products/create');

        $response->assertOk()->assertSeeInOrder([
            'Group Name',
            'Product Name',
            'Weight',
            'Purchase Rate',
            'Sale Rate',
            'Opening Qty',
            'Opening Rate',
            'Minimum Qty',
        ]);
    }

    public function test_valid_product_is_stored_with_exact_rates_and_opening_stock(): void
    {
        $response = $this->post('/inventory/products', $this->productPayload());

        $response->assertRedirect('/inventory');
        $this->assertDatabaseHas('products', [
            'group_name' => 'Fasteners',
            'name' => 'Stainless Steel Bolt',
            'weight' => 0.250,
            'stock' => 5.250,
            'reorder_level' => 2.500,
            'cost_cents' => 1125,
            'purchase_rate_cents' => 1250,
            'sale_rate_cents' => 1675,
            'opening_rate_cents' => 1125,
            'unit' => 'Ton',
        ]);
    }

    public function test_product_requires_every_requested_field(): void
    {
        $response = $this->post('/inventory/products', []);

        $response->assertSessionHasErrors([
            'group_name',
            'product_name',
            'weight',
            'purchase_rate',
            'sale_rate',
            'opening_qty',
            'opening_rate',
            'minimum_qty',
        ]);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_inventory_indicates_stock_above_and_at_minimum_with_reference_colours(): void
    {
        $this->post('/inventory/products', $this->productPayload([
            'opening_qty' => '5.000',
            'minimum_qty' => '0.520',
        ]));
        $this->post('/inventory/products', $this->productPayload([
            'group_name' => 'Wire',
            'product_name' => 'Copper Wire',
            'opening_qty' => '0.000',
            'minimum_qty' => '0.520',
        ]));

        $response = $this->get('/inventory');

        $response->assertOk()
            ->assertSee('stock-ok', false)
            ->assertSee('✓ 5 Ton')
            ->assertSee('stock-low', false)
            ->assertSee('⚠ 0 Ton')
            ->assertSee('Min: 0.52');
    }

    public function test_inventory_filters_by_group_and_stock_status(): void
    {
        $this->post('/inventory/products', $this->productPayload());
        $this->post('/inventory/products', $this->productPayload([
            'group_name' => 'Wire',
            'product_name' => 'Copper Wire',
            'opening_qty' => '1.000',
            'minimum_qty' => '2.000',
        ]));

        $response = $this->get('/inventory?group=Wire&stock_status=low');

        $response->assertOk()
            ->assertSee('Copper Wire')
            ->assertDontSee('Stainless Steel Bolt');
    }
}
