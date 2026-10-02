@extends('layouts.client-public')

@php
    $portalName = config('app.client_portal_name', 'トレーニング記録');
    $companyName = config('app.client_portal_company');
@endphp

@section('title', 'パスワードの再設定')

@section('content')
{{-- pre-auth シェル。項目 2 つのため既定幅 26rem で足りる（設計書 §4-11）。
     ワードマークは layouts.partials.client-nav（ヘッダー帯の左）に移動。 --}}
<div class="c-login">
    <div class="card c-login-card">
        <div class="card-body p-4">
            <h2 class="c-auth-heading">パスワードの再設定</h2>
            <p class="c-auth-lead">
                新しいパスワードを設定してください。
            </p>

            {{-- 入力エラーの上部案内（必須・強度・一致の違反などの本当の入力エラー。設計書 §2-7）--}}
            <x-form-error-summary />

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
            <form method="POST" action="{{ route('client-portal.password-reset.reset.save', ['token' => $token]) }}" novalidate>
                @csrf

                <div class="mb-3">
                    <label for="new_password" class="form-label">新しいパスワード</label>
                    <input
                        type="password"
                        class="form-control @error('new_password') is-invalid @enderror"
                        id="new_password"
                        name="new_password"
                        required
                        autofocus
                        autocomplete="new-password"
                        aria-describedby="new_password_help"
                    >
                    {{-- 強度要件のヘルプ文は <x-form-error> の下に置くと、エラーが出たとき欄との
                         間にヘルプ文が挟まる。Bootstrap の .is-invalid ~ .invalid-feedback の
                         セレクタは「以後の兄弟」なので、ヘルプ文が間に挟まっても表示される。
                         block は付けない。 --}}
                    <x-form-error field="new_password" />
                    {{-- 強度要件のヘルプ文（S-1403 初回設定と同じ文言）--}}
                    <div id="new_password_help" class="form-text">
                        8 文字以上で、大文字・小文字・数字・記号をそれぞれ 1 つ以上入れてください。
                    </div>
                </div>

                <div class="mb-3">
                    <label for="new_password_confirmation" class="form-label">新しいパスワード（確認）</label>
                    {{-- 確認用の欄に対する confirmed の文言は new_password 側に出る（他画面と同じ扱い）--}}
                    <input
                        type="password"
                        class="form-control"
                        id="new_password_confirmation"
                        name="new_password_confirmation"
                        required
                        autocomplete="new-password"
                    >
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">設定する</button>
                </div>
            </form>
        </div>
    </div>

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
