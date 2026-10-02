@extends('layouts.client')

@section('title', 'パスワードの変更')

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

        <h1 class="mb-4">パスワードの変更</h1>

        <div class="card mb-4">
            <div class="card-body p-4">
                {{-- 入力エラーの上部案内（現在のパスワード不一致・強度・一致違反などの入力エラー。設計書 §2-7）。
                     本画面はコントローラーで入力エラー以外の失敗（送信失敗など）を withErrors で返していないため、
                     現状は入力項目のキーだけが来る。将来追加した場合に備えて hasAny で絞る。 --}}
                @if ($errors->hasAny(['current_password', 'new_password']))
                    <x-form-error-summary />
                @endif

                {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
                <form method="POST" action="{{ route('client-portal.settings.password.update') }}" novalidate>
                    @csrf
                    @method('PUT')

                    {{-- パスワードマネージャー向け username（保存パスワードの紐付け先）--}}
                    <input type="email" name="username" value="{{ $client->email }}"
                           autocomplete="username" readonly tabindex="-1" aria-hidden="true"
                           class="visually-hidden">

                    <div class="mb-3">
                        <label for="pw_current" class="form-label">現在のパスワード <span class="text-danger">*</span></label>
                        <input type="password" class="form-control @error('current_password') is-invalid @enderror"
                               id="pw_current" name="current_password" required
                               autocomplete="current-password">
                        <x-form-error field="current_password" />
                    </div>
                    <div class="mb-3">
                        <label for="pw_new" class="form-label">新しいパスワード <span class="text-danger">*</span></label>
                        <input type="password" class="form-control @error('new_password') is-invalid @enderror"
                               id="pw_new" name="new_password" required
                               autocomplete="new-password" aria-describedby="pw_new_help">
                        {{-- 強度要件のヘルプ文は <x-form-error> の下に置くと、エラー時に欄との間にヘルプ文が
                             挟まる。Bootstrap の .is-invalid ~ .invalid-feedback の「以後の兄弟」セレクタで
                             ヘルプ文を挟んでも表示される。block は付けない。 --}}
                        <x-form-error field="new_password" />
                        <div id="pw_new_help" class="form-text">
                            8 文字以上で、大文字・小文字・数字・記号をそれぞれ 1 つ以上入れてください。
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="pw_new_confirm" class="form-label">新しいパスワード（確認） <span class="text-danger">*</span></label>
                        {{-- 確認用の欄に対する confirmed の文言は new_password 側に出る（他画面と同じ扱い）--}}
                        <input type="password" class="form-control"
                               id="pw_new_confirm" name="new_password_confirmation" required
                               autocomplete="new-password">
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">変更する</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
