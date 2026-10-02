@extends('layouts.client-public')

@php
    // ログイン画面と同じ config キー（§9-1・§9-2）を流用
    $portalName = config('app.client_portal_name', 'トレーニング記録');
    $companyName = config('app.client_portal_company');
    // 完了状態は $submittedEmail が非 null のとき
    $isDone = !empty($submittedEmail);
@endphp

@section('title', $isDone ? 'メールをお送りしました' : 'マイページのご登録')

@section('content')
{{-- pre-auth シェル（ログイン・初回設定と共通）。入力状態と完了状態を同じ画面で切り替える。
     ワードマークは layouts.partials.client-nav（ヘッダー帯の左）に移動。 --}}
<div class="c-login">
    <div class="card c-login-card">
        <div class="card-body p-4">
            @if($isDone)
                {{-- 完了状態。
                     案内文（下）と「メールアドレス入力に戻る」ボタン（さらに下）の 2 経路で
                     入力し直しに戻れる。ボタンは同じトークンの GET ルートに戻る `<a>` タグで、
                     押下時の挙動は EmailRegistrationController::showByToken() が
                     `submittedEmail => null` を渡すことで入力状態を再描画する（未変更）。
                     お客様が案内 URL を再アクセスした場合と同じ経路。 --}}
                <h2 class="c-auth-heading">メールをお送りしました</h2>
                <p class="c-auth-lead">
                    <strong class="c-auth-email">{{ $submittedEmail }}</strong> に
                    ログイン用のリンクをお送りしました。
                </p>
                <p class="c-auth-lead">
                    メールが届かない場合は、メールアドレスに誤りがある可能性があります。お渡しした URL から、もう一度ご登録ください。
                </p>

                <div class="d-grid">
                    <a href="{{ route('client-portal.email-registration.show', ['token' => $token]) }}"
                       class="btn btn-outline-secondary">メールアドレス入力に戻る</a>
                </div>
            @else
                {{-- 入力状態 --}}
                <h2 class="c-auth-heading">マイページのご登録</h2>
                <p class="c-auth-lead">
                    マイページのご登録のため、ご自身のメールアドレスを入力してください。
                </p>

                {{-- メール送信の失敗は専用のキー form でフォームの上に出す（設計書 §2-7
                     「入力エラー以外の失敗を入力の項目のキーで返さないルール」）。
                     入力欄の is-invalid や欄下文言、上部の案内（入力エラー用）は付かない。 --}}
                @error('form')
                    <div class="alert alert-danger" role="alert">{{ $message }}</div>
                @enderror

                {{-- 入力エラーの上部案内（email の必須・形式・unique などの本当の入力エラー）。
                     form キーの失敗は上部に別で出すため、ここでは入力項目のキーに絞る。 --}}
                @if ($errors->hasAny(['email']))
                    <x-form-error-summary />
                @endif

                {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
                <form method="POST" action="{{ route('client-portal.email-registration.store', ['token' => $token]) }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="email" class="form-label">メールアドレス</label>
                        <input
                            type="email"
                            class="form-control @error('email') is-invalid @enderror"
                            id="email"
                            name="email"
                            value="{{ old('email') }}"
                            required
                            autofocus
                            autocomplete="email"
                        >
                        <x-form-error field="email" />
                    </div>

                    @include('layouts.partials.privacy-consent')

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">登録して進む</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
