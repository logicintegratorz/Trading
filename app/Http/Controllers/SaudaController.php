<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->select('s.*', 'pa.name as party_name', 'p.name as product_name', 'p.unit');
        if ($type !== '') {
            $query->where('s.type', $type);
        }
        if ($status !== '') {
            $query->where('s.status', $status);
        }
        if ($search !== '') {
            $query->where(fn ($builder) => $builder->where('s.reference', 'like', '%'.$search.'%')->orWhere('pa.name', 'like', '%'.$search.'%')->orWhere('p.name', 'like', '%'.$search.'%'));
        }

        $saudas = $query->orderByDesc('s.date')->orderByDesc('s.id')->paginate(20)->withQueryString();

        return view('saudas.index', compact('saudas', 'type', 'status', 'search'));
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
        if ((float) $sauda->fulfilled_quantity > 0) {
            throw ValidationException::withMessages(['sauda' => 'A partially or fully used Sauda cannot be edited.']);
        }
        $data = $this->validateSauda($request);
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

    private function formView(?object $sauda = null): View
    {
        $parties = DB::table('parties')->orderBy('name')->get();
        $products = DB::table('products')->orderBy('name')->get();

        return view('saudas.form', compact('sauda', 'parties', 'products'));
    }

    /** @return array{type:string,party_id:int,product_id:int,quantity:string,rate:string,date:string} */
    private function validateSauda(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['purchase', 'sale'])],
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000'],
            'rate' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000000'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
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
