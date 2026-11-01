<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('products')->exists() || DB::table('records')->exists()) {
            return;
        }
        DB::transaction(function () {
            $groupNames = ['Metals', 'Electrical', 'Pipes', 'Fittings'];
            $groupIds = [];
            foreach ($groupNames as $groupName) {
                $groupIds[$groupName] = DB::table('group_heads')->insertGetId(['name' => $groupName, 'created_at' => now(), 'updated_at' => now()]);
            }
            $partyNames = ['Summit Industries', 'Atlas Metal Supply', 'Vertex Engineering', 'Northstar Traders'];
            $partyIds = [];
            foreach ($partyNames as $partyName) {
                $partyIds[$partyName] = DB::table('parties')->insertGetId(['name' => $partyName, 'created_at' => now(), 'updated_at' => now()]);
            }
            $items = [
                ['ST-001', 'Metals', 'Steel sheets', 18.500, 8, 20, 245000, 252000, 286000],
                ['CP-002', 'Electrical', 'Copper wire', 1.250, 45, 100, 18500, 19000, 24000],
                ['IP-003', 'Pipes', 'Industrial pipes', 12.750, 12, 25, 120000, 124000, 148000],
                ['AF-004', 'Fittings', 'Aluminium fittings', 0.850, 64, 15, 85000, 87500, 105000],
            ];
            $ids = [];
            foreach ($items as [$sku,$group,$name,$weight,$stock,$reorder,$cost,$purchaseRate,$saleRate]) {
                $ids[] = DB::table('products')->insertGetId([
                    'sku' => $sku,
                    'group_name' => $group,
                    'name' => $name,
                    'weight' => $weight,
                    'unit' => 'Ton',
                    'stock' => $stock,
                    'reorder_level' => $reorder,
                    'cost_cents' => $cost,
                    'purchase_rate_cents' => $purchaseRate,
                    'sale_rate_cents' => $saleRate,
                    'opening_rate_cents' => $cost,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            foreach (range(5, 0) as $i) {
                foreach (['sale', 'purchase'] as $type) {
                    $price = ($type === 'sale' ? 286000 : 220000) + $i * 12000;
                    $party = $type === 'sale' ? 'Summit Industries' : 'Atlas Metal Supply';
                    DB::table('records')->insert(['reference' => 'DEMO-'.strtoupper($type).'-'.$i, 'type' => $type, 'group_head_id' => $groupIds['Metals'], 'party_id' => $partyIds[$party], 'party' => $party, 'product_id' => $ids[0], 'quantity' => 20 + $i * 3, 'unit_price_cents' => $price, 'total_cents' => $price * (20 + $i * 3), 'date' => now()->startOfMonth()->subMonths($i)->toDateString(), 'status' => 'Recorded', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            foreach (['purchase-orders', 'sale-orders'] as $i => $type) {
                $party = $i ? 'Vertex Engineering' : 'Northstar Traders';
                DB::table('records')->insert(['reference' => 'DEMO-ORDER-'.$i, 'type' => $type, 'group_head_id' => $groupIds['Electrical'], 'party_id' => $partyIds[$party], 'party' => $party, 'product_id' => $ids[1], 'quantity' => 100, 'unit_price_cents' => 18500, 'total_cents' => 1850000, 'date' => today(), 'status' => 'Open', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }
}
