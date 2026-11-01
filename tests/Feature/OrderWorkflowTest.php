<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{groupHeadId: int, partyId: int} */
    private function masters(): array
    {
        $groupHeadId = DB::table('group_heads')->insertGetId(['name' => 'Pipes', 'created_at' => now(), 'updated_at' => now()]);
        $partyId = DB::table('parties')->insertGetId(['name' => 'Azad Traders', 'created_at' => now(), 'updated_at' => now()]);

        return compact('groupHeadId', 'partyId');
    }

    public function test_admin_can_add_edit_and_delete_unused_master_values(): void
    {
        $this->post('/masters/group-heads', ['name' => 'Pipes'])->assertRedirect();
        $id = (int) DB::table('group_heads')->value('id');
        $this->put('/masters/group-heads/'.$id, ['name' => 'Steel Pipes'])->assertRedirect();
        $this->assertDatabaseHas('group_heads', ['id' => $id, 'name' => 'Steel Pipes']);

        $this->delete('/masters/group-heads/'.$id)->assertRedirect();
        $this->assertDatabaseMissing('group_heads', ['id' => $id]);
    }

    public function test_order_create_page_uses_master_dropdowns_and_requested_actions(): void
    {
        $this->masters();

        $response = $this->get('/purchase-orders/create');

        $response->assertOk()->assertSeeInOrder(['Group Head', 'Pipes', 'Supplier Name', 'Azad Traders', 'QTY', 'Rate'])
            ->assertSeeInOrder(['Add', 'Edit', 'Previous', 'Next', 'Move', 'Exit', 'Save']);

        $this->get('/sale-orders/create')->assertOk()->assertSeeInOrder(['Group Head', 'Party Name', 'Azad Traders']);
    }

    public function test_purchase_order_can_be_created_edited_and_moved_without_changing_stock(): void
    {
        ['groupHeadId' => $groupHeadId, 'partyId' => $partyId] = $this->masters();
        $productId = DB::table('products')->insertGetId(['sku' => 'PIPE-1', 'name' => 'Pipe', 'unit' => 'Ton', 'stock' => 5, 'reorder_level' => 1, 'cost_cents' => 10000, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->post('/purchase-orders/orders', ['group_head_id' => $groupHeadId, 'party_id' => $partyId, 'quantity' => '2.500', 'rate' => '100.00', 'date' => '2026-10-20']);
        $id = (int) DB::table('records')->value('id');
        $response->assertRedirect(route('orders.show', ['purchase-orders', $id]));
        $this->assertDatabaseHas('records', ['id' => $id, 'type' => 'purchase-orders', 'quantity' => 2.500, 'unit_price_cents' => 10000, 'total_cents' => 25000]);
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock' => 5]);

        $this->put('/purchase-orders/'.$id, ['group_head_id' => $groupHeadId, 'party_id' => $partyId, 'quantity' => '3.000', 'rate' => '110.00', 'date' => '2026-10-21'])->assertRedirect();
        $this->patch('/purchase-orders/'.$id.'/move', ['date' => '2026-10-25'])->assertRedirect();
        $this->assertDatabaseHas('records', ['id' => $id, 'quantity' => 3.000, 'unit_price_cents' => 11000, 'total_cents' => 33000, 'date' => '2026-10-25']);
    }

    public function test_order_rejects_values_not_created_in_masters(): void
    {
        $response = $this->post('/sale-orders/orders', ['group_head_id' => 999, 'party_id' => 999, 'quantity' => 1, 'rate' => 10, 'date' => '2026-10-20']);

        $response->assertSessionHasErrors(['group_head_id', 'party_id']);
        $this->assertDatabaseCount('records', 0);
    }

    public function test_multi_item_order_calculates_receipt_and_prints_both_formats(): void
    {
        ['groupHeadId' => $groupHeadId, 'partyId' => $partyId] = $this->masters();

        $response = $this->post('/sale-orders/orders', [
            'group_head_id' => $groupHeadId, 'party_id' => $partyId, 'date' => '2026-11-01',
            'goods_number' => 'GOODS-101', 'gst_percent' => '18', 'labour' => '10', 'discount' => '5',
            'commission' => '0', 'freight' => '0', 'other_charges' => '0',
            'items' => [
                ['description' => '1X1X5KG', 'weight_kg' => '5', 'difference' => '6200', 'base_rate' => '46600', 'quantity' => '2.000', 'pieces' => 20, 'bundles' => 1, 'rate' => '100'],
                ['description' => '2X2X15KG', 'weight_kg' => '15', 'difference' => '4500', 'base_rate' => '46600', 'quantity' => '1.500', 'pieces' => 10, 'bundles' => 1, 'rate' => '200'],
            ],
        ]);

        $id = (int) DB::table('records')->value('id');
        $response->assertRedirect(route('orders.show', ['sale-orders', $id]));
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseHas('records', ['id' => $id, 'quantity' => 3.500, 'total_cents' => 59590, 'goods_number' => 'GOODS-101']);

        $this->get('/sale-orders/'.$id)->assertOk()->assertSee('Print Final Receipt')->assertSee('1X1X5KG');
        $this->get('/sale-orders/'.$id.'/receipt')->assertOk()->assertSeeInOrder(['JSSC AZAD', 'Azad Traders', '1X1X5KG', 'G Total']);
        $this->get('/sale-orders/'.$id.'/parchi')->assertOk()->assertSeeInOrder(['ORDER PARCHI', 'Goods No.', '1X1X5KG', 'Bundle']);
    }

    public function test_dispatch_material_print_format_opens(): void
    {
        $this->get('/dispatch/print')->assertOk()->assertSee('Material Dispatch / Pending Orders Report')->assertSee('Ord. Qty')->assertSee('Disp. Qty');
    }

    public function test_saved_orders_have_working_previous_and_next_voucher_navigation(): void
    {
        ['groupHeadId' => $groupHeadId, 'partyId' => $partyId] = $this->masters();
        foreach (['PO-FIRST', 'PO-SECOND', 'PO-THIRD'] as $reference) {
            DB::table('records')->insert([
                'reference' => $reference, 'type' => 'purchase-orders', 'group_head_id' => $groupHeadId, 'party_id' => $partyId,
                'party' => 'Azad Traders', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_cents' => 10000,
                'date' => '2026-11-01', 'status' => 'Open', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $middleId = (int) DB::table('records')->where('reference', 'PO-SECOND')->value('id');

        $this->get('/purchase-orders/'.$middleId)->assertOk()
            ->assertSee(route('orders.show', ['purchase-orders', $middleId - 1]), false)
            ->assertSee(route('orders.show', ['purchase-orders', $middleId + 1]), false);
    }
}
