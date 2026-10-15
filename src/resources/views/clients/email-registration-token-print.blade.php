@extends('layouts.print')

@section('title', 'マイページ登録のご案内')

@section('content')
{{-- ページ上部のトレーナー向け操作エリア。
     「« 戻る」リンクと「文面をコピー」ボタンを横に並べる。狭い幅では
     折り返す（`flex-wrap`）。どちらも画面表示のみで、`d-print-none` で
     印刷時は非表示にする（お客様に渡す紙にトレーナー向けの導線・操作は
     不要）。「@media print を要素の表示切替に使わない」方針に対し、この
     操作エリアに含める要素だけを例外として認めている（設計書 S-0307
     備考「操作エリアのまとめ方」参照）。
     文面をコピーは有効な URL があるときだけ出すため `@if($url)` の内側に置く。
     戻るリンクは URL の有無を問わず常に出す（条件分岐で出し分けない — 設計書参照）。
     戻るは会員詳細へのリンクにする（history.back() にしない。発行のあと同じタブでこの画面を開いたとき、
     発行の前の古い会員詳細に戻ることがあるため。2026-10）。 --}}
<div class="print-actions d-print-none d-flex flex-wrap gap-2 mb-3">
    <a href="{{ route('clients.show', $client) }}" class="btn btn-outline-secondary btn-sm">&laquo; 戻る</a>
    @if($url)
        {{-- 文面はコントローラで組み立て、data-copy-text で受け取る（画面の文字を
             JavaScript で拾って組み立てる方式は取らない — 画面の見た目と文面を
             独立させるため。設計書 S-0307「案内文面のコピー」参照）。
             ボタンの属性 data-copy-message は、S-0311 受付アンケート案内画面と共用の
             copy-message.js が拾う（2026-10 に data-copy-registration-message から変えた）。 --}}
        <button type="button"
                class="btn btn-outline-primary btn-sm"
                data-copy-message
                data-copy-text="{{ $copyText }}">文面をコピー</button>
    @endif
</div>

{{-- 案内を見分ける帯＝ページの見出し（h1。2026-10 追加。受付アンケート案内〔S-0311〕と取り違えないため）。
     有効な URL がないときも出す（どちらの案内の画面かが分かるように） --}}
<x-print-band label="マイページ登録のご案内" variant="mypage" icon="person-circle" />

@if($url)
    {{-- 有効な URL あり。見出しは上の帯 ---}}
    <div class="print-qr">
        <canvas data-qr-url="{{ $url }}" data-qr-size="240"
                aria-label="マイページ登録案内の QR コード"></canvas>
    </div>

    <p class="print-lead">
        マイページへのご登録は、上の QR コードを読み取るか、下の URL をブラウザに入力してください。
    </p>

    <div class="print-url">{{ $url }}</div>

    <hr class="print-divider">

    <p class="print-section-title">ご登録の手順</p>
    <ol class="print-steps">
        <li>上の QR コードを読み取ります</li>
        <li>開いたページでメールアドレスを入力します</li>
        <li>届いたメールのリンクを開いて、パスワードを設定します</li>
    </ol>

    <hr class="print-divider">

    <p class="print-expiry-label">有効期限</p>
    <p class="print-expiry-date">{{ $expiresOn->format('Y年n月j日') }}まで</p>

    {{-- 訓練所名は config('app.client_portal_company')。未設定なら出力しない --}}
    @if(config('app.client_portal_company'))
        <p class="print-company">{{ config('app.client_portal_company') }}</p>
    @endif
@else
    {{-- 有効な URL なし。ページ上部の「« 戻る」リンクで詳細画面へ戻れるため、
         専用の CTA ボタンは置かない（両状態で戻り導線の見せ方を揃える）。
         この状態ではコピーボタンは出さないため、操作エリアには戻るリンクだけが残る。--}}
    {{-- 見出しは上の帯。「ない」ことは本文の 1 行目（太字）で伝える（見出しにはしない） --}}
    <p class="print-lead">
        <strong>発行済みのマイページ登録案内がありません</strong><br>
        会員詳細画面から発行してください。
    </p>
@endif
@endsection

@if($url)
    @push('scripts')
        @vite(['resources/js/qr-code.js', 'resources/js/copy-message.js'])
    @endpush
@endif
