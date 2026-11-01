@extends('layout')
@section('title', $sauda ? 'Edit Sauda' : 'New Sauda')
@section('content')
<section class="page-heading compact-heading"><div><div class="eyebrow">SAUDA / {{ $sauda ? 'EDIT' : 'NEW' }}</div><h1>{{ $sauda ? 'Edit' : 'Create' }} Sauda<span class="accent">.</span></h1><p>Define the party, product, committed quantity and rate before stock movement.</p></div><a class="button" href="{{ route('saudas.index') }}">← Back to Sauda</a></section>
<form class="voucher-card" method="post" action="{{ $sauda ? route('saudas.update', $sauda->id) : route('saudas.store') }}">@csrf @if($sauda)@method('put')@endif
    <div class="voucher-grid">
        <label><span>Sauda Type <b>*</b></span><select required name="type"><option value="">Select type</option><option value="purchase" @selected(old('type', $sauda?->type ?? request('type')) === 'purchase')>Purchase Sauda</option><option value="sale" @selected(old('type', $sauda?->type ?? request('type')) === 'sale')>Sale Sauda</option></select></label>
        <label><span>Supplier / Party <b>*</b></span><select required name="party_id"><option value="">Select party</option>@foreach($parties as $party)<option value="{{ $party->id }}" @selected((string)old('party_id', $sauda?->party_id) === (string)$party->id)>{{ $party->name }}</option>@endforeach</select><small>Managed from Masters → Parties.</small></label>
        <label><span>Product <b>*</b></span><select required name="product_id"><option value="">Select product</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)old('product_id', $sauda?->product_id) === (string)$product->id)>{{ $product->name }} · {{ $product->stock }} Ton available</option>@endforeach</select></label>
        <label><span>Committed QTY <b>*</b></span><div class="input-with-unit"><input required name="quantity" type="number" min="0.001" max="1000000" step="0.001" value="{{ old('quantity', $sauda?->quantity) }}"><i>Ton</i></div></label>
        <label><span>Rate <b>*</b></span><div class="input-with-unit"><i>₹</i><input required name="rate" type="number" min="0.01" max="10000000" step="0.01" value="{{ old('rate', $sauda ? number_format($sauda->rate_cents / 100, 2, '.', '') : '') }}"></div></label>
        <label><span>Sauda Date <b>*</b></span><input required name="date" type="date" value="{{ old('date', $sauda?->date ?? today()->toDateString()) }}"></label>
    </div><div class="product-form-actions"><a class="button" href="{{ route('saudas.index') }}">Cancel</a><button class="button primary" type="submit">{{ $sauda ? 'Update' : 'Save' }} Sauda</button></div>
</form>
@endsection
