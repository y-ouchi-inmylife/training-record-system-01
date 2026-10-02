@extends('layouts.client-public')

@php
    $portalName = config('app.client_portal_name', 'トレーニング記録');
    $companyName = config('app.client_portal_company');
    // 送信完了状態は $submitted が true のとき
    $isDone = !empty($submitted);
@endphp

@section('title', $isDone ? 'メールをお送りしました' : 'パスワードの再設定')

@section('content')
{{-- pre-auth シェル（ログイン画面と共用）。項目 1 つのため既定幅 26rem で足りる。
     ワードマークは layouts.partials.client-nav（ヘッダー帯の左）に移動。 --}}
<div class="c-login">
    <div class="card c-login-card">
        <div class="card-body p-4">
            @if($isDone)
                {{-- 完了状態：登録の有無を出さない文言（決定事項 #1）。
                     入力したメールアドレスは表示しない（S-1405 とはここが異なる） --}}
                <h2 class="c-auth-heading">メールをお送りしました</h2>
                <p class="c-auth-lead">
                    ご登録のメールアドレスが確認できた場合は、パスワード再設定のご案内をお送りしました。メールをご確認ください。
                </p>
                <p class="c-auth-lead">
                    メールが届かない場合は、担当のトレーナーにご連絡ください。
                </p>

                <div class="d-grid">
                    <a href="{{ route('client-portal.login') }}"
                       class="btn btn-outline-secondary">← ログイン画面に戻る</a>
                </div>
            @else
                {{-- 入力状態 --}}
                <h2 class="c-auth-heading">パスワードの再設定</h2>
                <p class="c-auth-lead">
                    ご登録のメールアドレスを入力してください。
                </p>

                {{-- 入力エラーの上部案内（email の必須・形式などの本当の入力エラー。設計書 §2-7）--}}
                @if ($errors->hasAny(['email']))
                    <x-form-error-summary />
                @endif

                {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
                <form method="POST" action="{{ route('client-portal.password-reset.request.send') }}" novalidate>
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

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary">再設定のメールを送る</button>
                    </div>
                </form>

                <div class="text-center">
                    <a href="{{ route('client-portal.login') }}" class="btn btn-link btn-sm">← ログイン画面に戻る</a>
                </div>
            @endif
        </div>
    </div>

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
