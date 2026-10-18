@extends('layouts.client-public')

@php
    $companyName = config('app.client_portal_company');
@endphp

@section('title', 'ご回答ありがとうございました')

@section('content')
{{-- 性格診断回答完了画面（S-1416。2026-10 追加）。愛犬アンケートの回答完了画面（S-1414）と同じ作り・同じ文言
     （見出し「ご回答ありがとうございました」と本文 2 文。2026-10 変更：当初は見出し「性格診断」・本文 1 文だった）。
     トークンを使わない固定の画面で、直接開かれても同じ文面を出す。
     - 愛犬の名前も結果（タイプ・点数）も出さない（要件定義書 6-15-18 備考。アカウントを開設していないお客様もいるため。
       結果は、マイページのある会員はマイページ〔S-1402〕で見られる。2026-10 変更：当初は「結果はトレーナーだけが見る」）
     - ［マイページへ戻る］は、ログインしている会員が自分の愛犬の案内に回答したときだけ出す（S-1414 と同じ。2026-10 変更：
       当初は置かなかった）。回答の送信で判定してフラッシュの showMypageLink で渡す
     - 見出しはカードの外（S-1414 と同じ） --}}
<div class="c-login c-login--wide">
    <h1 class="mb-4">ご回答ありがとうございました</h1>

    <div class="card c-login-card">
        <div class="card-body p-4">
            <p>いただいた内容は、トレーニングの参考にさせていただきます。</p>
            <p class="mb-0">このページは閉じていただいて構いません。</p>
        </div>
    </div>

    @include('client._mypage-back-link')

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
