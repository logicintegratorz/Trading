<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SaleVoucherController
{
    public function index(): View
    {
        $sales = DB::table('sale_vouchers as v')->join('parties as p', 'v.party_id', '=', 'p.id')
            ->select('v.*', 'p.name as party_name')->orderByDesc('v.id')->paginate(20);
        return view('sales.index', compact('sales'));
    }

    public function create(Request $request): View
    {
        $ids = collect(explode(',', (string) $request->query('sauda_ids')))
            ->filter(fn ($id) => ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)->unique()->values();
        $lines = $ids->isEmpty() ? collect() : $this->saudaLines($ids);
        return view('sales.form', ['sale' => null, 'lines' => $lines]);
    }

    public function store(Request $request): RedirectResponse
    {
        $saleId = $this->save($request);
        if ($request->input('workflow_action') === 'dispatch') {
            $this->approveSale($saleId);
            return redirect()->route('stock-entry.create', ['module' => 'sale', 'sale_id' => $saleId])
                ->with('success', 'Sale approved. Select the quantities to dispatch.');
        }
        return redirect()->route('sales.show', $saleId)->with('success', 'Sale Voucher saved as Draft. Approve it before dispatch.');
    }

    public function show(int $id): View
    {
        $sale = $this->sale($id); $lines = $this->items($id);
        return view('sales.show', compact('sale', 'lines'));
    }

    public function edit(int $id): View
    {
        $sale = $this->sale($id);
        if ($sale->status !== 'Draft') return redirect()->route('sales.show', $id)->withErrors(['sale' => 'Only a Draft Sale Voucher can be edited.']);
        return view('sales.form', ['sale' => $sale, 'lines' => $this->items($id)]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $sale = $this->sale($id);
        if ($sale->status !== 'Draft') throw ValidationException::withMessages(['sale' => 'Only a Draft Sale Voucher can be edited.']);
        $this->save($request, $id);
        if ($request->input('workflow_action') === 'dispatch') {
            $this->approveSale($id);
            return redirect()->route('stock-entry.create', ['module' => 'sale', 'sale_id' => $id])
                ->with('success', 'Sale approved. Select the quantities to dispatch.');
        }
        return redirect()->route('sales.show', $id)->with('success', 'Sale Voucher updated.');
    }

    public function approve(int $id): RedirectResponse
    {
        $sale = $this->sale($id);
        if ($sale->status !== 'Draft') return back()->withErrors(['sale' => 'Only Draft Sale Vouchers can be approved.']);
        $this->approveSale($id);
        return back()->with('success', 'Sale Voucher approved and ready for dispatch.');
    }

    private function approveSale(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $sale = DB::table('sale_vouchers')->where('id', $id)->lockForUpdate()->first();
            abort_unless($sale, 404);
            if ($sale->status !== 'Draft') throw ValidationException::withMessages(['sale' => 'Only Draft Sale Vouchers can be approved.']);
            $items = DB::table('sale_voucher_items')->where('sale_voucher_id', $id)->lockForUpdate()->get();
            foreach ($items as $item) {
                $sauda = DB::table('saudas')->where('id', $item->sauda_id)->lockForUpdate()->first();
                abort_unless($sauda, 404);
                $alreadyAllocated = (float) DB::table('sale_voucher_items as i')
                    ->join('sale_vouchers as v', 'v.id', '=', 'i.sale_voucher_id')
                    ->where('i.sauda_id', $item->sauda_id)->where('i.sale_voucher_id', '!=', $id)
                    ->whereIn('v.status', ['Approved', 'Partial'])->sum('i.quantity');
                $available = (float) $sauda->quantity - (float) $sauda->fulfilled_quantity - $alreadyAllocated;
                if ((float) $item->quantity > $available + 0.0001) {
                    throw ValidationException::withMessages(['sale' => 'Sale Voucher quantity exceeds the remaining Sauda balance for one or more products.']);
                }
            }
            DB::table('sale_vouchers')->where('id', $id)->update(['status' => 'Approved', 'updated_at' => now()]);
            if ($sale->order_id) DB::table('records')->where('id', $sale->order_id)->update(['status' => 'Sale Approved', 'updated_at' => now()]);
        });
    }

    public function destroy(int $id): RedirectResponse
    {
        $sale = $this->sale($id);
        if ($sale->status !== 'Draft' || DB::table('records')->where('sale_voucher_id', $id)->exists()) {
            return back()->withErrors(['sale' => 'Only an unused Draft Sale Voucher can be deleted.']);
        }
        DB::table('sale_vouchers')->where('id', $id)->delete();
        return redirect()->route('sales.index')->with('success', 'Sale Voucher deleted.');
    }

    private function sale(int $id): object
    {
        $sale = DB::table('sale_vouchers as v')->join('parties as p', 'v.party_id', '=', 'p.id')->where('v.id', $id)->select('v.*', 'p.name as party_name')->first();
        abort_unless($sale, 404); return $sale;
    }

    private function items(int $saleId)
    {
        return DB::table('sale_voucher_items as i')->join('saudas as s', 'i.sauda_id', '=', 's.id')->join('products as p', 'i.product_id', '=', 'p.id')
            ->where('i.sale_voucher_id', $saleId)->select('i.*', 's.reference as sauda_reference', 'p.name as product_name', 'p.group_name')->get();
    }

    private function saudaLines($ids)
    {
        $lines = DB::table('saudas as s')->join('products as p', 's.product_id', '=', 'p.id')->whereIn('s.id', $ids)->where('s.type', 'sale')
            ->whereIn('s.status', ['Open', 'Partial'])->select('s.*', 's.reference as sauda_reference', 'p.name as product_name', 'p.group_name')->get();
        if ($lines->count() !== $ids->count() || $lines->pluck('party_id')->unique()->count() !== 1) abort(422, 'Select open Sale Sauda lines for one customer.');
        return $lines;
    }

    private function save(Request $request, ?int $id = null): int
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'goods_number' => ['nullable', 'string', 'max:60'], 'items' => ['required', 'array', 'min:1'], 'items.*.sauda_id' => ['required', 'integer'], 'items.*.quantity' => ['required', 'numeric', 'min:0.001']]);
        $requestedSaudaIds = collect($data['items'])->pluck('sauda_id')->map(fn ($value) => (int) $value);
        if ($requestedSaudaIds->count() !== $requestedSaudaIds->unique()->count()) {
            throw ValidationException::withMessages(['items' => 'The same product line cannot be added twice to a Sale Voucher.']);
        }
        return DB::transaction(function () use ($data, $id, $requestedSaudaIds): int {
            $ids = $requestedSaudaIds->unique()->values();
            $lines = $this->saudaLines($ids);
            if ($lines->pluck('product_id')->unique()->count() !== $lines->count()) {
                throw ValidationException::withMessages(['items' => 'The same product cannot be included twice in a Sale Voucher. Combine its quantity into one line.']);
            }
            foreach ($data['items'] as $item) {
                $sauda = $lines->firstWhere('id', (int) $item['sauda_id']);
                if ((float) $item['quantity'] > ((float) $sauda->quantity - (float) $sauda->fulfilled_quantity) + 0.0001) throw ValidationException::withMessages(['items' => 'Sale quantity cannot exceed the respective Sauda balance.']);
            }
            $attributes = ['party_id' => $lines->first()->party_id, 'date' => $data['date'], 'goods_number' => $data['goods_number'] ?? null, 'updated_at' => now()];
            if ($id) { DB::table('sale_vouchers')->where('id', $id)->update($attributes); DB::table('sale_voucher_items')->where('sale_voucher_id', $id)->delete(); }
            else { $attributes += ['reference' => 'SAL-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)), 'order_id' => $lines->pluck('order_id')->filter()->unique()->count() === 1 ? $lines->pluck('order_id')->filter()->first() : null, 'status' => 'Draft', 'created_at' => now()]; $id = DB::table('sale_vouchers')->insertGetId($attributes); }
            foreach ($data['items'] as $item) { $sauda = $lines->firstWhere('id', (int) $item['sauda_id']); DB::table('sale_voucher_items')->insert(['sale_voucher_id' => $id, 'sauda_id' => $sauda->id, 'product_id' => $sauda->product_id, 'quantity' => $item['quantity'], 'fulfilled_quantity' => 0, 'rate_cents' => $sauda->rate_cents, 'created_at' => now(), 'updated_at' => now()]); }
            $orderId = $attributes['order_id'] ?? DB::table('sale_vouchers')->where('id', $id)->value('order_id');
            if ($orderId) DB::table('records')->where('id', $orderId)->update(['status' => 'Sale Draft', 'updated_at' => now()]);
            return $id;
        });
    }
}
