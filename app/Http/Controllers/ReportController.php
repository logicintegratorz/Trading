<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController
{
    public const REPORTS = [
        'po' => ['title' => 'PO Report', 'description' => 'Purchase order vouchers, suppliers and values.', 'icon' => 'PO'],
        'so' => ['title' => 'SO Report', 'description' => 'Sale order vouchers, parties and values.', 'icon' => 'SO'],
        'size-purchase' => ['title' => 'Size Purchase Report', 'description' => 'Purchased products grouped by size and product details.', 'icon' => 'P↙'],
        'size-sale' => ['title' => 'Size Sale Report', 'description' => 'Sold products grouped by size and product details.', 'icon' => 'S↗'],
        'inventory' => ['title' => 'Inventory Report', 'description' => 'Current stock, minimum levels and inventory valuation.', 'icon' => '▦'],
        'stock-alert' => ['title' => 'Stock Alert Report', 'description' => 'Products currently at or below their minimum quantity.', 'icon' => '!'],
    ];

    public function index(): View
    {
        $counts = [
            'po' => DB::table('records')->where('type', 'purchase-orders')->count(),
            'so' => DB::table('records')->where('type', 'sale-orders')->count(),
            'size-purchase' => DB::table('records')->where('type', 'purchase')->count(),
            'size-sale' => DB::table('records')->where('type', 'sale')->count(),
            'inventory' => DB::table('products')->count(),
            'stock-alert' => DB::table('products')->whereColumn('stock', '<=', 'reorder_level')->count(),
        ];

        return view('reports.index', ['reports' => self::REPORTS, 'counts' => $counts]);
    }

    public function show(Request $request, string $report): View
    {
        abort_unless(isset(self::REPORTS[$report]), 404);

        $filters = $this->filters($request);
        $query = $this->query($report);
        $this->applyFilters($query, $report, $filters);

        $summaryQuery = clone $query;
        $summary = (object) [
            'records' => (clone $summaryQuery)->count(),
            'quantity' => $this->isStockReport($report) ? (float) (clone $summaryQuery)->sum('p.stock') : (float) (clone $summaryQuery)->sum('r.quantity'),
            'value' => $this->isStockReport($report)
                ? (float) (clone $summaryQuery)->sum(DB::raw('p.stock * p.cost_cents'))
                : (float) (clone $summaryQuery)->sum('r.total_cents'),
        ];

        $rows = $query->orderBy($this->isStockReport($report) ? 'p.name' : 'r.date', $this->isStockReport($report) ? 'asc' : 'desc')
            ->paginate(20)->withQueryString();

        $groups = DB::table('products')->select('group_name')->distinct()->orderBy('group_name')->pluck('group_name');
        $parties = DB::table('parties')->orderBy('name')->get();
        $products = DB::table('products')->orderBy('name')->get();

        return view('reports.show', compact('report', 'rows', 'summary', 'filters', 'groups', 'parties', 'products'))
            ->with('meta', self::REPORTS[$report]);
    }

    /** @return array{q:string,from:string,to:string,group:string,party_id:string,product_id:string,stock_status:string} */
    private function filters(Request $request): array
    {
        $text = static fn (mixed $value, int $length): string => is_string($value) ? mb_substr(trim($value), 0, $length) : '';

        return [
            'q' => $text($request->query('q'), 120),
            'from' => $text($request->query('from'), 10),
            'to' => $text($request->query('to'), 10),
            'group' => $text($request->query('group'), 80),
            'party_id' => $text($request->query('party_id'), 20),
            'product_id' => $text($request->query('product_id'), 20),
            'stock_status' => in_array($request->query('stock_status'), ['healthy', 'low'], true) ? $request->query('stock_status') : '',
        ];
    }

    private function query(string $report): Builder
    {
        if ($this->isStockReport($report)) {
            $query = DB::table('products as p')->select('p.*');
            if ($report === 'stock-alert') {
                $query->whereColumn('p.stock', '<=', 'p.reorder_level');
            }

            return $query;
        }

        $query = DB::table('records as r');
        if (in_array($report, ['po', 'so'], true)) {
            return $query->leftJoin('group_heads as g', 'r.group_head_id', '=', 'g.id')
                ->leftJoin('parties as pa', 'r.party_id', '=', 'pa.id')
                ->where('r.type', $report === 'po' ? 'purchase-orders' : 'sale-orders')
                ->select('r.*', 'g.name as group_name', 'pa.name as master_party_name');
        }

        return $query->join('products as p', 'r.product_id', '=', 'p.id')
            ->where('r.type', $report === 'size-purchase' ? 'purchase' : 'sale')
            ->select('r.*', 'p.name as product_name', 'p.sku', 'p.group_name', 'p.weight', 'p.unit');
    }

    /** @param array<string,string> $filters */
    private function applyFilters(Builder $query, string $report, array $filters): void
    {
        if ($this->isStockReport($report)) {
            if ($filters['q'] !== '') {
                $query->where(fn (Builder $q) => $q->where('p.name', 'like', '%'.$filters['q'].'%')->orWhere('p.sku', 'like', '%'.$filters['q'].'%'));
            }
            if ($filters['group'] !== '') {
                $query->where('p.group_name', $filters['group']);
            }
            if ($filters['product_id'] !== '' && ctype_digit($filters['product_id'])) {
                $query->where('p.id', (int) $filters['product_id']);
            }
            if ($report === 'inventory' && $filters['stock_status'] !== '') {
                $operator = $filters['stock_status'] === 'healthy' ? '>' : '<=';
                $query->whereColumn('p.stock', $operator, 'p.reorder_level');
            }

            return;
        }

        if ($filters['q'] !== '') {
            $query->where(fn (Builder $q) => $q->where('r.reference', 'like', '%'.$filters['q'].'%')->orWhere('r.party', 'like', '%'.$filters['q'].'%'));
        }
        if ($filters['from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
            $query->whereDate('r.date', '>=', $filters['from']);
        }
        if ($filters['to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
            $query->whereDate('r.date', '<=', $filters['to']);
        }
        if ($filters['party_id'] !== '' && ctype_digit($filters['party_id'])) {
            $query->where('r.party_id', (int) $filters['party_id']);
        }
        if ($filters['group'] !== '') {
            $query->where(in_array($report, ['po', 'so'], true) ? 'g.name' : 'p.group_name', $filters['group']);
        }
        if (in_array($report, ['size-purchase', 'size-sale'], true) && $filters['product_id'] !== '' && ctype_digit($filters['product_id'])) {
            $query->where('p.id', (int) $filters['product_id']);
        }
    }

    private function isStockReport(string $report): bool
    {
        return in_array($report, ['inventory', 'stock-alert'], true);
    }
}
