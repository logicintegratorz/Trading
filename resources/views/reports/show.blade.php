@extends('layout')
@section('title', $meta['title'])
@section('content')
@php($isStock = in_array($report, ['inventory', 'stock-alert'], true))
@php($isOrder = in_array($report, ['po', 'so'], true))
<section class="page-heading report-heading"><div><div class="eyebrow">REPORTS / {{ strtoupper($meta['title']) }}</div><h1>{{ $meta['title'] }}<span class="accent">.</span></h1><p>{{ $meta['description'] }}</p></div><a class="button" href="{{ route('reports.index') }}">← All reports</a></section>

<section class="report-summary">
    <div><span>Filtered records</span><strong>{{ number_format($summary->records) }}</strong></div>
    <div><span>{{ $isStock ? 'Stock quantity' : 'Total quantity' }}</span><strong>{{ rtrim(rtrim(number_format($summary->quantity, 3), '0'), '.') }} <small>Ton</small></strong></div>
    <div><span>{{ $isStock ? 'Stock value' : 'Total value' }}</span><strong>₹{{ number_format($summary->value / 100, 2) }}</strong></div>
</section>

<section class="panel report-panel">
    <form class="report-filters" method="get" data-auto-filter>
        <label class="filter-search"><span>Search</span><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Reference, party, product or SKU"></label>
        @unless($isStock)<label><span>From date</span><input type="date" name="from" value="{{ $filters['from'] }}"></label><label><span>To date</span><input type="date" name="to" value="{{ $filters['to'] }}"></label>@endunless
        <label><span>Group</span><select name="group"><option value="">All groups</option>@foreach($groups as $group)<option value="{{ $group }}" @selected($filters['group'] === $group)>{{ $group }}</option>@endforeach</select></label>
        @unless($isStock)<label><span>{{ $report === 'po' ? 'Supplier' : 'Party' }}</span><select name="party_id"><option value="">All {{ $report === 'po' ? 'suppliers' : 'parties' }}</option>@foreach($parties as $party)<option value="{{ $party->id }}" @selected((string) $filters['party_id'] === (string) $party->id)>{{ $party->name }}</option>@endforeach</select></label>@endunless
        @if(!$isOrder)<label><span>Product</span><select name="product_id"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) $filters['product_id'] === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></label>@endif
        @if($report === 'inventory')<label><span>Stock status</span><select name="stock_status"><option value="">All stock</option><option value="healthy" @selected($filters['stock_status'] === 'healthy')>Above minimum</option><option value="low" @selected($filters['stock_status'] === 'low')>At / below minimum</option></select></label>@endif
        <button class="button primary" type="submit" data-filter-submit>Apply filters</button>
        @if(collect($filters)->filter()->isNotEmpty())<a class="button" href="{{ route('reports.show', $report) }}">Clear</a>@endif
    </form>

    <div class="table-scroll"><table class="report-table"><thead><tr>
        @if($isStock)<th>SKU / Product</th><th>Group / Size</th><th class="numeric">Stock</th><th class="numeric">Minimum</th><th class="numeric">Rate</th><th class="numeric">Value</th><th>Status</th>
        @elseif($isOrder)<th>Voucher S.No</th><th>{{ $report === 'po' ? 'Supplier' : 'Party' }}</th><th>Group Head</th><th>Date</th><th class="numeric">QTY</th><th class="numeric">Rate</th><th class="numeric">Amount</th><th>Status</th>
        @else<th>Reference</th><th>Party</th><th>Product / SKU</th><th>Group / Size</th><th>Date</th><th class="numeric">QTY</th><th class="numeric">Rate</th><th class="numeric">Amount</th>
        @endif
    </tr></thead><tbody>
    @forelse($rows as $row)<tr>
        @if($isStock)
            <td><strong>{{ $row->name }}</strong><small>{{ $row->sku }}</small></td><td>{{ $row->group_name }}<small>{{ rtrim(rtrim(number_format($row->weight, 3), '0'), '.') }} kg</small></td><td class="numeric"><strong>{{ rtrim(rtrim(number_format($row->stock, 3), '0'), '.') }} Ton</strong></td><td class="numeric">{{ rtrim(rtrim(number_format($row->reorder_level, 3), '0'), '.') }} Ton</td><td class="numeric">₹{{ number_format($row->cost_cents / 100, 2) }}</td><td class="numeric">₹{{ number_format(($row->stock * $row->cost_cents) / 100, 2) }}</td><td><span class="stock-indicator {{ $row->stock > $row->reorder_level ? 'stock-ok' : 'stock-low' }}">{{ $row->stock > $row->reorder_level ? '✓ Healthy' : '⚠ Low' }}</span></td>
        @elseif($isOrder)
            <td><a href="{{ route('orders.show', [$report === 'po' ? 'purchase-orders' : 'sale-orders', $row->id]) }}"><strong>{{ $row->reference }}</strong></a></td><td>{{ $row->master_party_name ?? $row->party }}</td><td>{{ $row->group_name ?? '—' }}</td><td>{{ date('d M Y', strtotime($row->date)) }}</td><td class="numeric">{{ rtrim(rtrim(number_format($row->quantity, 3), '0'), '.') }} Ton</td><td class="numeric">₹{{ number_format($row->unit_price_cents / 100, 2) }}</td><td class="numeric"><strong>₹{{ number_format($row->total_cents / 100, 2) }}</strong></td><td><span class="status amber">{{ $row->status }}</span></td>
        @else
            <td><strong>{{ $row->reference }}</strong></td><td>{{ $row->party }}</td><td><strong>{{ $row->product_name }}</strong><small>{{ $row->sku }}</small></td><td>{{ $row->group_name }}<small>{{ rtrim(rtrim(number_format($row->weight, 3), '0'), '.') }} kg</small></td><td>{{ date('d M Y', strtotime($row->date)) }}</td><td class="numeric">{{ rtrim(rtrim(number_format($row->quantity, 3), '0'), '.') }} {{ $row->unit }}</td><td class="numeric">₹{{ number_format($row->unit_price_cents / 100, 2) }}</td><td class="numeric"><strong>₹{{ number_format($row->total_cents / 100, 2) }}</strong></td>
        @endif
    </tr>@empty<tr><td colspan="8" class="empty">No records match these filters.<small>Change or clear a filter to see more results.</small></td></tr>@endforelse
    </tbody></table></div>
    @if($rows->hasPages())<div class="pagination"><span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>@if($rows->previousPageUrl())<a href="{{ $rows->previousPageUrl() }}">← Previous</a>@endif @if($rows->nextPageUrl())<a href="{{ $rows->nextPageUrl() }}">Next →</a>@endif</div>@endif
</section>
@endsection
