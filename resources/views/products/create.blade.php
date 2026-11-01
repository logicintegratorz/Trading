@extends('layout')
@php($isEdit = $product !== null)
@section('title', $isEdit ? 'Edit Product' : 'Add Product')
@section('content')
<section class="page-heading compact-heading">
    <div>
        <div class="eyebrow">INVENTORY / {{ $isEdit ? 'EDIT PRODUCT' : 'NEW PRODUCT' }}</div>
        <h1>{{ $isEdit ? 'Edit' : 'Add' }} Product<span class="accent">.</span></h1>
        <p>{{ $isEdit ? 'Update product details, rates, current stock and minimum level.' : 'Create the opening stock record and pricing details for a product.' }}</p>
    </div>
    <a class="button" href="{{ route('inventory.index') }}">← Back to inventory</a>
</section>

<section class="product-form-shell">
    <div class="form-intro">
        <span class="form-intro-icon">▦</span>
        <h2>Product details</h2>
        <p>Enter the product identity, rates, and stock thresholds. {{ $isEdit ? 'Stock changes here are treated as a manual correction.' : 'The SKU is generated automatically.' }}</p>
        <div class="threshold-guide"><span class="stock-indicator stock-ok">✓ Above minimum</span><span class="stock-indicator stock-low">⚠ At / below minimum</span></div>
    </div>
    <form class="product-form panel" method="post" action="{{ $isEdit ? route('products.update', $product->id) : route('products.store') }}">
        @csrf @if($isEdit)@method('put')@endif
        <div class="product-form-grid">
            <label><span>Group Name <b>*</b></span><select required name="group_name"><option value="">Select a group</option>@foreach($groups as $group)<option value="{{ $group->name }}" @selected(old('group_name', $product?->group_name) === $group->name)>{{ $group->name }}</option>@endforeach</select><small>Manage values from Masters → Group Heads.</small></label>
            <label><span>Product Name <b>*</b></span><input required name="product_name" maxlength="120" value="{{ old('product_name', $product?->name) }}" placeholder="e.g. Stainless Steel Bolt"></label>
            <label><span>Weight <b>*</b></span><div class="input-with-unit"><input required name="weight" type="number" min="0" max="1000000" step="0.001" value="{{ old('weight', $product?->weight) }}" placeholder="0.000"><i>kg</i></div></label>
            <label><span>Purchase Rate <b>*</b></span><div class="input-with-unit"><i>₹</i><input required name="purchase_rate" type="number" min="0" max="10000000" step="0.01" value="{{ old('purchase_rate', $product ? number_format($product->purchase_rate_cents/100, 2, '.', '') : '') }}" placeholder="0.00"></div></label>
            <label><span>Sale Rate <b>*</b></span><div class="input-with-unit"><i>₹</i><input required name="sale_rate" type="number" min="0" max="10000000" step="0.01" value="{{ old('sale_rate', $product ? number_format($product->sale_rate_cents/100, 2, '.', '') : '') }}" placeholder="0.00"></div></label>
            <label><span>{{ $isEdit ? 'Current Qty' : 'Opening Qty' }} <b>*</b></span><div class="input-with-unit"><input required name="opening_qty" type="number" min="0" max="1000000" step="0.001" value="{{ old('opening_qty', $product?->stock) }}" placeholder="0.000"><i>Ton</i></div><small>Inventory quantity is always calculated in tons.</small></label>
            <label><span>Opening Rate <b>*</b></span><div class="input-with-unit"><i>₹</i><input required name="opening_rate" type="number" min="0" max="10000000" step="0.01" value="{{ old('opening_rate', $product ? number_format($product->opening_rate_cents/100, 2, '.', '') : '') }}" placeholder="0.00"></div></label>
            <label><span>Minimum Qty <b>*</b></span><div class="input-with-unit"><input required name="minimum_qty" type="number" min="0" max="1000000" step="0.001" value="{{ old('minimum_qty', $product?->reorder_level) }}" placeholder="0.000"><i>Ton</i></div><small>Stock at or below this level is shown in red.</small></label>
        </div>
        <div class="product-form-actions"><a class="button" href="{{ route('inventory.index') }}">Cancel</a><button class="button primary" type="submit">{{ $isEdit ? 'Update' : 'Save' }} Product</button></div>
    </form>
</section>
@endsection
