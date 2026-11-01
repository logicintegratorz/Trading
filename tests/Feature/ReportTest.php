<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_all_report_pages_render_with_filters(): void
    {
        $this->get('/reports')->assertOk()->assertSeeInOrder([
            'PO Report', 'SO Report', 'Size Purchase Report', 'Size Sale Report', 'Inventory Report', 'Stock Alert Report',
        ]);

        foreach (['po', 'so', 'size-purchase', 'size-sale', 'inventory', 'stock-alert'] as $report) {
            $this->get('/reports/'.$report)->assertOk()->assertSee('Search')->assertSee('Group');
        }
    }

    public function test_po_report_uses_supplier_master_and_filters_results(): void
    {
        $groupId = DB::table('group_heads')->insertGetId(['name' => 'Pipes', 'created_at' => now(), 'updated_at' => now()]);
        $supplierId = DB::table('parties')->insertGetId(['name' => 'Azad Supplier', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('records')->insert([
            'reference' => 'PO-100', 'type' => 'purchase-orders', 'group_head_id' => $groupId, 'party_id' => $supplierId,
            'party' => 'Azad Supplier', 'product_id' => null, 'quantity' => 2.500, 'unit_price_cents' => 10000,
            'total_cents' => 25000, 'date' => '2026-10-20', 'status' => 'Open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('records')->insert([
            'reference' => 'SO-100', 'type' => 'sale-orders', 'group_head_id' => $groupId, 'party_id' => $supplierId,
            'party' => 'Other Party', 'product_id' => null, 'quantity' => 1, 'unit_price_cents' => 10000,
            'total_cents' => 10000, 'date' => '2026-10-20', 'status' => 'Open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/reports/po?party_id='.$supplierId.'&group=Pipes')
            ->assertOk()->assertSee('Azad Supplier')->assertSee('PO-100')->assertDontSee('SO-100')->assertSee('Supplier');
    }

    public function test_inventory_and_stock_alert_reports_apply_stock_filters(): void
    {
        DB::table('products')->insert([
            ['sku' => 'OK-1', 'group_name' => 'Pipes', 'name' => 'Healthy Pipe', 'weight' => 1, 'unit' => 'Ton', 'stock' => 5, 'reorder_level' => 2, 'cost_cents' => 10000, 'purchase_rate_cents' => 0, 'sale_rate_cents' => 0, 'opening_rate_cents' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['sku' => 'LOW-1', 'group_name' => 'Pipes', 'name' => 'Low Pipe', 'weight' => 1, 'unit' => 'Ton', 'stock' => 1, 'reorder_level' => 2, 'cost_cents' => 10000, 'purchase_rate_cents' => 0, 'sale_rate_cents' => 0, 'opening_rate_cents' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->get('/reports/inventory?stock_status=healthy')->assertOk()->assertSee('<strong>Healthy Pipe</strong>', false)->assertDontSee('<strong>Low Pipe</strong>', false)->assertSee('₹500.00');
        $this->get('/reports/stock-alert')->assertOk()->assertSee('<strong>Low Pipe</strong>', false)->assertDontSee('<strong>Healthy Pipe</strong>', false)->assertSee('⚠ Low');
    }
}
