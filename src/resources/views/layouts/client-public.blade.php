<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="/icons/favicon-client.ico" sizes="any">
    <link rel="icon" href="/icons/favicon-client.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon-client.png">
    <link rel="manifest" href="/manifest-client.json">
    <meta name="theme-color" content="#162D66">
    <title>@hasSection('title')@yield('title') - @endif{{ config('app.client_portal_name', 'トレーニング記録') }}</title>
    @vite(['resources/sass/client.scss', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
    @include('layouts.partials.client-nav', ['authed' => false])

    <main class="py-4">
        <div class="container">
            {{-- 失敗の知らせ（2026-10-21 追加：ページの有効期限切れ〔419〕で初回設定・メールアドレス登録・パスワード再設定の画面へ戻したときの案内。
                 layouts.client の session('error') と同じ作り） --}}
            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @yield('content')
        </div>
    </main>
</body>
</html>
