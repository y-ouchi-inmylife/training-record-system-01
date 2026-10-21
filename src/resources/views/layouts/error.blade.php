{{-- エラーページの共通の部品（S-0103・S-0104 ほか。2026-10-21 変更。設計書 screen-design.md「エラーページの共通の作り」）。
     resources/views/errors/{番号}.blade.php（と受け皿の 4xx・5xx）から、番号（$status）を渡して読み込む。
     - 会員様側の URL で出たときは会員様側の見た目（client.scss・会員様側のヘッダー帯〔ログイン前の形〕・ログイン画面と同じ幅のカード）、
       トレーナー側の URL で出たときはトレーナー側の見た目（app.scss・紺のヘッダー帯にシステム名だけ・白いカード）。
       どちらとも決められないときは会員様側（App\Support\ErrorPageSide）
     - ログインの状態・データベースを読むもの（ヘッダーのメニュー・ログイン中の名前・フッターの HOME）は出さない。CSRF トークンも出さない
       （フォームがないため。セッションを読まない）。500・503 のときに、ここでまたエラーにならないように
     - 見出し・本文は日本語（lang/ja/messages.php の error_pages）。カードの下の端に小さく薄く「エラー番号：{番号}」
     - ボタンは［ログイン画面へ］の 1 つだけ（ふだんの主ボタン）。ログインしているかどうかで出し分けない。503（メンテナンス中）は出さない
     - 回答の画面（ErrorPageSide::isAnswerPage。2026-10-21 追加）では［ログイン画面へ］を出さず、本文の終わりに案内のリンクを開き直す文を足す。
       419 だけは本文を回答の画面用の文にする（会員様がログインしていないことが多いため）
     - 403 は、トレーナー側で abort(403, '…') の理由の文（日本語）があれば、本文の下にその文も出す（IP アドレスの制限・管理者だけの操作など。
       Laravel の英語の既定の文〔This action is unauthorized. など〕は出さない）
     その前（2026-10-21 まで）は、ヘッダーのない中立のレイアウトに、色の付いた帯と英語の見出しを出し、ボタンはなかった --}}
@php
    $status = (int) $status;
    $side = \App\Support\ErrorPageSide::resolve(request());
    $isClient = $side === \App\Support\ErrorPageSide::CLIENT;
    $pageKey = \Illuminate\Support\Facades\Lang::has('messages.error_pages.pages.'.$status)
        ? (string) $status
        : ($status >= 500 ? '5xx' : '4xx');
    $isAnswerPage = $isClient && \App\Support\ErrorPageSide::isAnswerPage(request());
    $heading = __('messages.error_pages.pages.'.$pageKey.'.title');
    $body = __('messages.error_pages.pages.'.$pageKey.'.body');
    if ($isAnswerPage) {
        $body = $status === 419
            ? __('messages.error_pages.answer_419')
            : $body.__('messages.error_pages.answer_suffix');
    }
    $reason = null;
    if (! $isClient && $status === 403 && isset($exception)) {
        $message = (string) $exception->getMessage();
        $reason = preg_match('/[^\x00-\x7F]/u', $message) === 1 ? $message : null;
    }
    $showButton = $status !== 503 && ! $isAnswerPage;
    $loginUrl = $isClient ? route('client-portal.login') : route('login');
    $portalName = $isClient ? config('app.client_portal_name', 'トレーニング記録') : config('app.trainer_portal_name');
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @if($isClient)
        <link rel="icon" href="/icons/favicon-client.ico" sizes="any">
        <link rel="icon" href="/icons/favicon-client.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon-client.png">
        <link rel="manifest" href="/manifest-client.json">
        <meta name="theme-color" content="#162D66">
    @else
        <link rel="icon" href="/icons/favicon-trainer.ico" sizes="any">
        <link rel="icon" href="/icons/favicon-trainer.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon-trainer.png">
        <link rel="manifest" href="/manifest-trainer.json">
        <meta name="theme-color" content="#0a4fa8">
    @endif
    <title>{{ $heading }} - {{ $portalName }}</title>
    @vite([$isClient ? 'resources/sass/client.scss' : 'resources/sass/app.scss', 'resources/js/app.js'])
</head>
<body data-error-page="{{ $status }}" data-error-side="{{ $side }}">
    @if($isClient)
        {{-- 会員様側のヘッダー帯（ログイン前の形：ロゴだけ。メニューは出さない） --}}
        @include('layouts.partials.client-nav', ['authed' => false])
    @else
        {{-- トレーナー側のヘッダー帯（ふだんの画面と同じ紺。システム名だけ。メニュー・ログイン中の名前は出さない） --}}
        <nav class="navbar navbar-dark bg-trainer-nav">
            <div class="container-fluid">
                <span class="navbar-brand mb-0">{{ $portalName }}</span>
            </div>
        </nav>
    @endif

    <main class="py-4">
        <div class="container">
            @if($isClient)
                {{-- 会員様側：ログイン画面と同じ幅のカード（.c-login） --}}
                <div class="c-login">
                    <div class="card c-login-card">
                        @include('layouts.partials.error-card-body')
                    </div>
                </div>
            @else
                {{-- トレーナー側：ふだんの画面の Bootstrap の列で真ん中に置く --}}
                <div class="row justify-content-center mt-4">
                    <div class="col-md-8 col-lg-6">
                        <div class="card shadow-sm">
                            @include('layouts.partials.error-card-body')
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </main>
</body>
</html>
