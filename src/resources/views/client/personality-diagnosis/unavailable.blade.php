@extends('layouts.client-public')

@php
    $companyName = config('app.client_portal_company');
@endphp

@section('title', '性格診断')

@section('content')
{{-- 性格診断の使えない URL の画面（設計書 S-1415「使えない URL の画面」。2026-10 追加）。
     受付アンケートの使えない URL の画面（client/intake-survey/unavailable.blade.php）と同じ作り：見出し「性格診断」は
     カードの外、本文（$message）はカードの中、「次にできること」のブロックは置かない。
     段階2 では、回答の画面の仮の受け口（Client\PersonalityDiagnosisController::show）がこの画面を返す --}}
<div class="c-login c-login--wide">
    <h1 class="mb-4">性格診断</h1>

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
