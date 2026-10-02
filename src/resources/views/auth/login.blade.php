@extends('layouts.guest')

@section('title', 'ログイン')

@section('content')
<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow">
                <div class="card-body p-4">
                    <div class="d-flex flex-column align-items-center justify-content-center gap-3 mb-4">
                        <img src="{{ asset('images/inmylife-logo.png') }}"
                             alt="株式会社インマイライフ"
                             style="max-height: 48px; width: auto;">
                        <h4 class="mb-0 fs-5">トレーニング記録管理システム</h4>
                    </div>

                    {{-- 認証の失敗・無効化・ロックは専用のキー login でフォームの上に出す
                         （設計書 §2-7「ログインの認証の失敗・アカウントの無効化・ロックの出し方」）。
                         入力欄の is-invalid や欄下文言、上部の案内（入力エラー用）は付かない。 --}}
                    @error('login')
                        <div class="alert alert-danger" role="alert">{{ $message }}</div>
                    @enderror

                    {{-- 入力エラーの上部の案内（login_id・password の必須などの本当の入力エラーがあるとき）。
                         認証失敗（キー login）は上部に別で出すため、ここでは判定を login_id/password に絞る。 --}}
                    @if($errors->hasAny(['login_id', 'password']))
                        <x-form-error-summary />
                    @endif

                    {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
                    <form method="POST" action="{{ url('/login') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="login_id" class="form-label">ログインID</label>
                            <input
                                type="text"
                                class="form-control @error('login_id') is-invalid @enderror"
                                id="login_id"
                                name="login_id"
                                value="{{ old('login_id') }}"
                                required
                                autofocus
                            >
                            <x-form-error field="login_id" />
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">パスワード</label>
                            <input
                                type="password"
                                class="form-control @error('password') is-invalid @enderror"
                                id="password"
                                name="password"
                                required
                            >
                            <x-form-error field="password" />
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">ログイン</button>
                        </div>
                    </form>
                </div>
            </div>
            <p class="text-center text-muted mt-3 small">
                &copy; {{ date('Y') }} 株式会社インマイライフ
            </p>
        </div>
    </div>
</div>
@endsection
