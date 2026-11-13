<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SaudaController
{
    public function index(Request $request): View
    {
        $type = in_array($request->query('type'), ['purchase', 'sale'], true) ? $request->query('type') : '';
        $status = in_array($request->query('status'), ['Open', 'Partial', 'Completed'], true) ? $request->query('status') : '';
        $search = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 120) : '';
        $query = DB::table('saudas as s')->join('parties as pa', 's.party_id', '=', 'pa.id')->join('products as p', 's.product_id', '=', 'p.id')
            ->select('s.*', 'pa.name as party_name', 'p.group_name as group_head_name', 'p.unit')
            ->selectRaw("(SELECT v.id FROM sale_voucher_items AS i JOIN sale_vouchers AS v ON v.id = i.sale_voucher_id WHERE i.sauda_id = s.id AND v.status IN ('Draft', 'Approved', 'Partial') ORDER BY v.id DESC LIMIT 1) AS sale_voucher_id");
        if ($type !== '') {
            $query->where('s.type', $type);
        }
        if ($status !== '') {
            $query->where('s.status', $status);
        }
        if ($search !== '') {
            $query->where(fn ($builder) => $builder->where('s.reference', 'like', '%'.$search.'%')->orWhere('pa.name', 'like', '%'.$search.'%')->orWhere('p.group_name', 'like', '%'.$search.'%'));
        }

        $allSaudas = $query->orderByDesc('s.date')->orderByDesc('s.id')->get();
        // A direct Sale can contain several product lines. Those lines are created
        // together and must be displayed as one Sale in the register.
        $displaySaudas = $allSaudas->groupBy(function ($sauda) {
            return $sauda->type === 'sale'
                ? ($sauda->order_id ? 'order-'.$sauda->order_id : 'sale-'.$sauda->party_id.'-'.$sauda->date.'-'.$sauda->created_at)
                : 'single-'.$sauda->id;
        })->map(function ($lines) {
            $sauda = $lines->first();
            $sauda->line_count = $lines->count();
            $sauda->line_ids = $lines->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            if ($sauda->type === 'sale' && $lines->count() > 1) {
                $sauda->quantity = $lines->sum('quantity');
                $sauda->fulfilled_quantity = $lines->sum('fulfilled_quantity');
                $sauda->group_head_name = $lines->pluck('group_head_name')->unique()->implode(', ');
                $sauda->rate_cents = null;
                $sauda->status = $lines->contains(fn ($line) => $line->status === 'Partial') ? 'Partial' : ($lines->every(fn ($line) => $line->status === 'Completed') ? 'Completed' : 'Open');
            }

            return $sauda;
        })->values();
        $perPage = 20;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $saudas = new LengthAwarePaginator(
            $displaySaudas->forPage($page, $perPage)->values(),
            $displaySaudas->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        $pendingTotals = DB::table('saudas')
            ->whereIn('status', ['Open', 'Partial'])
            ->selectRaw("type, COUNT(*) as count, COALESCE(SUM(quantity - fulfilled_quantity), 0) as balance")
            ->groupBy('type')->get()->keyBy('type');
        $pendingSaleLines = DB::table('saudas')
            ->where('type', 'sale')->whereIn('status', ['Open', 'Partial'])
            ->get(['order_id', 'party_id', 'date', 'created_at', 'quantity', 'fulfilled_quantity']);
        $pendingSaleDeals = $pendingSaleLines->groupBy(fn ($sauda) => $sauda->order_id ? 'order-'.$sauda->order_id : $sauda->party_id.'|'.$sauda->date.'|'.$sauda->created_at);
        $pendingTotals->put('sale', (object) [
            'count' => $pendingSaleDeals->count(),
            'balance' => $pendingSaleDeals->sum(fn ($lines) => $lines->sum('quantity') - $lines->sum('fulfilled_quantity')),
        ]);

        return view('saudas.index', compact('saudas', 'type', 'status', 'search', 'pendingTotals'));
    }

    public function create(): View
    {
        return $this->formView();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateSauda($request);
        $this->ensureUniqueSauda($data);
        DB::table('saudas')->insert($this->attributes($data) + [
            'reference' => 'SDA-'.Str::upper(Str::random(10)),
            'fulfilled_quantity' => 0,
            'status' => 'Open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('saudas.index')->with('success', 'Sauda created. It is now available for the matching Purchase or Sale entry.');
    }

    public function edit(int $id): View
    {
        $sauda = DB::table('saudas')->where('id', $id)->first();
        abort_unless($sauda, 404);

        return $this->formView($sauda);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $sauda = DB::table('saudas')->where('id', $id)->first();
        abort_unless($sauda, 404);
        if ($sauda->status === 'Completed') {
            throw ValidationException::withMessages(['sauda' => 'A completed Sauda cannot be edited.']);
        }
        $data = $this->validateSauda($request);

        if ((float) $sauda->fulfilled_quantity > 0) {
            $errors = [];
            if ($data['type'] !== $sauda->type) {
                $errors['type'] = 'Sauda type cannot be changed after an entry has been recorded.';
            }
            if ((int) $data['party_id'] !== (int) $sauda->party_id) {
                $errors['party_id'] = 'Party cannot be changed after an entry has been recorded.';
            }
            if ((int) $data['product_id'] !== (int) $sauda->product_id) {
                $errors['group_head_id'] = 'Group Head cannot be changed after an entry has been recorded.';
            }
            if ((float) $data['quantity'] < (float) $sauda->fulfilled_quantity) {
                $errors['quantity'] = 'Committed quantity cannot be lower than the already used quantity of '.number_format($sauda->fulfilled_quantity, 3).' Ton.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
        $this->ensureUniqueSauda($data, $id);
        DB::table('saudas')->where('id', $id)->update($this->attributes($data) + ['updated_at' => now()]);

        return redirect()->route('saudas.index')->with('success', 'Sauda updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $sauda = DB::table('saudas')->where('id', $id)->first();
        abort_unless($sauda, 404);
        if (DB::table('records')->where('sauda_id', $id)->exists() || (float) $sauda->fulfilled_quantity > 0) {
            throw ValidationException::withMessages(['sauda' => 'This Sauda has transactions and cannot be deleted.']);
        }
        DB::table('saudas')->where('id', $id)->delete();

        return redirect()->route('saudas.index')->with('success', 'Unused Sauda deleted.');
    }

    /** Display every product line that belongs to a consolidated pending Sale entry. */
    public function showBundle(Request $request): View
    {
        $ids = $this->bundleIds($request);
        $saudas = DB::table('saudas as s')
            ->join('parties as pa', 's.party_id', '=', 'pa.id')
            ->join('products as p', 's.product_id', '=', 'p.id')
            ->whereIn('s.id', $ids)
            ->select('s.*', 'pa.name as party_name', 'p.name as product_name', 'p.group_name as group_head_name', 'p.unit')
            ->orderBy('s.id')
            ->get();

        abort_unless($saudas->count() === $ids->count(), 404);

        return view('saudas.bundle', [
            'saudas' => $saudas,
            'ids' => $ids->implode(','),
            'isSaleBundle' => $saudas->every(fn ($sauda) => $sauda->type === 'sale'),
        ]);
    }

    /** Delete all unused product lines in a consolidated pending Sale entry. */
    public function destroyBundle(Request $request): RedirectResponse
    {
        $ids = $this->bundleIds($request);
        $saudas = DB::table('saudas')->whereIn('id', $ids)->get(['id', 'fulfilled_quantity']);
        abort_unless($saudas->count() === $ids->count(), 404);

        $hasTransactions = DB::table('records')->whereIn('sauda_id', $ids)->exists();
        $hasUsedLines = $saudas->contains(fn ($sauda) => (float) $sauda->fulfilled_quantity > 0);
        if ($hasTransactions || $hasUsedLines) {
            throw ValidationException::withMessages(['sauda' => 'This entry has dispatched/received quantity and cannot be deleted as a bundle. Delete is available only for fully unused lines.']);
        }

        DB::table('saudas')->whereIn('id', $ids)->delete();

        return redirect()->route('module', 'sale')->with('success', 'All unused product lines in the pending Sale entry were deleted.');
    }

    private function formView(?object $sauda = null): View
    {
        $parties = DB::table('parties')->orderBy('name')->get();
        $groupHeads = DB::table('group_heads')->orderBy('name')->get();
        $selectedGroupHeadId = null;
        if ($sauda) {
            $groupName = DB::table('products')->where('id', $sauda->product_id)->value('group_name');
            $selectedGroupHeadId = DB::table('group_heads')->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $groupName)])->value('id');
        }

        return view('saudas.form', compact('sauda', 'parties', 'groupHeads', 'selectedGroupHeadId'));
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function bundleIds(Request $request)
    {
        $rawIds = (string) $request->input('ids', '');
        $parts = array_filter(explode(',', $rawIds), fn ($value) => $value !== '');
        abort_if($parts === [] || count($parts) > 100 || collect($parts)->contains(fn ($id) => ! ctype_digit($id)), 404);

        $ids = collect($parts)->map(fn ($id) => (int) $id)->unique()->values();
        abort_if($ids->count() !== count($parts), 404);

        return $ids;
    }

    /** @return array{type:string,party_id:int,product_id:int,quantity:string,rate:string,date:string} */
    private function validateSauda(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['purchase', 'sale'])],
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'group_head_id' => ['required', 'integer', 'exists:group_heads,id'],
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000'],
            'rate' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000000'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $groupName = DB::table('group_heads')->where('id', $data['group_head_id'])->value('name');
        $products = DB::table('products')->whereRaw('LOWER(group_name) = ?', [mb_strtolower((string) $groupName)])->orderBy('id')->get(['id']);

        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['group_head_id' => 'No product exists under this Group Head. Add a product first.']);
        }
        return [
            'type' => $data['type'],
            'party_id' => $data['party_id'],
            'product_id' => $products->first()->id,
            'quantity' => $data['quantity'],
            'rate' => $data['rate'],
            'date' => $data['date'],
        ];
    }

    /** @param array{type:string,party_id:int,product_id:int,quantity:string,rate:string,date:string} $data */
    private function attributes(array $data): array
    {
        return [
            'type' => $data['type'],
            'party_id' => $data['party_id'],
            'product_id' => $data['product_id'],
            'quantity' => $data['quantity'],
            'rate_cents' => $this->money($data['rate']),
            'date' => $data['date'],
        ];
    }

    private function money(string|int|float $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** @param array{type:string,party_id:int,product_id:int,quantity:string,rate:string,date:string} $data */
    private function ensureUniqueSauda(array $data, ?int $ignoreId = null): void
    {
        $query = DB::table('saudas')->where('type', $data['type'])->where('party_id', $data['party_id'])
            ->where('product_id', $data['product_id'])->where('date', $data['date'])
            ->where('quantity', $data['quantity'])->where('rate_cents', $this->money($data['rate']));
        if ($ignoreId) $query->where('id', '!=', $ignoreId);
        if ($query->exists()) throw ValidationException::withMessages(['quantity' => 'An identical Sauda already exists for this party, product, date and rate.']);
    }
}
