{{-- エラーページのカードの中身（2026-10-21 追加。layouts/error.blade.php から、会員様側・トレーナー側の両方で読み込む）。
     見出し・本文・（トレーナー側の 403 の理由の文）・［ログイン画面へ］・エラー番号 --}}
<div class="card-body p-4">
    <h1 class="h5 fw-bold mb-3" data-error-heading>{{ $heading }}</h1>
    <p class="mb-0" data-error-body>{{ $body }}</p>
    @if($reason)
        <p class="mt-2 mb-0" data-error-reason>{{ $reason }}</p>
    @endif
    @if($showButton)
        <div class="text-center mt-4">
            <a href="{{ $loginUrl }}" class="btn btn-primary" data-error-login>{{ __('messages.error_pages.login') }}</a>
        </div>
    @endif
    <p class="text-end text-muted small mt-4 mb-0" data-error-code>{{ __('messages.error_pages.code', ['status' => $status]) }}</p>
</div>
