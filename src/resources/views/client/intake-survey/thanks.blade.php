@extends('layouts.client-public')

@php
    $companyName = config('app.client_portal_company');
@endphp

@section('title', 'ご回答ありがとうございました')

@section('content')
{{-- 愛犬アンケート回答完了画面（S-1414。2026-10 追加）。トークンを使わない固定の画面で、直接開かれても
     同じ文面を出す。
     - 愛犬の名前は出さない（回答後に名前を取り出す手段を持たないため。個人情報を含まない固定の文面）
     - ［マイページへ戻る］は、ログインしている会員が自分の愛犬の案内に回答したときだけ出す（2026-10 変更：当初は置かなかった。
       アカウントを開設していないお客様もいるため、ログインしていないときは今までどおり出さない）。
       回答の送信で判定してフラッシュの showMypageLink で渡す（この画面はトークンを持たないため）
     - 見出しはカードの外（S-1413 と同じ位置） --}}
<div class="c-login c-login--wide">
    <h1 class="mb-4">ご回答ありがとうございました</h1>

    <div class="card c-login-card">
        <div class="card-body p-4">
            {{-- 1 文目は lang/ja/messages.php の client_survey_thanks.body（S-1414・S-1416 で共通。2026-10-19 変更） --}}
            <p>{{ __('messages.client_survey_thanks.body') }}</p>
            <p class="mb-0">このページは閉じていただいて構いません。</p>
        </div>
    </div>

    {{-- マイページ登録へのお誘い（2026-10-19 追加。出すときだけ） --}}
    @include('client._mypage-registration-invite', ['url' => $mypageRegistrationUrl ?? null])

    @include('client._mypage-back-link')

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
