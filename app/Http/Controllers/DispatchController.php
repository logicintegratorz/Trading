<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DispatchController
{
    /** @return array{search:string,from:string,to:string,productId:?int} */
    private function filters(Request $request): array
    {
        return [
            'search' => is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 120) : '',
            'from' => is_string($request->query('from')) ? $request->query('from') : '',
            'to' => is_string($request->query('to')) ? $request->query('to') : '',
            'productId' => is_string($request->query('product_id')) && ctype_digit($request->query('product_id')) ? (int) $request->query('product_id') : null,
        ];
    }

    public function index(Request $request): View
    {
        ['search' => $search, 'from' => $from, 'to' => $to, 'productId' => $productId] = $this->filters($request);
        $query = DB::table('records as r')->join('products as p', 'r.product_id', '=', 'p.id')->leftJoin('saudas as s', 'r.sauda_id', '=', 's.id')
            ->where('r.type', 'sale')->select('r.*', 'p.name as product_name', 'p.sku', 'p.unit', 's.reference as sauda_reference');
        if ($search !== '') {
            $query->where(fn ($builder) => $builder->where('r.reference', 'like', '%'.$search.'%')->orWhere('r.party', 'like', '%'.$search.'%')->orWhere('p.name', 'like', '%'.$search.'%'));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $query->whereDate('r.date', '>=', $from);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $query->whereDate('r.date', '<=', $to);
        }
        if ($productId) {
            $query->where('p.id', $productId);
        }
        $dispatches = $query->orderByDesc('r.date')->orderByDesc('r.id')->paginate(20)->withQueryString();
        $products = DB::table('products')->orderBy('name')->get();
        $totals = (object) ['records' => $dispatches->total(), 'quantity' => $dispatches->sum('quantity'), 'value' => $dispatches->sum('total_cents')];

        return view('dispatch.index', compact('dispatches', 'products', 'totals', 'search', 'from', 'to', 'productId'));
    }

    public function print(Request $request): View
    {
        ['search' => $search, 'from' => $from, 'to' => $to, 'productId' => $productId] = $this->filters($request);
        $query = DB::table('saudas as s')->join('parties as pa', 's.party_id', '=', 'pa.id')->join('products as p', 's.product_id', '=', 'p.id')
            ->where('s.type', 'sale')->select('s.*', 'pa.name as party_name', 'p.name as product_name');
        if ($search !== '') $query->where(fn ($builder) => $builder->where('s.reference', 'like', '%'.$search.'%')->orWhere('pa.name', 'like', '%'.$search.'%')->orWhere('p.name', 'like', '%'.$search.'%'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $query->whereDate('s.date', '>=', $from);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $query->whereDate('s.date', '<=', $to);
        if ($productId) $query->where('p.id', $productId);
        $rows = $query->orderBy('pa.name')->orderBy('s.date')->get();

        return view('dispatch.print', compact('rows', 'from', 'to'));
    }
}
