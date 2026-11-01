@extends('layout')
@section('title', $configuration['label'].' Masters')
@section('content')
<section class="page-heading compact-heading">
    <div><div class="eyebrow">ADMIN MASTER DATA</div><h1>{{ $configuration['plural'] }}<span class="accent">.</span></h1><p>Add the approved values that users can select while creating orders.</p></div>
    <div class="heading-actions"><a class="button {{ $master === 'group-heads' ? 'primary' : '' }}" href="{{ route('masters.index', 'group-heads') }}">Group Heads</a><a class="button {{ $master === 'parties' ? 'primary' : '' }}" href="{{ route('masters.index', 'parties') }}">Parties</a></div>
</section>
<section class="master-layout">
    <form class="panel master-create" method="post" action="{{ route('masters.store', $master) }}">
        @csrf
        <div class="panel-heading"><div><h2>Add {{ $configuration['label'] }}</h2><p>This value will appear in the order dropdown.</p></div></div>
        <div class="master-input"><label>{{ $configuration['label'] }} name<input required autofocus name="name" maxlength="{{ $configuration['max'] }}" value="{{ old('name') }}" placeholder="Enter {{ strtolower($configuration['label']) }} name"></label><button class="button primary" type="submit">＋ Add</button></div>
    </form>
    <section class="panel">
        <div class="panel-heading"><div><h2>Saved {{ $configuration['plural'] }}</h2><p>{{ $values->count() }} values available</p></div></div>
        <div class="master-list">
            @forelse($values as $value)
            <div class="master-row">
                <form method="post" action="{{ route('masters.update', [$master, $value->id]) }}">@csrf @method('put')<input required name="name" maxlength="{{ $configuration['max'] }}" value="{{ $value->name }}"><button class="button" type="submit">Edit</button></form>
                <form method="post" action="{{ route('masters.destroy', [$master, $value->id]) }}" data-confirm-delete>@csrf @method('delete')<button class="button danger" type="submit">Delete</button></form>
            </div>
            @empty
            <div class="empty">No values added yet.<small>Add the first value using the form.</small></div>
            @endforelse
        </div>
    </section>
</section>
@endsection
