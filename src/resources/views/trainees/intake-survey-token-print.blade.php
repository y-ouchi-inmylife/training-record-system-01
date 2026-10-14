@extends('layouts.print')

@section('title', '受付アンケートのご案内')

@section('content')
{{-- 受付アンケート案内画面（S-0311。2026-10 追加。要件定義書 6-16-8）。
     マイページ登録案内の印刷画面（S-0307、clients/email-registration-token-print.blade.php）と同じ作り：
     印刷専用レイアウト・ページ上部の操作エリア（「« 戻る」と「文面をコピー」。d-print-none で印刷時は
     出さない。S-0307 と S-0311 の 2 画面だけに認めた例外）・QR コード（qr-code.js）・文面のコピー
     （copy-message.js）。S-0307 との違い：有効期限を載せない（代わりに「1回かぎり」の文）、愛犬の名前の行、
     手順の中身、戻り先は常に会員詳細。お客様の氏名は載せない（紛失時に氏名と URL が同時に漏れないため）。
     変数：$trainee、$url（有効な案内がなければ null）、$copyText（同） --}}
<div class="print-actions d-print-none d-flex flex-wrap gap-2 mb-3">
    {{-- 戻り先は会員詳細（S-0305）。案内の操作は会員詳細だけで行うため --}}
    <a href="{{ route('clients.show', $trainee->client_id) }}" class="btn btn-outline-secondary btn-sm">&laquo; 戻る</a>
    @if($url)
        {{-- 文面はコントローラ（TraineeIntakeSurveyTokenController::buildCopyText）で組み立て、
             data-copy-text で受け取る（S-0307 と同じ）。ボタンは copy-message.js が拾う --}}
        <button type="button"
                class="btn btn-outline-primary btn-sm"
                data-copy-message
                data-copy-text="{{ $copyText }}">文面をコピー</button>
    @endif
</div>

@if($url)
    <h1 class="print-title">受付アンケートのご案内</h1>

    {{-- 名前があれば「モモちゃんのことを…」、未登録なら「愛犬のことを…」（「ちゃん」を付けない。
         会員側用の呼び名のアクセサ Trainee::client_call_name。§2-4 の例外） --}}
    <p class="print-lead">{{ $trainee->client_call_name }}のことを教えてください</p>

    <div class="print-qr">
        <canvas data-qr-url="{{ $url }}" data-qr-size="240"
                aria-label="受付アンケート案内の QR コード"></canvas>
    </div>

    <p class="print-lead">
        上の QR コードを読み取るか、下の URL をブラウザに入力してください。
    </p>

    <div class="print-url">{{ $url }}</div>

    <hr class="print-divider">

    <p class="print-section-title">ご回答の手順</p>
    <ol class="print-steps">
        <li>上の QR コードを読み取ります</li>
        <li>開いたページで、愛犬のことと暮らしの様子をご記入ください</li>
        <li>「送信する」を押すと完了です</li>
    </ol>

    <hr class="print-divider">

    {{-- 有効期限は持たない（要件定義書 6-15-17 備考）。S-0307 の有効期限の位置に置く --}}
    <p class="print-lead">ご回答は1回かぎりです。送信すると、この案内は使えなくなります。</p>

    {{-- 訓練所名は config('app.client_portal_company')。未設定なら出力しない（S-0307 と同じ） --}}
    @if(config('app.client_portal_company'))
        <p class="print-company">{{ config('app.client_portal_company') }}</p>
    @endif
@else
    {{-- 有効な案内なし（未発行・取消済み・回答済みのいずれでも同じ）。発行できる場所には触れない。
         ページ上部の「« 戻る」リンクだけを残す（S-0307 と同じ見せ方） --}}
    <h1 class="print-title">有効な受付アンケート案内がありません。</h1>
@endif
@endsection

@if($url)
    @push('scripts')
        @vite(['resources/js/qr-code.js', 'resources/js/copy-message.js'])
    @endpush
@endif
