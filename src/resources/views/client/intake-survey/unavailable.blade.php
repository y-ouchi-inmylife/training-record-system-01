@extends('layouts.client-public')

@php
    $companyName = config('app.client_portal_company');
@endphp

@section('title', '受付アンケート')

@section('content')
{{-- 受付アンケートの使えない URL の画面（設計書 S-1413「使えない URL の画面」。2026-10 追加）。
     $message は「回答済み」「使えない（存在しない・取消済み・トレーニー削除済み）」の 2 通り
     （IntakeSurveyController::unavailableView）。
     - 見出し「受付アンケート」はカードの外（S-1413・S-1414 と見出しの位置をそろえる。お客様から見て
       1 つの流れの画面のため）。既存の client.setup.invalid-token（見出しがカードの中）にはそろえない
     - 「次にできること」のブロックは置かない（「使えない」の文言にトレーナーへの問い合わせが入っており、
       「回答済み」ではお客様がすることがないため） --}}
<div class="c-login c-login--wide">
    <h1 class="mb-4">受付アンケート</h1>

    <div class="card c-login-card">
        <div class="card-body p-4">
            <p class="mb-0">{{ $message }}</p>
        </div>
    </div>

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
