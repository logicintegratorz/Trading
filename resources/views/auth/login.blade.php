<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Tradeflow</title>
    <link rel="stylesheet" href="/app.css">
    <link rel="stylesheet" href="/auth.css?v=1">
</head>
<body class="auth-page">
<main class="auth-main">
    <section class="auth-card" aria-labelledby="login-title">
        <a class="brand" href="{{ route('login') }}"><span class="brandmark">▥</span>tradeflow<span class="brand-dot">.</span></a>
        <p class="eyebrow">SECURE TRADING WORKSPACE</p>
        <h1 id="login-title">Welcome back<span class="accent">.</span></h1>
        <p class="auth-copy">Sign in to manage inventory, Sauda, stock and order documents.</p>

        @if(session('success'))<div class="notice" role="status">✓ {{ session('success') }}</div>@endif
        @if(session('error'))<div class="error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif

        <form method="post" action="{{ route('login.store') }}" class="login-form">
            @csrf
            <label>Email address<input required autofocus autocomplete="username" type="email" name="email" value="{{ old('email') }}" placeholder="admin@tradeflow.local"></label>
            <label>Password<input required autocomplete="current-password" type="password" name="password" placeholder="Enter your password"></label>
            <button class="button primary" type="submit">Sign in securely</button>
        </form>
        <p class="auth-security-note">Protected with encrypted sessions, inactivity timeout and daily midnight sign-out.</p>
    </section>
</main>
</body>
</html>
