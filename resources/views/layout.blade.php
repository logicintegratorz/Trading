<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title', 'Overview') · Tradeflow</title><link rel="stylesheet" href="/app.css"><link rel="stylesheet" href="/inventory.css"><link rel="stylesheet" href="/order.css?v=2"><link rel="stylesheet" href="/reports.css?v=1"><link rel="stylesheet" href="/workflow.css?v=2"><link rel="stylesheet" href="/simplified-workflow.css?v=1"><link rel="stylesheet" href="/readability.css?v=2"><script defer src="/app.js?v=6"></script></head>
<body><header class="topbar"><div class="nav-wrap"><a class="brand" href="/" aria-label="Tradeflow home"><span class="brandmark">▥</span>tradeflow<span class="brand-dot">.</span></a><nav aria-label="Main navigation">
    <a href="/" class="{{ request()->is('/') ? 'active' : '' }}">Overview</a>
    <details class="nav-dropdown"><summary class="{{ request()->is('masters/*') ? 'active' : '' }}">1. Masters <span>⌄</span></summary><div class="dropdown"><a href="{{ route('masters.index', 'group-heads') }}">Group Heads <span>≡</span></a><a href="{{ route('masters.index', 'parties') }}">Parties <span>◎</span></a></div></details>
    <a href="{{ route('inventory.index') }}" class="{{ request()->is('inventory*') ? 'active' : '' }}">2. Inventory</a>
    <a href="/saudas" class="{{ request()->is('saudas*') ? 'active' : '' }}">3. Sauda (Deal)</a>
    <a href="/purchase" class="{{ request()->is('purchase') ? 'active' : '' }}">4. Receive Stock</a>
    <a href="/sale" class="{{ request()->is('sale') ? 'active' : '' }}">5. Dispatch Stock</a>
    <details class="nav-dropdown"><summary class="{{ request()->is('*-orders*') ? 'active' : '' }}">Order Documents <span>⌄</span></summary><div class="dropdown"><a href="/purchase-orders">Purchase Order (PO) <span>↙</span></a><a href="/sale-orders">Sale Order (SO) <span>↗</span></a></div></details>
    <details class="nav-dropdown"><summary class="{{ request()->is('reports*') || request()->is('dispatch*') ? 'active' : '' }}">Reports <span>⌄</span></summary><div class="dropdown"><a href="{{ route('dispatch.index') }}">Dispatch Register <span>↗</span></a><a href="{{ route('reports.index') }}">All Reports <span>▦</span></a><a href="{{ route('reports.show', 'po') }}">PO Report <span>PO</span></a><a href="{{ route('reports.show', 'so') }}">SO Report <span>SO</span></a><a href="{{ route('reports.show', 'inventory') }}">Inventory Report <span>≡</span></a><a href="{{ route('reports.show', 'stock-alert') }}">Stock Alerts <span>!</span></a></div></details>
</nav><div class="workspace"><span class="workspace-dot"></span> Trading workspace <span class="avatar">TF</span></div></div></header>
<main><div class="breadcrumb">WORKSPACE <span>/</span> @yield('title', 'Overview')</div>
@if(session('success'))<div class="notice" role="status">✓ {{ session('success') }}</div>@endif
@if($errors->any())<div class="error" role="alert"><strong>Please check your entry.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</main><footer><span><strong>tradeflow.</strong> A clearer view of your business.</span><span>Trading workspace · Phase 01</span></footer>
</body></html>
