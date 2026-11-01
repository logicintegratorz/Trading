@extends('layout')
@section('title', 'Inventory')
@section('content')
<section class="page-heading">
    <div>
        <div class="eyebrow">STOCK CONTROL</div>
        <h1>Inventory report<span class="accent">.</span></h1>
        <p>Track product rates, opening balances, and minimum stock levels.</p>
    </div>
    <a class="button primary" href="{{ route('products.create') }}">＋ Add Product</a>
</section>

<section class="metrics inventory-metrics" aria-label="Inventory totals">
    <article class="metric"><div class="metric-label">Total products <span class="metric-icon blue">▦</span></div><strong>{{ number_format($report->total_products) }}</strong><div class="metric-foot">Products in your catalogue</div></article>
    <article class="metric"><div class="metric-label">Above minimum <span class="metric-icon green">✓</span></div><strong>{{ number_format($report->healthy_products) }}</strong><div class="metric-foot">Healthy stock levels</div></article>
    <article class="metric"><div class="metric-label">Needs attention <span class="metric-icon danger">⚠</span></div><strong>{{ number_format($report->low_products) }}</strong><div class="metric-foot">At or below minimum</div></article>
    <article class="metric"><div class="metric-label">Inventory value <span class="metric-icon purple">₹</span></div><strong>₹{{ number_format($report->stock_value / 100, 2) }}</strong><div class="metric-foot">At current weighted cost</div></article>
</section>

<section class="panel">
    <div class="report-toolbar">
        <div><h2>Product report</h2><p>{{ $products->total() }} matching products</p></div>
        <form class="inventory-filters" method="get" action="{{ route('inventory.index') }}" data-auto-filter>
            <label><span>Search</span><input name="q" value="{{ $search }}" placeholder="Product, group or SKU"></label>
            <label><span>Group</span><select name="group"><option value="">All groups</option>@foreach($groups as $groupOption)<option value="{{ $groupOption }}" @selected($group === $groupOption)>{{ $groupOption }}</option>@endforeach</select></label>
            <label><span>Stock status</span><select name="stock_status"><option value="">All stock</option><option value="healthy" @selected($stockStatus === 'healthy')>Above minimum</option><option value="low" @selected($stockStatus === 'low')>At / below minimum</option></select></label>
            <button class="button primary" type="submit" data-filter-submit>Apply filters</button>
            @if($search || $group || $stockStatus)<a class="button" href="{{ route('inventory.index') }}">Clear</a>@endif
        </form>
    </div>
    <div class="table-scroll">
        <table class="inventory-table">
            <thead><tr><th>Group Name</th><th>Product Name</th><th class="numeric">Weight</th><th class="numeric">Purchase Rate</th><th class="numeric">Sale Rate</th><th class="numeric">Opening Rate</th><th class="numeric">Qty / Minimum</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($products as $product)
                @php
                    $isHealthy = (float) $product->stock > (float) $product->reorder_level;
                    $quantity = rtrim(rtrim(number_format($product->stock, 3, '.', ''), '0'), '.');
                    $minimum = rtrim(rtrim(number_format($product->reorder_level, 3, '.', ''), '0'), '.');
                    $weight = rtrim(rtrim(number_format($product->weight, 3, '.', ''), '0'), '.');
                @endphp
                <tr>
                    <td><span class="group-tag">{{ $product->group_name }}</span></td>
                    <td><strong>{{ $product->name }}</strong><small>{{ $product->sku }}</small></td>
                    <td class="numeric">{{ $weight }} kg</td>
                    <td class="numeric">₹{{ number_format($product->purchase_rate_cents / 100, 2) }}</td>
                    <td class="numeric">₹{{ number_format($product->sale_rate_cents / 100, 2) }}</td>
                    <td class="numeric">₹{{ number_format($product->opening_rate_cents / 100, 2) }}</td>
                    <td class="numeric"><strong>{{ $quantity }} {{ $product->unit }}</strong><small>Min: {{ $minimum }}</small></td>
                    <td><span class="stock-indicator {{ $isHealthy ? 'stock-ok' : 'stock-low' }}">{{ $isHealthy ? '✓' : '⚠' }} {{ $quantity }} {{ $product->unit }}</span><small class="minimum-note">Min: {{ $minimum }}</small></td><td><div class="row-actions"><a class="button" href="{{ route('products.edit', $product->id) }}">Edit</a><form method="post" action="{{ route('products.destroy', $product->id) }}" data-confirm-delete>@csrf @method('delete')<button class="button danger" type="submit">Delete</button></form></div></td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">No products match these filters.<small>Clear the filters or add a new product.</small></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination">@if($products->previousPageUrl())<a href="{{ $products->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</span>@if($products->nextPageUrl())<a href="{{ $products->nextPageUrl() }}">Next →</a>@endif</div>
</section>
@endsection
