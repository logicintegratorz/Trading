@extends('layout')

@section('title', 'Sale Entry Details')

@section('content')
@php
    $totalQuantity = $saudas->sum('quantity');
    $totalUsed = $saudas->sum('fulfilled_quantity');
    $totalBalance = $totalQuantity - $totalUsed;
    $canDeleteBundle = $saudas->every(fn ($sauda) => (float) $sauda->fulfilled_quantity === 0.0);
@endphp

<section class="page-heading">
    <div>
        <div class="eyebrow">SALE / ENTRY DETAILS</div>
        <h1>Product lines<span class="accent">.</span></h1>
        <p>Review every Group Head and product included in this single Sale entry.</p>
    </div>
    <a class="button" href="{{ route('module', 'sale') }}">← Back to Dispatch Stock</a>
</section>

<section class="report-summary sauda-pending-summary">
    <div><span>Product lines</span><strong>{{ $saudas->count() }}</strong><small>saved in this Sale entry</small></div>
    <div><span>Total quantity</span><strong>{{ number_format($totalQuantity, 3) }} <small>Ton</small></strong><small>{{ number_format($totalUsed, 3) }} Ton already dispatched</small></div>
    <div><span>Remaining balance</span><strong>{{ number_format($totalBalance, 3) }} <small>Ton</small></strong><small>available for dispatch</small></div>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h2>Included Group Heads &amp; products</h2>
            <p>Use Edit for a line-wise change. Delete is protected after any dispatch.</p>
        </div>
        <div class="row-actions">
        @if($isSaleBundle && $totalBalance > 0)
            <a class="button primary" href="{{ route('stock-entry.create', 'sale') }}?sauda_ids={{ $ids }}">Dispatch all products</a>
        @endif
        @if($canDeleteBundle)
            <form method="post" action="{{ route('saudas.bundle.destroy') }}" data-confirm-delete>
                @csrf
                @method('delete')
                <input type="hidden" name="ids" value="{{ $ids }}">
                <button class="button danger" type="submit">Delete all lines</button>
            </form>
        @endif
        </div>
    </div>
    <div class="table-scroll sauda-table-scroll">
        <table class="sauda-table">
            <thead><tr><th>Group Head</th><th>Product</th><th>Date</th><th class="numeric">Qty</th><th class="numeric">Used / Balance</th><th class="numeric">Rate</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                @foreach($saudas as $sauda)
                    @php($balance = (float) $sauda->quantity - (float) $sauda->fulfilled_quantity)
                    <tr>
                        <td><strong>{{ $sauda->group_head_name }}</strong></td>
                        <td>{{ $sauda->product_name }}</td>
                        <td>{{ date('d M Y', strtotime($sauda->date)) }}</td>
                        <td class="numeric">{{ number_format($sauda->quantity, 3) }} Ton</td>
                        <td class="numeric">{{ number_format($sauda->fulfilled_quantity, 3) }} / <strong>{{ number_format($balance, 3) }} Ton</strong></td>
                        <td class="numeric">₹{{ number_format($sauda->rate_cents / 100, 2) }}</td>
                        <td><span class="status {{ $sauda->status === 'Completed' ? 'green' : 'amber' }}">{{ $sauda->status }}</span></td>
                        <td>
                            <div class="row-actions">
                                @if($balance > 0)<a class="button primary" href="{{ route('stock-entry.create', $sauda->type) }}?sauda_id={{ $sauda->id }}">Dispatch</a>@endif
                                @if($sauda->status !== 'Completed')<a class="button" href="{{ route('saudas.edit', $sauda->id) }}">Edit</a>@endif
                                @if((float) $sauda->fulfilled_quantity === 0.0)
                                    <form method="post" action="{{ route('saudas.destroy', $sauda->id) }}" data-confirm-delete>@csrf @method('delete')<button class="button danger" type="submit">Delete</button></form>
                                @elseif($balance > 0)<span class="status">Delete locked</span>@endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
