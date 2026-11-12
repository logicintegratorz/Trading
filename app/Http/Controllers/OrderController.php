<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OrderController extends Controller
{
    private function title(string $module): string
    {
        abort_unless(in_array($module, ['purchase-orders', 'sale-orders'], true), 404);
        // The first workflow is a customer Order. The route remains sale-orders
        // internally because it feeds the Sale Sauda and Dispatch workflow.
        return $module === 'purchase-orders' ? 'Purchase Order' : 'Order';
    }

    private function money(string|int|float|null $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) ($value ?? 0), 2), 2, '');
        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $rules = [
            'group_head_id' => ['required', 'integer', 'exists:group_heads,id'],
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'goods_number' => ['nullable', 'string', 'max:60'],
            'basic_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            'gst_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'labour' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            'commission' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            'freight' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            'other_charges' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
        ];
        if ($request->has('items')) {
            $rules += [
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
                'items.*.description' => ['required', 'string', 'max:160'],
                'items.*.weight_kg' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:1000000'],
                'items.*.difference' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
                'items.*.base_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
                'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000'],
                'items.*.pieces' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:100000000'],
                'items.*.bundles' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:100000000'],
                'items.*.rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            ];
        } else {
            $rules += [
                'quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000'],
                'rate' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000000'],
            ];
        }
        return $request->validate($rules);
    }

    private function order(string $module, int $id): object
    {
        $this->title($module);
        $order = DB::table('records')->join('group_heads', 'records.group_head_id', '=', 'group_heads.id')
            ->join('parties', 'records.party_id', '=', 'parties.id')->where('records.type', $module)->where('records.id', $id)
            ->select('records.*', 'group_heads.name as group_head_name', 'parties.name as party_name')->first();
        abort_unless($order, 404);
        return $order;
    }

    /** @return array<string, Collection> */
    private function masters(): array
    {
        return ['groupHeads' => DB::table('group_heads')->orderBy('name')->get(), 'parties' => DB::table('parties')->orderBy('name')->get(), 'products' => DB::table('products')->orderBy('name')->get()];
    }

    private function items(object $order): Collection
    {
        $items = DB::table('order_items')->where('record_id', $order->id)->orderBy('id')->get();
        return $items->isNotEmpty() ? $items : collect([(object) ['product_id' => $order->product_id, 'description' => $order->group_head_name, 'weight_kg' => 0, 'difference_cents' => 0, 'base_rate_cents' => 0, 'quantity' => $order->quantity, 'pieces' => 0, 'bundles' => 0, 'rate_cents' => $order->unit_price_cents, 'amount_cents' => $order->total_cents]]);
    }

    public function create(string $module): View
    {
        $title = $this->title($module); $order = null; $items = collect([(object) []]);
        return view('orders.form', array_merge(compact('module', 'title', 'order', 'items'), $this->masters()));
    }

    public function store(Request $request, string $module): RedirectResponse
    {
        $title = $this->title($module); $id = $this->save($module, $this->validated($request));
        return redirect()->route('orders.show', [$module, $id])->with('success', $title.' created. Receipt and Order Parchi are ready to print.');
    }

    public function show(string $module, int $id): View
    {
        $title = $this->title($module); $order = $this->order($module, $id); $items = $this->items($order);
        $saleSaudaIds = $module === 'sale-orders'
            ? DB::table('saudas')->where('order_id', $id)->orderBy('id')->pluck('id')
            : collect();
        $previousOrder = DB::table('records')->where('type', $module)->where('id', '<', $id)->orderByDesc('id')->first();
        $nextOrder = DB::table('records')->where('type', $module)->where('id', '>', $id)->orderBy('id')->first();
        return view('orders.show', compact('module', 'title', 'order', 'items', 'previousOrder', 'nextOrder', 'saleSaudaIds'));
    }

    public function receipt(string $module, int $id): View
    {
        $title = $this->title($module); $order = $this->order($module, $id); $items = $this->items($order);
        return view('orders.receipt', compact('module', 'title', 'order', 'items'));
    }

    public function parchi(string $module, int $id): View
    {
        $title = $this->title($module); $order = $this->order($module, $id); $items = $this->items($order);
        return view('orders.parchi', compact('module', 'title', 'order', 'items'));
    }

    public function edit(string $module, int $id): View
    {
        $title = $this->title($module); $order = $this->order($module, $id); $items = $this->items($order);
        if ($module === 'sale-orders' && DB::table('saudas')->where('order_id', $id)->exists()) {
            return redirect()->route('orders.show', [$module, $id])->withErrors(['order' => 'This Sale Order is already linked to a Sale Sauda and cannot be changed.']);
        }
        return view('orders.form', array_merge(compact('module', 'title', 'order', 'items'), $this->masters()));
    }

    public function update(Request $request, string $module, int $id): RedirectResponse
    {
        $title = $this->title($module); $this->order($module, $id);
        if ($module === 'sale-orders' && DB::table('saudas')->where('order_id', $id)->exists()) {
            return redirect()->route('orders.show', [$module, $id])->withErrors(['order' => 'This Sale Order is already linked to a Sale Sauda and cannot be changed.']);
        }
        $this->save($module, $this->validated($request), $id);
        return redirect()->route('orders.show', [$module, $id])->with('success', $title.' updated.');
    }

    /** @param array<string, mixed> $data */
    private function save(string $module, array $data, ?int $id = null): int
    {
        return DB::transaction(function () use ($module, $data, $id): int {
            $party = DB::table('parties')->where('id', $data['party_id'])->value('name');
            $group = DB::table('group_heads')->where('id', $data['group_head_id'])->value('name');
            $rawItems = $data['items'] ?? [['description' => $group, 'quantity' => $data['quantity'], 'rate' => $data['rate']]];
            $basicRate = $this->money($data['basic_rate'] ?? 0);
            $items = collect($rawItems)->map(function (array $item) use ($basicRate): array {
                $product = ! empty($item['product_id'])
                    ? DB::table('products')->where('id', $item['product_id'])->select('name', 'weight', 'sale_rate_cents')->first()
                    : null;
                // The KG column on the Order Parchi shows the last product-name value
                // (12x15x20 shows 20). Pcs, however, uses the actual per-piece
                // Inventory Weight (for example 90 KG) in the calculation.
                preg_match_all('/\d+(?:\.\d+)?/', (string) ($product?->name ?? $item['description'] ?? ''), $matches);
                $lastNumber = $matches[0] ? end($matches[0]) : null;
                $weight = $lastNumber !== null ? (float) $lastNumber : (float) ($item['weight_kg'] ?? 0);
                $pieceWeight = (float) ($product?->weight ?? $weight);
                $quantity = (float) $item['quantity'];
                $lineRate = $this->money($item['rate'] ?? 0);
                $rate = $basicRate > 0 ? $basicRate : ($lineRate > 0 ? $lineRate : (int) ($product?->sale_rate_cents ?? 0));
                // Quantity is entered in Ton, so convert it to KG first.
                $pieces = $pieceWeight > 0 ? round(($quantity * 1000) / $pieceWeight, 3) : 0;
                $bundles = round(($quantity * 1000) / 52, 3);
                return ['product_id' => $item['product_id'] ?? null, 'description' => trim($item['description']), 'weight_kg' => $weight, 'difference_cents' => 0, 'base_rate_cents' => $basicRate, 'quantity' => $quantity, 'pieces' => $pieces, 'bundles' => $bundles, 'rate_cents' => $rate, 'amount_cents' => (int) round($rate * $quantity)];
            });
            $subtotal = (int) $items->sum('amount_cents');
            $labour = $this->money($data['labour'] ?? 0); $discount = $this->money($data['discount'] ?? 0); $commission = $this->money($data['commission'] ?? 0); $freight = $this->money($data['freight'] ?? 0); $others = $this->money($data['other_charges'] ?? 0);
            $taxable = max(0, $subtotal + $labour + $commission + $freight + $others - $discount); $gstPercent = (float) ($data['gst_percent'] ?? 0); $gst = (int) round($taxable * $gstPercent / 100); $total = $taxable + $gst;
            $quantity = (float) $items->sum('quantity'); $averageRate = $quantity > 0 ? (int) round($subtotal / $quantity) : 0;
            $fingerprint = hash('sha256', json_encode([
                'type' => $module, 'group_head_id' => $data['group_head_id'], 'party_id' => $data['party_id'], 'date' => $data['date'],
                'goods_number' => mb_strtolower(trim((string) ($data['goods_number'] ?? ''))), 'gst_percent' => $gstPercent,
                'charges' => [$labour, $discount, $commission, $freight, $others], 'items' => $items->values()->all(),
            ], JSON_THROW_ON_ERROR));
            $duplicate = DB::table('records')->where('type', $module)->where('order_fingerprint', $fingerprint);
            if ($id) $duplicate->where('id', '!=', $id);
            if ($duplicate->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'An identical order already exists. Open and edit the existing voucher instead.']);
            $record = ['type' => $module, 'group_head_id' => $data['group_head_id'], 'party_id' => $data['party_id'], 'party' => $party, 'product_id' => null, 'quantity' => $quantity, 'unit_price_cents' => $averageRate, 'basic_rate_cents' => $basicRate, 'total_cents' => $total, 'date' => $data['date'], 'goods_number' => $data['goods_number'] ?? null, 'gst_percent' => $gstPercent, 'labour_cents' => $labour, 'discount_cents' => $discount, 'commission_cents' => $commission, 'freight_cents' => $freight, 'other_charges_cents' => $others, 'status' => 'Open', 'updated_at' => now()];
            $record['order_fingerprint'] = $fingerprint;
            if ($id) { DB::table('records')->where('id', $id)->update($record); DB::table('order_items')->where('record_id', $id)->delete(); }
            else { $prefix = $module === 'purchase-orders' ? 'PO' : 'SO'; $record['reference'] = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)); $record['created_at'] = now(); $id = DB::table('records')->insertGetId($record); }
            foreach ($items as $item) DB::table('order_items')->insert($item + ['record_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            return $id;
        });
    }

    public function move(Request $request, string $module, int $id): RedirectResponse
    {
        $this->order($module, $id); $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]); DB::table('records')->where('id', $id)->update(['date' => $data['date'], 'updated_at' => now()]);
        return back()->with('success', 'Voucher moved to '.date('d M Y', strtotime($data['date'])).'.');
    }

    public function createSale(string $module, int $id): RedirectResponse
    {
        abort_unless($module === 'sale-orders', 404);
        $order = $this->order($module, $id);
        $items = $this->items($order);
        if ($items->contains(fn (object $item) => empty($item->product_id))) {
            return back()->withErrors(['order' => 'Every order line needs a selected inventory product before a Sale can be created.']);
        }

        $ids = DB::transaction(function () use ($order, $items): array {
            $existing = DB::table('saudas')->where('order_id', $order->id)->orderBy('id')->pluck('id')->all();
            if ($existing) return $existing;
            $now = now();
            foreach ($items as $item) {
                DB::table('saudas')->insert([
                    'reference' => 'SDA-'.Str::upper(Str::random(10)), 'type' => 'sale', 'party_id' => $order->party_id,
                    'product_id' => $item->product_id, 'order_id' => $order->id, 'order_item_id' => $item->id,
                    'quantity' => $item->quantity, 'fulfilled_quantity' => 0, 'rate_cents' => $item->rate_cents,
                    'date' => $order->date, 'status' => 'Open', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            return DB::table('saudas')->where('order_id', $order->id)->orderBy('id')->pluck('id')->all();
        });

        return redirect()->route('saudas.bundle.show', ['ids' => implode(',', $ids)])
            ->with('success', 'Sale created from this Order. Select any ordered product lines and dispatch only the quantities required.');
    }

    public function destroy(string $module, int $id): RedirectResponse
    {
        $this->order($module, $id);
        if (DB::table('saudas')->where('order_id', $id)->exists()) {
            return back()->withErrors(['order' => 'This order is linked to a Sale Sauda and cannot be deleted.']);
        }
        DB::table('records')->where('id', $id)->delete();
        return redirect()->route('module', $module)->with('success', 'Order deleted.');
    }
}
