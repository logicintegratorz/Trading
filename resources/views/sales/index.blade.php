@extends('layout')
@section('title', 'Sale Vouchers')
@section('content')
<section class="page-heading"><div><div class="eyebrow">STEP 3.5 · SALE VOUCHERS</div><h1>Sales<span class="accent">.</span></h1><p>Create a Sale Voucher only from an Order-linked Sale Sauda. Dispatch is available after approval.</p></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Sale Voucher register</h2><p>{{ $sales->total() }} vouchers</p></div></div><div class="table-scroll"><table><thead><tr><th>Reference</th><th>Party</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>@forelse($sales as $sale)<tr><td><strong>{{ $sale->reference }}</strong></td><td>{{ $sale->party_name }}</td><td>{{ $sale->date }}</td><td><span class="status {{ $sale->status === 'Completed' ? 'green' : 'amber' }}">{{ $sale->status }}</span></td><td><a class="button" href="{{ route('sales.show', $sale->id) }}">View</a></td></tr>@empty<tr><td colspan="5" class="empty">No Sale Vouchers yet. Open an Order-linked Sale Sauda and create one.</td></tr>@endforelse</tbody></table></div></section>
@endsection
