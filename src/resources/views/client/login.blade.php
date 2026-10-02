@extends('layouts.client')

@php
    // プロダクト名は config 経由で供給(設計書 §9-1)。暫定値は「トレーニング記録」。
    // 会社名は env が未設定なら null になり、Blade 側でフッターごと出さない(§9-2)。
    $portalName = config('app.client_portal_name', 'トレーニング記録');
    $companyName = config('app.client_portal_company');
@endphp

@section('title', 'ログイン')

@section('content')
{{-- ワードマークは layouts.partials.client-nav（ヘッダー帯の左）に移動。
     カード外側にワードマークを繰り返さない。 --}}
<div class="c-login">
    <div class="card c-login-card">
        <div class="card-body p-4">
            {{-- 認証の失敗は専用のキー login でフォームの上に出す（設計書 §2-7
                 「ログインの認証の失敗・アカウントの無効化・ロックの出し方」）。
                 入力欄の is-invalid や欄下文言、上部の案内（入力エラー用）は付かない。 --}}
            @error('login')
                <div class="alert alert-danger" role="alert">{{ $message }}</div>
            @enderror

            {{-- 入力エラーの上部案内（email・password の必須・形式などの本当の入力エラー）。
                 認証失敗（キー login）は上部に別で出すため、ここでは入力項目のキーに絞る。 --}}
            @if($errors->hasAny(['email', 'password']))
                <x-form-error-summary />
            @endif

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
            <form method="POST" action="{{ route('client-portal.login') }}" novalidate>
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
                        autocomplete="username"
                    >
                    <x-form-error field="email" />
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">パスワード</label>
                    <input
                        type="password"
                        class="form-control @error('password') is-invalid @enderror"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >
                    <x-form-error field="password" />
                </div>

                <div class="d-grid">
                    {{-- 動詞は能動態(設計書「文体の基本」)。btn-primary は
                         SCSS の変数上書きで cobalt 面 + mat 文字になる --}}
                    <button type="submit" class="btn btn-primary">ログインする</button>
                </div>
            </form>
        </div>
    </div>

    {{-- カード下の 2 種類の案内（設計書 §4-3）：
         1. 「パスワードを忘れた方」を先に置く（S-1407 への文字リンク）
         2. 空行を挟み「初めてご利用の方は…」のヘルプ文を後に置く
         並び順の根拠：本画面は「初回設定を終えたお客様が 2 回目以降にログインする画面」
         の位置づけ。パスワード忘れの救済導線を先、初めて訪れた方向けのヘルプは後 --}}
    <p class="c-login-help">
        <a href="{{ route('client-portal.password-reset.request.show') }}" class="btn btn-link btn-sm">パスワードを忘れた方</a>
    </p>

    <p class="c-login-help">初めてご利用の方は、担当トレーナーから受け取った URL からお進みください。</p>

    {{-- フッター(会社名/年): config('app.client_portal_company') が未設定なら
         ブロックごと出力しない(設計書 §9-2)。カードの位置は上余白基準なので、
         フッターの有無でレイアウトが崩れない。 --}}
    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>
@endsection
