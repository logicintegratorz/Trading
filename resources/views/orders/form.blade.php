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
        <label><span>{{ $partyLabel }} <b>*</b><button class="quick-create" type="button" data-order-new-party>＋ New Party</button></span><select required name="party_id"><option value="">Select {{ strtolower($partyLabel) }}</option>@foreach($parties as $party)<option value="{{ $party->id }}" @selected((string) old('party_id', $order?->party_id) === (string) $party->id)>{{ $party->name }}</option>@endforeach</select></label>
        <label><span>Basic Rate <small>(optional, applies to all lines)</small></span><input type="number" min="0" step="0.01" name="basic_rate" value="{{ old('basic_rate', $order ? number_format(($order->basic_rate_cents ?? 0) / 100, 2, '.', '') : '') }}" data-order-basic-rate placeholder="Set common Basic Rate"></label>
        <label><span>Goods / Vehicle No. <small>(optional)</small></span><input name="goods_number" maxlength="60" value="{{ old('goods_number', $order?->goods_number) }}" placeholder="Vehicle or challan no., e.g. PB10CQ3051"><small>Use delivery challan, truck/vehicle number, or your internal goods reference.</small></label>
        <label><span>Voucher Date <b>*</b></span><input required name="date" type="date" value="{{ old('date', $order ? date('Y-m-d', strtotime($order->date)) : today()->toDateString()) }}"></label>
    </div>
    <div class="order-lines-heading"><div><h2>Material details</h2><p>KG shows the last value in the product name: 12x15x20 = 20 KG. Pcs uses the per-piece Product Weight saved in Inventory: (Total Qty in Ton × 1000) ÷ Inventory Weight. Bundles = (Total Qty in Ton × 1000) ÷ 52.</p></div></div>
    <div class="order-lines" data-order-items>
        @foreach($formItems as $index => $item)
        @include('orders.item-row', ['index' => $index, 'item' => $item])
        @endforeach
    </div>
    <div class="order-lines-add"><button class="button" type="button" data-add-order-item>＋ Add Line</button></div>
</form>
<template id="order-item-template">@include('orders.item-row', ['index' => '__INDEX__', 'item' => []])</template>
@include('orders.toolbar', ['mode' => $order ? 'edit' : 'create'])
@endsection
