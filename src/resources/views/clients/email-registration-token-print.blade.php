@extends('layouts.app')

@section('title', 'マイページ登録のご案内')

@section('content')
{{-- マイページ登録のご案内（S-0307）。会員詳細（S-0305）の［マイページ登録の案内を表示］から同じタブで開く。
     2026-10-18 変更：ユーザーレビューで、実際は印刷せず、LINE・メールで送るか、その場で QR コードを読み取ってもらうことが
     分かったため、印刷して渡す作り（印刷専用のレイアウト layouts.print・A4・「ご登録の手順」の欄）をやめ、トレーナー側の
     ふつうの画面（layouts.app）に「LINE・メールで送る」「その場で読み取ってもらう」の 2 つの欄を置いた（invitations/_panels）。
     URL（…/print）とルート名は、会員詳細などから呼んでいるのでそのまま。
     変数：$client、$url（有効な案内がなければ null）、$expiresOn（同）、$copyText（同。LINE・メールで送る文面） --}}
<div class="container invitation-page">
    {{-- 戻る：会員詳細（S-0305）へのふつうのリンク（2026-10-17 変更。その前は「閉じる」） --}}
    <div class="d-flex mb-3">
        <a href="{{ route('clients.show', $client) }}" class="btn btn-outline-secondary btn-sm">戻る</a>
    </div>

    {{-- 案内を見分ける帯＝ページの見出し（h1。青）。有効な URL がないときも出す（どの案内の画面かが分かるように） --}}
    <x-print-band label="マイページ登録のご案内" variant="mypage" icon="person-circle" />

    @if($url)
        @include('invitations._panels', [
            'copyText' => $copyText,
            'url' => $url,
            'qrLabel' => 'マイページ登録案内の QR コード',
        ])
    @else
        {{-- 有効な URL なし。「ない」ことは本文の 1 行目（太字）で伝える（見出しにはしない） --}}
        <p class="invitation-empty">
            <strong>発行済みのマイページ登録案内がありません</strong><br>
            会員詳細画面から発行してください。
        </p>
    @endif
</div>
@endsection

@if($url)
    @push('scripts')
        @vite(['resources/js/qr-code.js', 'resources/js/copy-message.js'])
    @endpush
@endif
