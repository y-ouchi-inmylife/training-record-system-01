@extends('layouts.client-public')

@php
    $companyName = config('app.client_portal_company');
@endphp

@section('title', '受付アンケート')

@section('content')
{{-- 受付アンケートの仮の画面（2026-10、段階3b）。ルートだけを先に定義し、回答の画面（S-1413）は
     段階4で作る。見た目は案内が使えないときの画面（client.setup.invalid-token）にそろえた。
     段階3と段階4は両方そろってから本番に出すため、お客様がこの画面を見ることはない想定 --}}
<div class="c-login">
    <div class="card c-login-card">
        <div class="card-body p-4">
            <h2 class="c-auth-heading">受付アンケート</h2>
            <p class="c-auth-lead">このページは準備中です。</p>
        </div>
    </div>

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
