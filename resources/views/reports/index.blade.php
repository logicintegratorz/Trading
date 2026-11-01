@extends('layout')
@section('title', 'Reports')
@section('content')
<section class="page-heading report-heading"><div><div class="eyebrow">BUSINESS INTELLIGENCE</div><h1>Reports<span class="accent">.</span></h1><p>Review orders, size-wise movement and live inventory from one place.</p></div></section>
<section class="report-grid">
@foreach($reports as $key => $report)
    <a class="report-card" href="{{ route('reports.show', $key) }}">
        <span class="report-icon">{{ $report['icon'] }}</span>
        <div><h2>{{ $report['title'] }}</h2><p>{{ $report['description'] }}</p></div>
        <strong>{{ number_format($counts[$key]) }} <small>records</small></strong><span class="report-arrow">→</span>
    </a>
@endforeach
</section>
@endsection
