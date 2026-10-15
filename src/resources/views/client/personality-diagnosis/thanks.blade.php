@extends('layouts.client-public')

@php
    $companyName = config('app.client_portal_company');
@endphp

@section('title', '性格診断')

@section('content')
{{-- 性格診断回答完了画面（S-1416。2026-10 追加）。受付アンケートの回答完了画面（S-1414）と同じ作りで、見出しは
     「性格診断」。トークンを使わない固定の画面で、直接開かれても同じ文面を出す。
     - 愛犬の名前も結果（タイプ・点数）も出さない（結果はトレーナーだけが見る。要件定義書 6-15-18 備考）
     - マイページへのリンクは置かない（アカウントを開設していないお客様もいるため。S-1414 と同じ）
     - 見出しはカードの外（S-1415 と同じ位置） --}}
<div class="c-login c-login--wide">
    <h1 class="mb-4">性格診断</h1>

    <div class="card c-login-card">
        <div class="card-body p-4">
            <p class="mb-0">ご回答ありがとうございました。</p>
        </div>
    </div>

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
