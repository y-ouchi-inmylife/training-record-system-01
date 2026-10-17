@extends('layouts.print')

@section('title', '性格診断のご案内')

@section('content')
{{-- 性格診断案内画面（S-0312。2026-10 追加。要件定義書 6-16-11）。
     受付アンケート案内画面（S-0311、trainees/intake-survey-token-print.blade.php）と同じ作り：
     印刷専用レイアウト・ページ上部の操作エリア（「戻る」と「文面をコピー」。d-print-none で印刷時は
     出さない。S-0307・S-0311・S-0312 の 3 画面だけに認めた例外）・案内を見分ける帯（紫）・QR コード（qr-code.js）・
     文面のコピー（copy-message.js）。S-0311 との違い：帯（文字・色・アイコン）、手順の 2 番目、有効な案内がないときの文、
     コピーの文面。お客様の氏名も愛犬の名前も載せない（S-0311 と同じ）。
     変数：$trainee、$url（有効な案内がなければ null）、$copyText（同） --}}
<div class="print-actions d-print-none d-flex flex-wrap gap-2 mb-3">
    {{-- 戻る（2026-10-17 変更。その前は「閉じる」）：S-0311 と同じく、会員詳細（S-0305。案内の操作は会員詳細だけで
         行うため）へのふつうのリンク --}}
    <a href="{{ route('clients.show', $trainee->client_id) }}" class="btn btn-outline-secondary btn-sm">戻る</a>
    @if($url)
        {{-- 文面はコントローラ（TraineePersonalityDiagnosisTokenController::buildCopyText）で組み立て、
             data-copy-text で受け取る（S-0311 と同じ）。ボタンは copy-message.js が拾う --}}
        <button type="button"
                class="btn btn-outline-primary btn-sm"
                data-copy-message
                data-copy-text="{{ $copyText }}">文面をコピー</button>
    @endif
</div>

{{-- 案内を見分ける帯＝ページの見出し（h1。紫。ほかの案内〔S-0307・S-0311〕と取り違えないため）。
     有効な案内がないときも出す（どの案内の画面かが分かるように） --}}
<x-print-band label="性格診断のご案内" variant="personality" icon="heart-pulse" />

@if($url)
    {{-- 見出し（上の帯）のすぐ下に QR コードを置く（S-0311 と同じ）。愛犬の名前は載せない（設計書 S-0312・§2-4） --}}
    <div class="print-qr">
        <canvas data-qr-url="{{ $url }}" data-qr-size="240"
                aria-label="性格診断の案内の QR コード"></canvas>
    </div>

    <p class="print-lead">
        上の QR コードを読み取るか、下の URL をブラウザに入力してください。
    </p>

    <div class="print-url">{{ $url }}</div>

    <hr class="print-divider">

    <p class="print-section-title">ご回答の手順</p>
    <ol class="print-steps">
        <li>上の QR コードを読み取ります</li>
        <li>開いたページで、50の質問にお答えください</li>
        <li>「送信する」を押すと完了です</li>
    </ol>

    <hr class="print-divider">

    {{-- 有効期限は持たない（要件定義書 6-15-18 備考）。S-0311 と同じ位置に置く --}}
    <p class="print-lead">ご回答は1回かぎりです。送信すると、この案内は使えなくなります。</p>

    {{-- 訓練所名は config('app.client_portal_company')。未設定なら出力しない（S-0311 と同じ） --}}
    @if(config('app.client_portal_company'))
        <p class="print-company">{{ config('app.client_portal_company') }}</p>
    @endif
@else
    {{-- 有効な案内なし（未発行・取消済み・前の案内に回答済みのいずれでも同じ）。発行できる場所には触れない。
         ページ上部の「閉じる」だけを残す（S-0311 と同じ見せ方） --}}
    {{-- 見出しは上の帯。「ない」ことは本文（太字）で伝える（見出しにはしない） --}}
    <p class="print-lead"><strong>有効な性格診断の案内がありません。</strong></p>
@endif
@endsection

@if($url)
    @push('scripts')
        @vite(['resources/js/qr-code.js', 'resources/js/copy-message.js'])
    @endpush
@endif

