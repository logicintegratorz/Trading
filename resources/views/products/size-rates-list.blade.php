@extends('layout')
@section('title', 'Stock Item List')
@section('content')
<section class="page-heading">
    <div>
        <div class="eyebrow">INVENTORY / STOCK ITEM</div>
        <h1>Stock Item List<span class="accent">.</span></h1>
        <p>Open a size to manage its purchase rate, sale rate and GST, or add a new stock item.</p>
    </div>
    <div class="row-actions"><a class="button" href="{{ route('inventory.index') }}">← Back to Inventory</a><a class="button primary" href="{{ route('products.size-rates.create') }}">＋ Add Stock Item</a></div>
</section>

<section class="panel">
    <div class="report-toolbar">
        <div><h2>Stock item register</h2><p>{{ $products->total() }} matching stock items</p></div>
        <form class="inventory-filters" method="get" action="{{ route('products.size-rates.index') }}" data-auto-filter>
            <label><span>Search</span><input name="q" value="{{ $search }}" placeholder="Size, group or A/c code"></label>
            <label><span>Group</span><select name="group"><option value="">All groups</option>@foreach($groups as $groupOption)<option value="{{ $groupOption }}" @selected($group === $groupOption)>{{ $groupOption }}</option>@endforeach</select></label>
            <button class="button primary" type="submit" data-filter-submit>Apply filters</button>
            @if($search || $group)<a class="button" href="{{ route('products.size-rates.index') }}">Clear</a>@endif
        </form>
    </div>
    <div class="table-scroll">
        <table class="inventory-table">
            <thead><tr><th>A/c Code</th><th>Stock Item</th><th>Group Name</th><th class="numeric">Opening Qty</th><th class="numeric">Purchase Rate</th><th class="numeric">Sale Rate</th><th class="numeric">GST</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($products as $product)
                <tr>
                    <td><strong>{{ $product->sku }}</strong></td>
                    <td><strong>{{ $product->name }}</strong><small>Weight: {{ rtrim(rtrim(number_format($product->weight, 3, '.', ''), '0'), '.') }} kg</small></td>
                    <td><span class="group-tag">{{ $product->group_name }}</span></td>
                    <td class="numeric">{{ rtrim(rtrim(number_format($product->stock, 3, '.', ''), '0'), '.') }} {{ $product->unit }}</td>
                    <td class="numeric">₹{{ number_format($product->purchase_rate_cents / 100, 2) }}</td>
                    <td class="numeric">₹{{ number_format($product->sale_rate_cents / 100, 2) }}</td>
                    <td class="numeric">{{ number_format($product->gst_percent ?? 18, 2) }}%</td>
                    <td><div class="row-actions"><a class="button primary" href="{{ route('products.size-rates', ['product_id' => $product->id]) }}">Open</a><a class="button" href="{{ route('products.edit', $product->id) }}">Edit</a></div></td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No stock items match these filters.<small>Add a stock item to manage its rates and GST.</small></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination">@if($products->previousPageUrl())<a href="{{ $products->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</span>@if($products->nextPageUrl())<a href="{{ $products->nextPageUrl() }}">Next →</a>@endif</div>
</section>
@endsection
