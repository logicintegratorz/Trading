<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TradingWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{productId:int,partyId:int,groupId:int} */
    private function masters(float $stock = 10, int $costCents = 10000): array
    {
        $groupId = DB::table('group_heads')->insertGetId(['name' => 'Pipes', 'created_at' => now(), 'updated_at' => now()]);
        $partyId = DB::table('parties')->insertGetId(['name' => 'Azad Traders', 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId([
            'sku' => 'PIPE-100', 'group_name' => 'Pipes', 'name' => 'MS Pipe', 'weight' => 1.250, 'unit' => 'Ton',
            'stock' => $stock, 'reorder_level' => 2, 'cost_cents' => $costCents, 'purchase_rate_cents' => 11000,
            'sale_rate_cents' => 13000, 'opening_rate_cents' => $costCents, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('productId', 'partyId', 'groupId');
    }

    private function createSauda(string $type, int $partyId, int $productId, float $quantity, int $rateCents): int
    {
        return DB::table('saudas')->insertGetId([
            'reference' => 'SDA-'.strtoupper($type).'-100', 'type' => $type, 'party_id' => $partyId, 'product_id' => $productId,
            'quantity' => $quantity, 'fulfilled_quantity' => 0, 'rate_cents' => $rateCents, 'date' => '2026-10-20',
            'status' => 'Open', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_unused_product_can_be_edited_and_deleted(): void
    {
        ['productId' => $productId] = $this->masters();
        $payload = ['group_name' => 'Pipes', 'product_name' => 'Updated Pipe', 'weight' => '1.500', 'purchase_rate' => '120.00', 'sale_rate' => '140.00', 'opening_qty' => '12.500', 'opening_rate' => '105.00', 'minimum_qty' => '3.000'];

        $this->get('/inventory/products/'.$productId.'/edit')->assertOk()->assertSee('Edit Product');
        $this->put('/inventory/products/'.$productId, $payload)->assertRedirect('/inventory');
        $this->assertDatabaseHas('products', ['id' => $productId, 'name' => 'Updated Pipe', 'stock' => 12.500, 'unit' => 'Ton', 'cost_cents' => 10500]);
        $this->delete('/inventory/products/'.$productId)->assertRedirect('/inventory');
        $this->assertDatabaseMissing('products', ['id' => $productId]);
    }

    public function test_product_used_by_sauda_cannot_be_deleted(): void
    {
        ['productId' => $productId, 'partyId' => $partyId] = $this->masters();
        $this->createSauda('purchase', $partyId, $productId, 2, 10000);

        $this->delete('/inventory/products/'.$productId)->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $productId]);
    }

    public function test_purchase_receipt_adds_stock_updates_weighted_cost_and_completes_sauda(): void
    {
        ['productId' => $productId, 'partyId' => $partyId] = $this->masters(10, 10000);
        $saudaId = $this->createSauda('purchase', $partyId, $productId, 10, 12000);

        $this->post('/purchase', ['sauda_id' => $saudaId, 'quantity' => '4.000', 'date' => '2026-10-21'])->assertRedirect('/purchase');
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock' => 14.000, 'cost_cents' => 10571]);
        $this->assertDatabaseHas('saudas', ['id' => $saudaId, 'fulfilled_quantity' => 4.000, 'status' => 'Partial']);

        $this->post('/purchase', ['sauda_id' => $saudaId, 'quantity' => '6.000', 'date' => '2026-10-22'])->assertRedirect('/purchase');
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock' => 20.000, 'cost_cents' => 11000]);
        $this->assertDatabaseHas('saudas', ['id' => $saudaId, 'fulfilled_quantity' => 10.000, 'status' => 'Completed']);
        $this->assertDatabaseCount('records', 2);
    }

    public function test_sale_creates_dispatch_and_reduces_stock(): void
    {
        ['productId' => $productId, 'partyId' => $partyId] = $this->masters(10);
        $saudaId = $this->createSauda('sale', $partyId, $productId, 3, 13000);

        $this->post('/sale', ['sauda_id' => $saudaId, 'quantity' => '3.000', 'date' => '2026-10-21'])->assertRedirect('/sale');

        $this->assertDatabaseHas('products', ['id' => $productId, 'stock' => 7.000]);
        $this->assertDatabaseHas('records', ['sauda_id' => $saudaId, 'type' => 'sale', 'quantity' => 3.000, 'unit_price_cents' => 13000, 'total_cents' => 39000, 'status' => 'Dispatched']);
        $this->get('/dispatch')->assertOk()->assertSee('MS Pipe')->assertSee('SDA-SALE-100')->assertSee('₹390.00');
    }

    public function test_oversale_and_over_fulfilment_roll_back_inventory_and_sauda(): void
    {
        ['productId' => $productId, 'partyId' => $partyId] = $this->masters(2);
        $saleSaudaId = $this->createSauda('sale', $partyId, $productId, 5, 13000);

        $this->post('/sale', ['sauda_id' => $saleSaudaId, 'quantity' => '3.000', 'date' => '2026-10-21'])->assertSessionHasErrors('quantity');
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock' => 2.000]);
        $this->assertDatabaseHas('saudas', ['id' => $saleSaudaId, 'fulfilled_quantity' => 0, 'status' => 'Open']);
        $this->assertDatabaseCount('records', 0);

        $purchaseSaudaId = $this->createSauda('purchase', $partyId, $productId, 1, 10000);
        $this->post('/purchase', ['sauda_id' => $purchaseSaudaId, 'quantity' => '1.001', 'date' => '2026-10-21'])->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('records', 0);
    }

    public function test_sauda_type_must_match_transaction_and_used_sauda_is_locked(): void
    {
        ['productId' => $productId, 'partyId' => $partyId] = $this->masters();
        $saudaId = $this->createSauda('sale', $partyId, $productId, 1, 13000);

        $this->post('/purchase', ['sauda_id' => $saudaId, 'quantity' => 1, 'date' => '2026-10-21'])->assertSessionHasErrors('sauda_id');
        $this->post('/sale', ['sauda_id' => $saudaId, 'quantity' => 1, 'date' => '2026-10-21'])->assertRedirect('/sale');
        $this->put('/saudas/'.$saudaId, ['type' => 'sale', 'party_id' => $partyId, 'product_id' => $productId, 'quantity' => 2, 'rate' => 130, 'date' => '2026-10-20'])->assertSessionHasErrors('sauda');
        $this->delete('/saudas/'.$saudaId)->assertSessionHasErrors('sauda');
    }

    public function test_purchase_and_sale_show_only_their_matching_open_sauda(): void
    {
        ['productId' => $productId, 'partyId' => $partyId] = $this->masters();
        $purchaseId = $this->createSauda('purchase', $partyId, $productId, 2, 10000);
        $saleId = DB::table('saudas')->insertGetId([
            'reference' => 'SDA-SALE-200', 'type' => 'sale', 'party_id' => $partyId, 'product_id' => $productId,
            'quantity' => 2, 'fulfilled_quantity' => 0, 'rate_cents' => 13000, 'date' => '2026-10-20',
            'status' => 'Open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/purchase')->assertOk()->assertSee('SDA-PURCHASE-100')->assertDontSee('SDA-SALE-200')
            ->assertSee('Create Purchase Sauda');
        $this->get('/sale')->assertOk()->assertSee('SDA-SALE-200')->assertDontSee('SDA-PURCHASE-100')
            ->assertSee('Create Sale Sauda');
        $this->assertDatabaseHas('saudas', ['id' => $purchaseId, 'type' => 'purchase']);
        $this->assertDatabaseHas('saudas', ['id' => $saleId, 'type' => 'sale']);
    }
}
