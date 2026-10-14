@extends('layouts.client-public')

@php
    $companyName = config('app.client_portal_company');
@endphp

@section('title', '受付アンケート')

@section('content')
{{-- 受付アンケートの送信がセッション切れ（419）になったときの画面（2026-10 追加。設計書 S-1413 備考
     「入力中にセッションが切れたとき」）。bootstrap/app.php の例外の処理から、受付アンケートの画面
     （client-portal/intake-survey/*）からの送信のときだけ出す。ほかの画面は errors/419.blade.php のまま。
     - 見た目は使えない URL の画面（unavailable.blade.php）にそろえる（見出しはカードの外、本文はカードの中、
       「次にできること」のブロックは置かない）
     - 入力を残す・トークンを取り直す仕組みは作らない（案内は回答するまで使えるため、開き直せば回答できる） --}}
<div class="c-login c-login--wide">
    <h1 class="mb-4">時間がたったため送信できませんでした</h1>

    <div class="card c-login-card">
        <div class="card-body p-4">
            <p class="mb-0">お手数ですが、もう一度案内のページ（QR コードやリンク）を開いて、はじめからご入力ください。</p>
        </div>
    </div>

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
