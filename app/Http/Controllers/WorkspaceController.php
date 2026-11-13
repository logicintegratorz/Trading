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
    public const LABELS = ['purchase' => 'Receive Stock (Purchase)', 'sale' => 'Dispatch Stock (Sale)', 'inventory' => 'Inventory', 'purchase-orders' => 'Purchase Orders', 'sale-orders' => 'Orders'];

    private function money(string|int|float $value): int
    {
        [$whole,$fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** Allocate one voucher serial for the current calendar day. */
    private function nextDailyVoucherNumber(): int
    {
        $sequenceDate = now()->toDateString();
        $timestamp = now();

        // The date is the primary key: at midnight a fresh row is created and
        // serial numbering starts again from 1. The row lock keeps parallel
        // voucher saves from receiving the same number.
        DB::table('daily_voucher_sequences')->insertOrIgnore([
            'sequence_date' => $sequenceDate,
            'last_number' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $lastNumber = (int) DB::table('daily_voucher_sequences')
            ->where('sequence_date', $sequenceDate)
            ->lockForUpdate()
            ->value('last_number');
        $nextNumber = $lastNumber + 1;

        DB::table('daily_voucher_sequences')->where('sequence_date', $sequenceDate)->update([
            'last_number' => $nextNumber,
            'updated_at' => $timestamp,
        ]);

        return $nextNumber;
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
        if ($module === 'sale-orders') {
            $query->select('records.*')->selectRaw('EXISTS (SELECT 1 FROM saudas WHERE saudas.order_id = records.id) as has_linked_sauda');
        }
        if (in_array($module, ['purchase', 'sale'], true)) {
            // A voucher can contain several material lines. Keep those lines together in
            // the register so one saved purchase/sale is shown as one stock entry.
            $voucherKey = "COALESCE(NULLIF(voucher_reference, ''), reference)";
            $rows = $query->selectRaw(
                $voucherKey.' as voucher_key, MIN(id) as id, MAX(id) as latest_id, MAX(reference) as reference, '
                .'MAX(voucher_reference) as voucher_reference, MAX(party) as party, MAX(date) as date, '
                .'SUM(quantity) as quantity, SUM(total_cents) as total_cents, MAX(labour_cents) as labour_cents, '
                .'MAX(discount_cents) as discount_cents, MAX(commission_cents) as commission_cents, '
                .'MAX(freight_cents) as freight_cents, MAX(other_charges_cents) as other_charges_cents, '
                .'MAX(gst_percent) as gst_percent, MAX(status) as status, COUNT(*) as line_count'
            )->groupByRaw($voucherKey)->orderByDesc('latest_id')->paginate(15)->withQueryString();
        } else {
            $rows = $query->orderByDesc('id')->paginate(15)->withQueryString();
        }
        $products = DB::table('products')->orderBy('name')->get();
        $saudas = collect();
        if (in_array($module, ['purchase', 'sale'], true)) {
            $saudaLines = DB::table('saudas as s')->join('parties as pa', 's.party_id', '=', 'pa.id')->join('products as p', 's.product_id', '=', 'p.id')
                ->where('s.type', $module)->whereIn('s.status', ['Open', 'Partial'])
                ->select('s.*', 'pa.name as party_name', 'p.group_name', 'p.stock as product_stock', 'p.unit')
                ->orderByDesc('s.date')->get();
            // A direct Sale with multiple product lines creates its Sauda lines in the
            // same second. Present that one saved sale as a single pending entry.
            $saudas = $module === 'sale'
                ? $saudaLines->groupBy(fn ($line) => $line->order_id ? 'order-'.$line->order_id : $line->party_id.'|'.$line->date.'|'.$line->created_at)
                    ->map(function ($lines) {
                        $sale = $lines->first();
                        $sale->quantity = $lines->sum('quantity');
                        $sale->fulfilled_quantity = $lines->sum('fulfilled_quantity');
                        $sale->line_count = $lines->count();
                        $sale->line_ids = $lines->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                        $sale->group_name = $lines->pluck('group_name')->unique()->implode(', ');
                        $sale->status = $lines->contains(fn ($line) => $line->status === 'Partial') ? 'Partial' : 'Open';

                        return $sale;
                    })->values()
                : $saudaLines;
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

    public function createRecord(Request $request, string $module): View
    {
        $initialSale = null;
        if ($module === 'sale' && $request->filled('sale_id')) {
            $initialSale = DB::table('sale_vouchers')->where('id', $request->integer('sale_id'))
                ->whereIn('status', ['Approved', 'Partial'])->first();
            abort_unless($initialSale, 404);
        }
        $saudas = DB::table('saudas as s')->join('parties as pa', 's.party_id', '=', 'pa.id')->join('products as p', 's.product_id', '=', 'p.id')
            ->where('s.type', $module)->whereIn('s.status', ['Open', 'Partial'])
            ->select('s.*', 'pa.name as party_name', 'p.group_name')->orderByDesc('s.date')->orderByDesc('s.id')->get();
        $products = DB::table('products')->orderBy('group_name')->orderBy('name')->get();
        $groups = DB::table('group_heads')->orderBy('name')->get();
        $parties = DB::table('parties')->orderBy('name')->get();
        $title = $module === 'purchase' ? 'Receive Stock' : 'Sale Stock';
        $requestedSaudaIds = $request->query('sauda_ids', '');
        $requestedSaudaIds = is_array($requestedSaudaIds) ? $requestedSaudaIds : explode(',', (string) $requestedSaudaIds);
        $initialSaudaIds = $initialSale
            ? DB::table('sale_voucher_items')->where('sale_voucher_id', $initialSale->id)->whereColumn('fulfilled_quantity', '<', 'quantity')->pluck('sauda_id')->map(fn ($id) => (int) $id)->values()
            : collect($requestedSaudaIds)
            ->filter(fn ($id) => ctype_digit($id))->map(fn ($id) => (int) $id)->unique()->values();
        $initialBundle = $initialSaudaIds->isEmpty()
            ? collect()
            : $saudas->whereIn('id', $initialSaudaIds)->values();
        abort_if($initialSaudaIds->isNotEmpty() && ($module !== 'sale' || $initialBundle->count() !== $initialSaudaIds->count()), 404);

        return view('stock-entry-form', compact('module', 'title', 'saudas', 'products', 'parties', 'groups', 'initialSaudaIds', 'initialBundle', 'initialSale'));
    }

    public function printRecord(string $module, int $id): View
    {
        $record = DB::table('records as r')->leftJoin('products as p', 'r.product_id', '=', 'p.id')
            ->leftJoin('group_heads as g', 'r.group_head_id', '=', 'g.id')->where('r.id', $id)->where('r.type', $module)
            ->select('r.*', 'p.name as product_name', 'g.name as group_head_name')->first();
        abort_unless($record, 404);
        $records = $record->voucher_reference
            ? DB::table('records as r')->leftJoin('products as p', 'r.product_id', '=', 'p.id')->where('r.voucher_reference', $record->voucher_reference)->select('r.*', 'p.name as product_name')->orderBy('r.id')->get()
            : collect([$record]);
        $title = $module === 'purchase' ? 'Purchase Receipt' : 'Sale Dispatch Receipt';

        return view('stock-receipt', compact('record', 'records', 'title'));
    }

    public function editRecord(string $module, int $id): View
    {
        $record = DB::table('records as r')->leftJoin('products as p', 'r.product_id', '=', 'p.id')
            ->where('r.id', $id)->where('r.type', $module)->select('r.*', 'p.name as product_name', 'p.group_name')->first();
        abort_unless($record, 404);

        return view('records.edit', compact('record', 'module'));
    }

    public function updateRecord(Request $request, string $module, int $id): RedirectResponse
    {
        $record = DB::table('records')->where('id', $id)->where('type', $module)->first();
        abort_unless($record, 404);
        $rateRules = $module === 'purchase'
            ? ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000']
            : ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000000'];
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'goods_number' => ['nullable', 'string', 'max:60'],
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000'],
            'rate' => $rateRules,
        ]);
        $data['goods_number'] = filled($data['goods_number'] ?? null) ? mb_strtoupper(trim($data['goods_number'])) : null;
        DB::transaction(function () use ($record, $data, $module): void {
            $product = DB::table('products')->where('id', $record->product_id)->lockForUpdate()->first();
            abort_unless($product, 404);
            $oldQuantity = (float) $record->quantity;
            $newQuantity = (float) $data['quantity'];
            $stockAfter = $module === 'purchase' ? (float)$product->stock - $oldQuantity + $newQuantity : (float)$product->stock + $oldQuantity - $newQuantity;
            if ($stockAfter < -0.0001) throw ValidationException::withMessages(['quantity' => 'Quantity cannot be increased because stock would become negative.']);
            if ($record->sauda_id) $this->adjustSaudaFulfilment((int)$record->sauda_id, $newQuantity - $oldQuantity);
            if ($record->sale_voucher_id) $this->adjustSaleVoucherFulfilment((int)$record->sale_voucher_id, (int)$record->sauda_id, $newQuantity - $oldQuantity);
            // A blank optional Purchase rate leaves its already-recorded rate unchanged.
            $price = $this->money($data['rate'] ?? ($record->unit_price_cents / 100));
            DB::table('products')->where('id', $product->id)->update(['stock' => max(0, $stockAfter), 'cost_cents' => $module === 'purchase' ? $price : $product->cost_cents, 'updated_at' => now()]);
            DB::table('records')->where('id', $record->id)->update(['date' => $data['date'], 'goods_number' => $data['goods_number'], 'quantity' => $newQuantity, 'unit_price_cents' => $price, 'total_cents' => (int)round($price * $newQuantity), 'updated_at' => now()]);
        });
        return redirect()->route('module', $module)->with('success', ucfirst($module).' entry updated. Stock and Sauda balance were recalculated.');
    }

    public function destroyRecord(string $module, int $id): RedirectResponse
    {
        $record = DB::table('records')->where('id', $id)->where('type', $module)->first();
        abort_unless($record, 404);
        DB::transaction(function () use ($record, $module): void {
            $product = DB::table('products')->where('id', $record->product_id)->lockForUpdate()->first();
            abort_unless($product, 404);
            $stockAfter = $module === 'purchase' ? (float)$product->stock - (float)$record->quantity : (float)$product->stock + (float)$record->quantity;
            if ($stockAfter < -0.0001) throw ValidationException::withMessages(['record' => 'This purchase cannot be deleted because its stock has already been dispatched.']);
            if ($record->sauda_id) $this->adjustSaudaFulfilment((int)$record->sauda_id, -(float)$record->quantity);
            if ($record->sale_voucher_id) $this->adjustSaleVoucherFulfilment((int)$record->sale_voucher_id, (int)$record->sauda_id, -(float)$record->quantity);
            DB::table('products')->where('id', $product->id)->update(['stock' => max(0, $stockAfter), 'updated_at' => now()]);
            DB::table('records')->where('id', $record->id)->delete();
        });
        return redirect()->route('module', $module)->with('success', ucfirst($module).' entry deleted and stock balance restored.');
    }

    private function adjustSaudaFulfilment(int $saudaId, float $change): void
    {
        $sauda = DB::table('saudas')->where('id', $saudaId)->lockForUpdate()->first();
        if (! $sauda) return;
        $fulfilled = max(0, (float)$sauda->fulfilled_quantity + $change);
        if ($fulfilled > (float)$sauda->quantity + 0.0001) throw ValidationException::withMessages(['quantity' => 'Quantity exceeds the linked Sauda balance.']);
        $status = $fulfilled <= 0.0001 ? 'Open' : ($fulfilled + 0.0001 >= (float)$sauda->quantity ? 'Completed' : 'Partial');
        DB::table('saudas')->where('id', $saudaId)->update(['fulfilled_quantity' => $fulfilled, 'status' => $status, 'updated_at' => now()]);
    }

    private function adjustSaleVoucherFulfilment(int $saleVoucherId, int $saudaId, float $change): void
    {
        $item = DB::table('sale_voucher_items')->where('sale_voucher_id', $saleVoucherId)->where('sauda_id', $saudaId)->lockForUpdate()->first();
        if (! $item) return;
        $fulfilled = max(0, (float) $item->fulfilled_quantity + $change);
        if ($fulfilled > (float) $item->quantity + 0.0001) {
            throw ValidationException::withMessages(['quantity' => 'Quantity exceeds the approved Sale Voucher balance.']);
        }
        DB::table('sale_voucher_items')->where('id', $item->id)->update(['fulfilled_quantity' => $fulfilled, 'updated_at' => now()]);
        $items = DB::table('sale_voucher_items')->where('sale_voucher_id', $saleVoucherId)->lockForUpdate()->get();
        $hasUsed = $items->contains(fn ($row) => (float) $row->fulfilled_quantity > 0.0001);
        $complete = $items->isNotEmpty() && $items->every(fn ($row) => (float) $row->fulfilled_quantity + 0.0001 >= (float) $row->quantity);
        $status = $complete ? 'Completed' : ($hasUsed ? 'Partial' : 'Approved');
        DB::table('sale_vouchers')->where('id', $saleVoucherId)->update(['status' => $status, 'updated_at' => now()]);
        $orderId = DB::table('sale_vouchers')->where('id', $saleVoucherId)->value('order_id');
        if ($orderId) DB::table('records')->where('id', $orderId)->update(['status' => $complete ? 'Completed' : 'Dispatch Partial', 'updated_at' => now()]);
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

        $productId = DB::table('products')->insertGetId([
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

        if ($request->boolean('popup')) {
            return redirect()->route('products.quick-created', $productId);
        }

        return redirect()->route('inventory.index')->with('success', 'Product added to inventory.');
    }

    public function quickCreatedProduct(int $id): View
    {
        $product = DB::table('products')->where('id', $id)->first();
        abort_unless($product, 404);

        return view('products.quick-created', compact('product'));
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

    public function stockItems(Request $request): View
    {
        $searchInput = $request->query('q', '');
        $groupInput = $request->query('group', '');
        $search = is_string($searchInput) ? mb_substr(trim($searchInput), 0, 120) : '';
        $group = is_string($groupInput) ? mb_substr(trim($groupInput), 0, 80) : '';

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

        $products = $query->orderBy('group_name')->orderBy('name')->paginate(20)->withQueryString();
        $groups = DB::table('products')->select('group_name')->distinct()->orderBy('group_name')->pluck('group_name');

        return view('products.size-rates-list', compact('products', 'groups', 'search', 'group'));
    }

    public function sizeRates(Request $request): View
    {
        $products = DB::table('products')->orderBy('group_name')->orderBy('name')->get();
        abort_if($products->isEmpty(), 404, 'Add a product before managing size rates.');

        $selectedId = $request->integer('product_id');
        $selected = $products->firstWhere('id', $selectedId) ?? $products->first();
        $position = $products->search(fn (object $product): bool => $product->id === $selected->id);
        $previous = $position > 0 ? $products[$position - 1] : null;
        $next = $position < $products->count() - 1 ? $products[$position + 1] : null;

        return view('products.size-rates', compact('products', 'selected', 'previous', 'next'));
    }

    public function updateSizeRate(Request $request, int $id): RedirectResponse
    {
        $product = DB::table('products')->where('id', $id)->first();
        abort_unless($product, 404);
        $data = $request->validate([
            'purchase_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'difference_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'sale_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'gst_percent' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ]);
        // Voucher Difference always starts from the selected product's Sale Rate.
        $data['difference_rate'] = (float) $data['sale_rate'];

        DB::table('products')->where('id', $id)->update([
            'purchase_rate_cents' => $this->money($data['purchase_rate']),
            'difference_cents' => $this->money($data['difference_rate']),
            'sale_rate_cents' => $this->money($data['sale_rate']),
            'gst_percent' => $data['gst_percent'],
            'updated_at' => now(),
        ]);

        return redirect()->route('products.size-rates', ['product_id' => $id])->with('success', 'Size purchase rate, sale rate and GST updated.');
    }

    public function createSizeRate(): View
    {
        $groups = DB::table('group_heads')->orderBy('name')->get();
        return view('products.size-rates-create', compact('groups'));
    }

    public function storeSizeRate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group_name' => ['nullable', 'string', 'max:80', 'exists:group_heads,name'], 'name' => ['required', 'string', 'max:120'],
            'weight' => ['nullable', 'numeric', 'min:0'], 'opening_qty' => ['nullable', 'numeric', 'min:0'],
            'purchase_rate' => ['required', 'numeric', 'min:0'], 'sale_rate' => ['required', 'numeric', 'min:0'], 'gst_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $data['group_name'] = $data['group_name'] ?: 'General';
        $data['weight'] = $data['weight'] ?? 0;
        $data['opening_qty'] = $data['opening_qty'] ?? 0;
        DB::table('group_heads')->updateOrInsert(['name' => $data['group_name']], ['created_at' => now(), 'updated_at' => now()]);
        $this->ensureUniqueProduct($data['group_name'], $data['name']);
        $purchase = $this->money($data['purchase_rate']);
        $id = DB::table('products')->insertGetId(['sku' => 'PRD-'.Str::upper(Str::random(10)), 'group_name' => $data['group_name'], 'name' => $data['name'], 'product_name_key' => mb_strtolower(trim($data['name'])), 'weight' => $data['weight'], 'unit' => 'Ton', 'stock' => $data['opening_qty'], 'reorder_level' => 0, 'cost_cents' => $purchase, 'purchase_rate_cents' => $purchase, 'difference_cents' => $purchase, 'sale_rate_cents' => $this->money($data['sale_rate']), 'opening_rate_cents' => $purchase, 'gst_percent' => $data['gst_percent'], 'created_at' => now(), 'updated_at' => now()]);
        return redirect()->route('products.size-rates', ['product_id' => $id])->with('success', 'New Stock Item added successfully.');
    }

    public function storeRecord(Request $request, string $module): RedirectResponse
    {
        $rateRules = $module === 'purchase'
            ? ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000']
            : ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000000'];
        $data = $request->validate([
            'sauda_id' => ['nullable', 'integer', Rule::exists('saudas', 'id')->where('type', $module)],
            'sauda_ids' => ['nullable', 'string', 'max:1000'],
            'sale_id' => ['nullable', 'integer', 'exists:sale_vouchers,id'],
            'party_id' => ['required_without_all:sauda_id,sauda_ids', 'nullable', 'integer', 'exists:parties,id'],
            'goods_number' => ['nullable', 'string', 'max:60'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.group_name' => ['nullable', 'string', 'max:80', 'exists:group_heads,name'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.difference' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'items.*.base_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'items.*.rate' => $rateRules,
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000'],
            'labour' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'commission' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'freight' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'other_charges' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'gst_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ]);
        $bundleSaudaIds = collect(explode(',', (string) ($data['sauda_ids'] ?? '')))
            ->filter(fn ($id) => ctype_digit($id))->map(fn ($id) => (int) $id)->unique()->values();
        if (filled($data['sauda_ids'] ?? null) && $bundleSaudaIds->isEmpty()) {
            throw ValidationException::withMessages(['sauda_ids' => 'The selected Sale bundle is invalid. Please reopen it and try again.']);
        }
        // Keep vehicle/challan references consistent in registers and printouts.
        $data['goods_number'] = filled($data['goods_number'] ?? null)
            ? mb_strtoupper(trim($data['goods_number']))
            : null;
        $productIds = collect($data['items'])->pluck('product_id');
        if ($productIds->count() !== $productIds->unique()->count()) throw ValidationException::withMessages(['items' => 'Add each product only once in a voucher. Change its quantity instead.']);
        if ($module === 'sale' && empty($data['sauda_id']) && $bundleSaudaIds->isEmpty()) {
            DB::transaction(function () use ($data): void {
                foreach ($data['items'] as $item) {
                    $duplicate = DB::table('saudas')->where('type', 'sale')->where('party_id', $data['party_id'])
                        ->where('product_id', $item['product_id'])->where('date', $data['date'])->where('quantity', $item['quantity'])
                        ->where('rate_cents', $this->money($item['rate']))->exists();
                    if ($duplicate) throw ValidationException::withMessages(['items' => 'One of these Sale entries already exists. Open it from Sale Stock and dispatch against it.']);
                    DB::table('saudas')->insert([
                        'reference' => 'SDA-'.Str::upper(Str::random(10)), 'type' => 'sale', 'party_id' => $data['party_id'], 'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'], 'fulfilled_quantity' => 0, 'rate_cents' => $this->money($item['rate']), 'date' => $data['date'], 'status' => 'Open',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
            return redirect()->route('module', 'sale')->with('success', 'Sale punched successfully. Each product is pending dispatch; stock will reduce only when you dispatch against it.');
        }
        $recordId = null;
        DB::transaction(function () use ($data, $module, $bundleSaudaIds, &$recordId) {
            $saleVoucher = !empty($data['sale_id']) && $module === 'sale'
                ? DB::table('sale_vouchers')->where('id', $data['sale_id'])->lockForUpdate()->first()
                : null;
            if (!empty($data['sale_id']) && (! $saleVoucher || !in_array($saleVoucher->status, ['Approved', 'Partial'], true))) {
                throw ValidationException::withMessages(['sale_id' => 'This Sale Voucher is not available for dispatch.']);
            }
            $saleItemsBySauda = $saleVoucher
                ? DB::table('sale_voucher_items')->where('sale_voucher_id', $saleVoucher->id)->lockForUpdate()->get()->keyBy('sauda_id')
                : collect();
            $sauda = !empty($data['sauda_id']) ? DB::table('saudas')->where('id', $data['sauda_id'])->lockForUpdate()->first() : null;
            $bundleSaudas = $bundleSaudaIds->isEmpty()
                ? collect()
                : DB::table('saudas')->whereIn('id', $bundleSaudaIds)->where('type', $module)->lockForUpdate()->get();
            if ($bundleSaudaIds->isNotEmpty() && $bundleSaudas->count() !== $bundleSaudaIds->count()) {
                throw ValidationException::withMessages(['sauda_ids' => 'One or more selected Sale lines are no longer available.']);
            }
            $bundleByProduct = $bundleSaudas->keyBy('product_id');
            if ($bundleSaudaIds->isNotEmpty()) {
                if ($bundleSaudas->pluck('party_id')->unique()->count() !== 1) {
                    throw ValidationException::withMessages(['sauda_ids' => 'All bundled Sale lines must belong to the same customer.']);
                }
                if (collect($data['items'])->pluck('product_id')->diff($bundleByProduct->keys())->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => 'Each dispatched product must belong to the selected Sale bundle.']);
                }
            }
            if ($saleVoucher) {
                if ($bundleSaudaIds->isEmpty() || $bundleSaudaIds->diff($saleItemsBySauda->keys())->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => 'Each dispatched product must belong to the selected Sale Voucher.']);
                }
                if ($saleVoucher->party_id !== $bundleSaudas->first()?->party_id) {
                    throw ValidationException::withMessages(['items' => 'The Sale Voucher customer does not match the selected Sauda.']);
                }
            }
            if ($sauda) {
                $remaining = (float) $sauda->quantity - (float) $sauda->fulfilled_quantity;
                $saudaProduct = DB::table('products')->where('id', $sauda->product_id)->first();
                if (! $saudaProduct || ! in_array($sauda->status, ['Open', 'Partial'], true)) throw ValidationException::withMessages(['sauda_id' => 'This Sauda is no longer available.']);
                if (collect($data['items'])->sum('quantity') > $remaining + 0.0001) throw ValidationException::withMessages(['items' => 'Total quantity exceeds the selected Sauda balance.']);
            }
            $partyId = $sauda?->party_id ?? ($bundleSaudas->first()?->party_id ?? ($data['party_id'] ?? null));
            $party = DB::table('parties')->where('id', $partyId)->value('name');
            if (! $party) throw ValidationException::withMessages(['party_id' => 'Please select a valid customer.']);
            $prefix = $module === 'purchase' ? 'PUR' : 'DSP';
            $voucherReference = $prefix.'V-'.Str::upper(Str::random(10));
            $voucherNumber = $this->nextDailyVoucherNumber();
            $totalWeight = (float) collect($data['items'])->sum('quantity');
            $labour = $this->money(((float) ($data['labour'] ?? 0)) * $totalWeight);
            $discount = $this->money($data['discount'] ?? 0);
            $commission = $this->money($data['commission'] ?? 0);
            $freight = $this->money($data['freight'] ?? 0);
            $others = $this->money($data['other_charges'] ?? 0);
            $gstPercent = (float) ($data['gst_percent'] ?? 18);
            $isFirstLine = true;
            foreach ($data['items'] as $item) {
                $product = DB::table('products')->where('id', $item['product_id'])->lockForUpdate()->first();
                $linkedSauda = $sauda ?? $bundleByProduct->get($product->id);
                if ($sauda && mb_strtolower($product->group_name) !== mb_strtolower($saudaProduct->group_name)) throw ValidationException::withMessages(['items' => 'All items for a selected Sauda must belong to its Group Head.']);
                if ($linkedSauda) {
                    $lineRemaining = (float) $linkedSauda->quantity - (float) $linkedSauda->fulfilled_quantity;
                    if (! in_array($linkedSauda->status, ['Open', 'Partial'], true) || (float) $item['quantity'] > $lineRemaining + 0.0001) {
                        throw ValidationException::withMessages(['items' => 'One or more quantities exceed the selected Sale balance.']);
                    }
                }
                if ($saleVoucher) {
                    $saleItem = $saleItemsBySauda->get($linkedSauda?->id);
                    $saleRemaining = $saleItem ? (float) $saleItem->quantity - (float) $saleItem->fulfilled_quantity : -1;
                    if ($saleRemaining < 0 || (float) $item['quantity'] > $saleRemaining + 0.0001) {
                        throw ValidationException::withMessages(['items' => 'One or more quantities exceed the approved Sale Voucher balance.']);
                    }
                }
                if ($module === 'sale' && $product->stock < $item['quantity']) throw ValidationException::withMessages(['items' => 'Insufficient stock for '.$product->name.'. Available: '.$product->stock.' '.$product->unit.'.']);
                $baseRate = filled($item['base_rate'] ?? null) ? $this->money($item['base_rate']) : (int) $product->purchase_rate_cents;
                $difference = $this->money($item['difference']);
                $price = $baseRate + $difference;
                if ($module === 'sale') DB::table('products')->where('id', $product->id)->decrement('stock', $item['quantity']);
                if ($module === 'purchase') { $newStock = $product->stock + $item['quantity']; $cost = (int) round(($product->stock * $product->cost_cents + $item['quantity'] * $price) / $newStock); DB::table('products')->where('id', $product->id)->update(['stock' => $newStock, 'cost_cents' => $cost, 'updated_at' => now()]); }
                $groupHeadId = DB::table('group_heads')->where('name', $product->group_name)->value('id');
                $newRecordId = DB::table('records')->insertGetId(['reference' => $prefix.'-'.Str::upper(Str::random(10)), 'voucher_reference' => $voucherReference, 'voucher_number' => $voucherNumber, 'type' => $module, 'group_head_id' => $groupHeadId, 'party_id' => $partyId, 'sauda_id' => $linkedSauda?->id, 'sale_voucher_id' => $saleVoucher?->id, 'party' => $party, 'product_id' => $product->id, 'goods_number' => $data['goods_number'], 'quantity' => $item['quantity'], 'base_rate_cents' => $baseRate, 'difference_cents' => $difference, 'unit_price_cents' => $price, 'total_cents' => (int) round($price * (float) $item['quantity']), 'date' => $data['date'], 'gst_percent' => $gstPercent, 'labour_cents' => $isFirstLine ? $labour : 0, 'discount_cents' => $isFirstLine ? $discount : 0, 'commission_cents' => $isFirstLine ? $commission : 0, 'freight_cents' => $isFirstLine ? $freight : 0, 'other_charges_cents' => $isFirstLine ? $others : 0, 'status' => $module === 'sale' ? 'Dispatched' : 'Received', 'created_at' => now(), 'updated_at' => now()]);
                $recordId ??= $newRecordId;
                $isFirstLine = false;
            }
            if ($sauda) { $fulfilled = (float) $sauda->fulfilled_quantity + (float) collect($data['items'])->sum('quantity'); DB::table('saudas')->where('id', $sauda->id)->update(['fulfilled_quantity' => $fulfilled, 'status' => $fulfilled + 0.0001 >= (float) $sauda->quantity ? 'Completed' : 'Partial', 'updated_at' => now()]); }
            foreach ($bundleSaudas as $bundleSauda) {
                $bundleItem = collect($data['items'])->firstWhere('product_id', $bundleSauda->product_id);
                if (! $bundleItem) continue;
                $quantity = (float) $bundleItem['quantity'];
                $fulfilled = (float) $bundleSauda->fulfilled_quantity + $quantity;
                DB::table('saudas')->where('id', $bundleSauda->id)->update(['fulfilled_quantity' => $fulfilled, 'status' => $fulfilled + 0.0001 >= (float) $bundleSauda->quantity ? 'Completed' : 'Partial', 'updated_at' => now()]);
            }
            if ($saleVoucher) {
                foreach ($data['items'] as $item) {
                    $linkedSauda = $bundleByProduct->get($item['product_id']);
                    $saleItem = $saleItemsBySauda->get($linkedSauda?->id);
                    if ($saleItem) DB::table('sale_voucher_items')->where('id', $saleItem->id)->update(['fulfilled_quantity' => (float) $saleItem->fulfilled_quantity + (float) $item['quantity'], 'updated_at' => now()]);
                }
                $freshItems = DB::table('sale_voucher_items')->where('sale_voucher_id', $saleVoucher->id)->get();
                $complete = $freshItems->isNotEmpty() && $freshItems->every(fn ($row) => (float) $row->fulfilled_quantity + 0.0001 >= (float) $row->quantity);
                DB::table('sale_vouchers')->where('id', $saleVoucher->id)->update(['status' => $complete ? 'Completed' : 'Partial', 'updated_at' => now()]);
                if ($saleVoucher->order_id) DB::table('records')->where('id', $saleVoucher->order_id)->update(['status' => $complete ? 'Completed' : 'Dispatch Partial', 'updated_at' => now()]);
            }
        });

        return redirect('/'.$module)->with('success', $module === 'sale' ? 'Sale dispatched and inventory reduced.' : 'Purchase received and inventory increased.')->with('print_url', route('records.print', [$module, $recordId]));
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
