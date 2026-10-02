@extends('layouts.client')

@section('title', 'メールアドレスの変更')

@section('content')
<div class="container">
    <div class="c-settings">
        {{-- 戻る導線：「← 戻る」の形で置く（3 変更画面 S-1409/S-1410/S-1411 で共通）。
             画面名を書かないのは、将来この画面への入口が増えても文言を直さずに済むため。
             矢印は装飾なので aria-hidden にし、「戻る」の文字を読み上げソフトに読ませる。
             設計書：`client-portal-design-plan.md` §4-10、`screen-design.md` §6。 --}}
        <a href="{{ route('client-portal.profile.show') }}" class="c-detail-back">
            <span aria-hidden="true">←</span> 戻る
        </a>

        <h1 class="mb-4">メールアドレスの変更</h1>

        <div class="card mb-4">
            <div class="card-body p-4">
                {{-- 補足説明：確認メール経由の流れは珍しいので、画面内で流れを説明する --}}
                <p class="text-muted mb-4">
                    新しいアドレスに確認メールを送ります。メールのリンクを開くと、メールアドレスが切り替わります。
                </p>

                {{-- 入力エラーの上部案内（必須・形式・重複・現在のパスワード不一致などの入力エラー。設計書 §2-7）。
                     本画面はコントローラーで入力エラー以外の失敗（送信失敗など）を withErrors で返していないため、
                     現状は入力項目のキーだけが来る。将来追加した場合に備えて hasAny で絞る。 --}}
                @if ($errors->hasAny(['new_email', 'current_password']))
                    <x-form-error-summary />
                @endif

                {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
                <form method="POST" action="{{ route('client-portal.settings.email.request') }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="new_email" class="form-label">新しいメールアドレス <span class="text-danger">*</span></label>
                        <input type="email" class="form-control @error('new_email') is-invalid @enderror"
                               id="new_email" name="new_email" required maxlength="255"
                               value="{{ old('new_email') }}"
                               autocomplete="email">
                        <x-form-error field="new_email" />
                    </div>
                    <div class="mb-3">
                        <label for="email_current_password" class="form-label">現在のパスワード <span class="text-danger">*</span></label>
                        <input type="password" class="form-control @error('current_password') is-invalid @enderror"
                               id="email_current_password" name="current_password" required
                               autocomplete="current-password">
                        <x-form-error field="current_password" />
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">確認メールを送る</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
