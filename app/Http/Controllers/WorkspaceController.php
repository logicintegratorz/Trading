<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceController
{
    public const LABELS = ['purchase' => 'Receive Stock (Purchase)', 'sale' => 'Dispatch Stock (Sale)', 'inventory' => 'Inventory', 'purchase-orders' => 'Purchase Orders', 'sale-orders' => 'Sale Orders'];

    private function money(string|int|float $value): int
    {
        [$whole,$fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function home(): View
    {
        $month = now()->startOfMonth()->toDateString();
        $totals = DB::table('records')->where('date', '>=', $month)->where('date', '<=', today())->selectRaw('type, SUM(total_cents) as total')->groupBy('type')->pluck('total', 'type');
        $low = DB::table('products')->whereColumn('stock', '<=', 'reorder_level')->orderBy('stock')->get();
        $recent = DB::table('records')->orderByDesc('date')->orderByDesc('id')->limit(5)->get();
        $open = DB::table('records')->where('status', 'Open')->count();
        $stockValue = DB::table('products')->selectRaw('COALESCE(SUM(stock * cost_cents),0) as total')->value('total');
        $chart = collect(range(5, 0))->map(function ($i) {
            $start = now()->startOfMonth()->subMonths($i);
            $v = DB::table('records')->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])->selectRaw('type, SUM(total_cents) as total')->groupBy('type')->pluck('total', 'type');

            return ['label' => $start->format('M'), 'sale' => (int) ($v['sale'] ?? 0), 'purchase' => (int) ($v['purchase'] ?? 0)];
        });

        return view('home', compact('totals', 'low', 'recent', 'open', 'stockValue', 'chart'));
    }

    public function index(Request $request, string $module): View
    {
        $title = self::LABELS[$module];
        $searchInput = $request->query('q', '');
        $search = is_string($searchInput) ? mb_substr(trim($searchInput), 0, 120) : '';
        $query = DB::table($module === 'inventory' ? 'products' : 'records');
        if ($module !== 'inventory') {
            $query->where('type', $module);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($module, $search) {
                $q->where($module === 'inventory' ? 'name' : 'party', 'like', '%'.$search.'%')->orWhere($module === 'inventory' ? 'sku' : 'reference', 'like', '%'.$search.'%');
            });
        }
        $rows = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $products = DB::table('products')->orderBy('name')->get();
        $saudas = collect();
        if (in_array($module, ['purchase', 'sale'], true)) {
            $saudas = DB::table('saudas as s')->join('parties as pa', 's.party_id', '=', 'pa.id')->join('products as p', 's.product_id', '=', 'p.id')
                ->where('s.type', $module)->whereIn('s.status', ['Open', 'Partial'])
                ->select('s.*', 'pa.name as party_name', 'p.name as product_name', 'p.stock as product_stock', 'p.unit')
                ->orderByDesc('s.date')->get();
        }

        return view('module', compact('module', 'title', 'rows', 'products', 'saudas', 'search'));
    }

    public function inventory(Request $request): View
    {
        $searchInput = $request->query('q', '');
        $groupInput = $request->query('group', '');
        $stockStatusInput = $request->query('stock_status');
        $search = is_string($searchInput) ? mb_substr(trim($searchInput), 0, 120) : '';
        $group = is_string($groupInput) ? mb_substr(trim($groupInput), 0, 80) : '';
        $stockStatus = in_array($stockStatusInput, ['healthy', 'low'], true)
            ? $stockStatusInput
            : '';

        $query = DB::table('products');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('group_name', 'like', '%'.$search.'%');
            });
        }

        if ($group !== '') {
            $query->where('group_name', $group);
        }

        if ($stockStatus === 'healthy') {
            $query->whereColumn('stock', '>', 'reorder_level');
        } elseif ($stockStatus === 'low') {
            $query->whereColumn('stock', '<=', 'reorder_level');
        }

        $products = $query->orderBy('group_name')->orderBy('name')->paginate(15)->withQueryString();
        $groups = DB::table('products')->select('group_name')->distinct()->orderBy('group_name')->pluck('group_name');
        $report = DB::table('products')->selectRaw(
            'COUNT(*) as total_products, '
            .'SUM(CASE WHEN stock > reorder_level THEN 1 ELSE 0 END) as healthy_products, '
            .'SUM(CASE WHEN stock <= reorder_level THEN 1 ELSE 0 END) as low_products, '
            .'COALESCE(SUM(stock * cost_cents), 0) as stock_value'
        )->first();

        return view('inventory', compact('products', 'groups', 'report', 'search', 'group', 'stockStatus'));
    }

    public function createProduct(): View
    {
        $groups = DB::table('group_heads')->orderBy('name')->get();
        $product = null;

        return view('products.create', compact('groups', 'product'));
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $data = $this->validateProduct($request);
        $this->ensureUniqueProduct($data['group_name'], $data['product_name']);

        $openingRateCents = $this->money($data['opening_rate']);

        DB::table('products')->insert([
            'sku' => 'PRD-'.Str::upper(Str::random(10)),
            'group_name' => $data['group_name'],
            'name' => $data['product_name'],
            'product_name_key' => mb_strtolower(trim($data['product_name'])),
            'weight' => $data['weight'],
            'unit' => 'Ton',
            'stock' => $data['opening_qty'],
            'reorder_level' => $data['minimum_qty'],
            'cost_cents' => $openingRateCents,
            'purchase_rate_cents' => $this->money($data['purchase_rate']),
            'sale_rate_cents' => $this->money($data['sale_rate']),
            'opening_rate_cents' => $openingRateCents,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('inventory.index')->with('success', 'Product added to inventory.');
    }

    public function editProduct(int $id): View
    {
        $product = DB::table('products')->where('id', $id)->first();
        abort_unless($product, 404);
        $groups = DB::table('group_heads')->orderBy('name')->get();

        return view('products.create', compact('groups', 'product'));
    }

    public function updateProduct(Request $request, int $id): RedirectResponse
    {
        $product = DB::table('products')->where('id', $id)->first();
        abort_unless($product, 404);
        $data = $this->validateProduct($request);
        $this->ensureUniqueProduct($data['group_name'], $data['product_name'], $id);
        $openingRateCents = $this->money($data['opening_rate']);
        DB::table('products')->where('id', $id)->update([
            'group_name' => $data['group_name'],
            'name' => $data['product_name'],
            'product_name_key' => mb_strtolower(trim($data['product_name'])),
            'weight' => $data['weight'],
            'unit' => 'Ton',
            'stock' => $data['opening_qty'],
            'reorder_level' => $data['minimum_qty'],
            'cost_cents' => $openingRateCents,
            'purchase_rate_cents' => $this->money($data['purchase_rate']),
            'sale_rate_cents' => $this->money($data['sale_rate']),
            'opening_rate_cents' => $openingRateCents,
            'updated_at' => now(),
        ]);

        return redirect()->route('inventory.index')->with('success', 'Product updated. Current quantity remains measured in tons.');
    }

    public function destroyProduct(int $id): RedirectResponse
    {
        $product = DB::table('products')->where('id', $id)->first();
        abort_unless($product, 404);
        if (DB::table('records')->where('product_id', $id)->exists() || DB::table('saudas')->where('product_id', $id)->exists()) {
            throw ValidationException::withMessages(['product' => 'This product is used in a Sauda or transaction and cannot be deleted.']);
        }
        DB::table('products')->where('id', $id)->delete();

        return redirect()->route('inventory.index')->with('success', 'Unused product deleted.');
    }

    public function storeRecord(Request $request, string $module): RedirectResponse
    {
        $data = $request->validate([
            'sauda_id' => ['required', 'integer', Rule::exists('saudas', 'id')->where('type', $module)],
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        DB::transaction(function () use ($data, $module) {
            $sauda = DB::table('saudas')->where('id', $data['sauda_id'])->lockForUpdate()->first();
            if (! $sauda || $sauda->type !== $module || ! in_array($sauda->status, ['Open', 'Partial'], true)) {
                throw ValidationException::withMessages(['sauda_id' => 'This Sauda is not available for the selected transaction.']);
            }
            $remaining = (float) $sauda->quantity - (float) $sauda->fulfilled_quantity;
            if ((float) $data['quantity'] > $remaining + 0.0001) {
                throw ValidationException::withMessages(['quantity' => 'Quantity exceeds the Sauda balance of '.rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.').' Ton.']);
            }
            $product = DB::table('products')->where('id', $sauda->product_id)->lockForUpdate()->first();
            if (! $product) {
                throw ValidationException::withMessages(['sauda_id' => 'The product linked to this Sauda is no longer available.']);
            }
            if ($module === 'sale' && $product->stock < $data['quantity']) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient stock. Available: '.$product->stock.' '.$product->unit.'.']);
            }
            if (DB::table('records')->where('type', $module)->where('sauda_id', $sauda->id)->where('date', $data['date'])->where('quantity', $data['quantity'])->exists()) {
                throw ValidationException::withMessages(['quantity' => 'This exact '.$module.' entry is already recorded for the selected Sauda and date.']);
            }
            $price = (int) $sauda->rate_cents;
            if ($module === 'sale') {
                DB::table('products')->where('id', $product->id)->decrement('stock', $data['quantity']);
            }
            if ($module === 'purchase') {
                $newStock = $product->stock + $data['quantity'];
                $cost = (int) round(($product->stock * $product->cost_cents + $data['quantity'] * $price) / $newStock);
                DB::table('products')->where('id', $product->id)->update(['stock' => $newStock, 'cost_cents' => $cost, 'updated_at' => now()]);
            }
            $party = DB::table('parties')->where('id', $sauda->party_id)->value('name');
            $groupHeadId = DB::table('group_heads')->where('name', $product->group_name)->value('id');
            $prefix = $module === 'purchase' ? 'PUR' : 'DSP';
            DB::table('records')->insert(['reference' => $prefix.'-'.Str::upper(Str::random(10)), 'type' => $module, 'group_head_id' => $groupHeadId, 'party_id' => $sauda->party_id, 'sauda_id' => $sauda->id, 'party' => $party, 'product_id' => $product->id, 'quantity' => $data['quantity'], 'unit_price_cents' => $price, 'total_cents' => (int) round($price * (float) $data['quantity']), 'date' => $data['date'], 'status' => $module === 'sale' ? 'Dispatched' : 'Received', 'created_at' => now(), 'updated_at' => now()]);
            $fulfilled = (float) $sauda->fulfilled_quantity + (float) $data['quantity'];
            DB::table('saudas')->where('id', $sauda->id)->update([
                'fulfilled_quantity' => $fulfilled,
                'status' => $fulfilled + 0.0001 >= (float) $sauda->quantity ? 'Completed' : 'Partial',
                'updated_at' => now(),
            ]);
        });

        return redirect('/'.$module)->with('success', $module === 'sale' ? 'Sale dispatched and inventory reduced.' : 'Purchase received and inventory increased.');
    }

    /** @return array{group_name:string,product_name:string,weight:string,purchase_rate:string,sale_rate:string,opening_qty:string,opening_rate:string,minimum_qty:string} */
    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'group_name' => ['required', 'string', 'max:80', 'exists:group_heads,name'],
            'product_name' => ['required', 'string', 'max:120'],
            'weight' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:1000000'],
            'purchase_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'sale_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'opening_qty' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:1000000'],
            'opening_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'minimum_qty' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:1000000'],
        ]);
    }

    private function ensureUniqueProduct(string $groupName, string $productName, ?int $ignoreId = null): void
    {
        $query = DB::table('products')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($productName))]);
        if ($ignoreId) $query->where('id', '!=', $ignoreId);
        if ($query->exists()) throw ValidationException::withMessages(['product_name' => 'A product with this name already exists. Use the existing product instead of creating another one.']);
    }
}
