@extends('layout')
@section('title', 'Gate Register')
@section('content')
<section class="page-heading">
    <div><div class="eyebrow">GATE / TRACKING</div><h1>Gate Register<span class="accent">.</span></h1><p>Track every vehicle entry with its party, movement type and gate photo.</p></div>
    <a class="button primary" href="{{ route('gate.create') }}">＋ New Gate Entry</a>
</section>
<section class="report-summary">
    <div><span>Total Gate Entries</span><strong>{{ number_format($summary->total ?? 0) }}</strong><small>All recorded vehicle movements</small></div>
    <div><span>Purchase Entries</span><strong>{{ number_format($summary->purchase_total ?? 0) }}</strong><small>Incoming purchase vehicles</small></div>
    <div><span>Sale Entries</span><strong>{{ number_format($summary->sale_total ?? 0) }}</strong><small>Outgoing sale vehicles</small></div>
    <div><span>Unique Vehicles</span><strong>{{ number_format($summary->vehicles ?? 0) }}</strong><small>Tracked in gate register</small></div>
</section>
<section class="panel">
    <div class="report-toolbar">
        <div><h2>Gate report</h2><p>{{ $entries->total() }} matching gate entries</p></div>
        <form class="inventory-filters" method="get" data-auto-filter>
            <label><span>Search</span><input type="search" name="q" value="{{ $search }}" placeholder="Party or vehicle no."></label>
            <label><span>Party</span><select name="party_id"><option value="">All parties</option>@foreach($parties as $party)<option value="{{ $party->id }}" @selected($partyId === $party->id)>{{ $party->name }}</option>@endforeach</select></label>
            <label><span>Type</span><select name="type"><option value="">All types</option><option value="purchase" @selected($type === 'purchase')>Purchase</option><option value="sale" @selected($type === 'sale')>Sale</option></select></label>
            <label><span>Date</span><input type="date" name="date" value="{{ $date }}"></label><button class="button primary" data-filter-submit>Apply</button>
        </form>
    </div>
    <div class="table-scroll"><table class="gate-table">
        <thead><tr><th>Entry Date</th><th>Party Name</th><th>Vehicle Number</th><th>Type</th><th>Photo</th><th>Action</th></tr></thead>
        <tbody>@forelse($entries as $entry)<tr>
            <td>{{ date('d M Y', strtotime($entry->entry_date)) }}</td><td><strong>{{ $entry->party_name }}</strong></td><td>{{ $entry->vehicle_number }}</td>
            <td><span class="status {{ $entry->type === 'purchase' ? 'green' : 'amber' }}">{{ ucfirst($entry->type) }}</span></td>
            <td>@if($entry->photo_path)<a class="button" target="_blank" href="{{ route('gate.photo', $entry->id) }}">View Photo</a>@else<span class="status">No photo</span>@endif</td>
            <td><div class="row-actions"><a class="button" href="{{ route('gate.edit', $entry->id) }}">Edit</a><form method="post" action="{{ route('gate.destroy', $entry->id) }}" data-confirm-delete>@csrf @method('delete')<button class="button danger" type="submit">Delete</button></form></div></td>
        </tr>@empty<tr><td colspan="6" class="empty">No gate entries found.<small>Add the first vehicle entry from New Gate Entry.</small></td></tr>@endforelse</tbody>
    </table></div>
    <div class="pagination">@if($entries->previousPageUrl())<a href="{{ $entries->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</span>@if($entries->nextPageUrl())<a href="{{ $entries->nextPageUrl() }}">Next →</a>@endif</div>
</section>
@endsection
