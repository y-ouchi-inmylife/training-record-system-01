@extends('layouts.app')

@section('title', '初回設定メール')

@section('content')
{{-- 初回設定メール表示画面（S-0316。要件定義書 6-3-9。2026-11-10 追加）。会員詳細（S-0305）の［初回設定メールを表示］から同じタブで開く。
     お客様に初回設定メールが届かないときに、トレーナーが自分のメールから同じ内容を送れるよう、送ったメールの宛先・件名・本文を出す。
     見た目・作りは S-0307 マイページ登録のご案内（clients/email-registration-token-print）にそろえる：
     ［戻る］・見出しの帯・欄の見出しの行の右の［コピー］（copy-message.js。data-copy-area ごとに知らせの場所を持つ）。QR コードの欄は置かない。
     変数：$client、$to（宛先。表示できるメールがなければ null）、$subject（件名。同）、$body（本文。同）。
     件名・本文はコントローラが ClientLoginLinkMail（実際に送る Mailable）から取り出したもの --}}
<div class="container invitation-page">
    {{-- 戻る：会員詳細（S-0305）へのふつうのリンク（S-0307 と同じ） --}}
    <div class="d-flex mb-3">
        <a href="{{ route('clients.show', $client) }}" class="btn btn-outline-secondary btn-sm">戻る</a>
    </div>

    {{-- 見出しの帯（h1）。色は S-0307 と同じ青（マイページ登録の手続きの画面のため）。アイコンはメールを表す封筒（S-0307 の人のアイコンと見分ける） --}}
    <x-print-band label="初回設定メール" variant="mypage" icon="envelope" />

    @if($body !== null)
        <p>
            会員さんにメールが届かないときは、この内容をご自身のメールから送ってください。<br>
            宛先は必ず下のメールアドレスにしてください。アドレスに誤りがある場合は送らずに、会員さんにお渡しした URL からメールアドレスを入れ直してもらってください。
        </p>

        {{-- 本文のリンクはログイン用リンク（開くとその会員としてログインする）。確かめのつもりで開かないよう、注意の帯にする --}}
        <div class="alert alert-warning d-flex align-items-start gap-2" data-login-link-warning>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <div>本文のリンクは開かないでください。開くと、この会員さんとしてログインした状態になります。</div>
        </div>

        {{-- 宛先・件名・本文の 3 つの欄（縦に積む）。中身は改行を保って出す（.invitation-message は white-space: pre-wrap）。
             ［コピー］は欄の中身をそのままコピーする（data-copy-text）。見た目・読み上げの付け方は invitations/_panels と同じ --}}
        @foreach([
            ['key' => 'to', 'heading' => '宛先', 'text' => $to],
            ['key' => 'subject', 'heading' => '件名', 'text' => $subject],
            ['key' => 'body', 'heading' => '本文', 'text' => $body],
        ] as $field)
            <section class="card mb-3" aria-labelledby="login-link-mail-{{ $field['key'] }}-heading" data-login-link-mail-{{ $field['key'] }}>
                <div class="card-header invitation-panel-header invitation-send-header" data-copy-area>
                    <h2 class="h6 mb-0" id="login-link-mail-{{ $field['key'] }}-heading">{{ $field['heading'] }}</h2>
                    <span class="invitation-copy-status small" role="status" aria-live="polite" data-copy-status></span>
                    <button type="button" class="btn btn-primary btn-sm" aria-label="{{ $field['heading'] }}をコピー" data-copy-message data-copy-text="{{ $field['text'] }}">コピー</button>
                </div>
                <div class="card-body">
                    <div class="invitation-message">{{ $field['text'] }}</div>
                </div>
            </section>
        @endforeach
    @else
        {{-- 表示できるメールなし（初回設定待ちの期限内でない・未使用のログイン用リンクがない）。S-0307 の「ない」ときと同じ書き方 --}}
        <p class="invitation-empty">
            <strong>表示できる初回設定メールはありません。</strong>
        </p>
    @endif
</div>
@endsection

@if($body !== null)
    @push('scripts')
        @vite(['resources/js/copy-message.js'])
    @endpush
@endif
