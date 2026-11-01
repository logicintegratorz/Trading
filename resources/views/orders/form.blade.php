@extends('layout')
@section('title', ($order ? 'Edit ' : 'New ').$title)
@section('content')
@php
    $partyLabel = $module === 'purchase-orders' ? 'Supplier Name' : 'Party Name';
    $formItems = collect(old('items', $items->map(fn($item) => [
        'product_id' => $item->product_id ?? '', 'description' => $item->description ?? '', 'weight_kg' => $item->weight_kg ?? '',
        'difference' => isset($item->difference_cents) ? number_format($item->difference_cents / 100, 2, '.', '') : '',
        'base_rate' => isset($item->base_rate_cents) ? number_format($item->base_rate_cents / 100, 2, '.', '') : '',
        'quantity' => $item->quantity ?? '', 'pieces' => $item->pieces ?? '', 'bundles' => $item->bundles ?? '',
        'rate' => isset($item->rate_cents) ? number_format($item->rate_cents / 100, 2, '.', '') : '',
    ])->all()));
@endphp
<section class="voucher-heading"><div><div class="eyebrow">{{ strtoupper($title) }} / {{ $order ? 'EDIT' : 'NEW' }}</div><h1>{{ $order ? 'Edit' : 'Create' }} {{ $title }}<span class="accent">.</span></h1><p>Add all material lines once; the final receipt and Order Parchi will be generated automatically.</p></div><div class="voucher-chip"><span>Voucher S.No</span><strong>{{ $order?->reference ?? 'Generated on save' }}</strong></div></section>
<form id="order-form" class="voucher-card" method="post" action="{{ $order ? route('orders.update', [$module, $order->id]) : route('orders.store', $module) }}">
    @csrf @if($order) @method('put') @endif
    <div class="voucher-grid order-header-grid">
        <label><span>Group Head <b>*</b></span><select required name="group_head_id"><option value="">Select group head</option>@foreach($groupHeads as $groupHead)<option value="{{ $groupHead->id }}" @selected((string) old('group_head_id', $order?->group_head_id) === (string) $groupHead->id)>{{ $groupHead->name }}</option>@endforeach</select></label>
        <label><span>{{ $partyLabel }} <b>*</b></span><select required name="party_id"><option value="">Select {{ strtolower($partyLabel) }}</option>@foreach($parties as $party)<option value="{{ $party->id }}" @selected((string) old('party_id', $order?->party_id) === (string) $party->id)>{{ $party->name }}</option>@endforeach</select></label>
        <label><span>Goods / Vehicle No. <small>(optional)</small></span><input name="goods_number" maxlength="60" value="{{ old('goods_number', $order?->goods_number) }}" placeholder="Vehicle or challan no., e.g. PB10CQ3051"><small>Use delivery challan, truck/vehicle number, or your internal goods reference.</small></label>
        <label><span>Voucher Date <b>*</b></span><input required name="date" type="date" value="{{ old('date', $order ? date('Y-m-d', strtotime($order->date)) : today()->toDateString()) }}"></label>
    </div>
    <div class="order-lines-heading"><div><h2>Material details</h2><p>Add one row for every size/description.</p></div><button class="button" type="button" data-add-order-item>＋ Add Line</button></div>
    <div class="order-lines" data-order-items>
        @foreach($formItems as $index => $item)
        @include('orders.item-row', ['index' => $index, 'item' => $item])
        @endforeach
    </div>
    <div class="order-settlement">
        <div class="charge-grid">
            @foreach(['labour' => 'Labour', 'discount' => 'Discount', 'commission' => 'Commission', 'freight' => 'Freight', 'other_charges' => 'Others'] as $name => $label)
            @php($column = $name === 'other_charges' ? 'other_charges_cents' : $name.'_cents')
            <label><span>{{ $label }}</span><input type="number" min="0" step="0.01" name="{{ $name }}" value="{{ old($name, $order ? number_format($order->{$column} / 100, 2, '.', '') : '0.00') }}" data-charge></label>
            @endforeach
            <label><span>GST %</span><input type="number" min="0" max="100" step="0.01" name="gst_percent" value="{{ old('gst_percent', $order?->gst_percent ?? 18) }}" data-gst></label>
        </div>
        <div class="order-total detailed-total"><span>Calculated Grand Total</span><strong data-order-total>₹0.00</strong><small data-order-summary>Subtotal ₹0.00 · GST ₹0.00</small></div>
    </div>
</form>
<template id="order-item-template">@include('orders.item-row', ['index' => '__INDEX__', 'item' => []])</template>
@include('orders.toolbar', ['mode' => $order ? 'edit' : 'create'])
@endsection
