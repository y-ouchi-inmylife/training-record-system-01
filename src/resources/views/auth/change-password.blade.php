@extends('layouts.app')

@section('title', 'パスワード変更')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4" style="max-width: 700px;">
                <h2 class="mb-0">パスワード変更</h2>
                <div class="d-flex gap-2">
                    <button type="submit" form="change-password-form" class="btn btn-success">更新</button>
                </div>
            </div>

            @if(auth()->user()->must_change_password)
                <div class="alert alert-warning" style="max-width: 700px;">
                    初回ログインのため、パスワードを変更してください。
                </div>
            @endif

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
            <form id="change-password-form" method="POST" action="{{ route('password.update') }}" novalidate>
                @csrf

                {{-- 画面上部の 1 文の案内（設計書 §2-7） --}}
                <x-form-error-summary />

                {{-- パスワードマネージャー向け username（保存パスワードの紐付け先。会員側のパスワードの変更と同じ形）。
                     name は付けない（送信の中身を変えないため。autocomplete はブラウザが読むだけで、送信には要らない） --}}
                <input type="text" value="{{ auth()->user()->login_id }}"
                       autocomplete="username" readonly tabindex="-1" aria-hidden="true"
                       class="visually-hidden">

                <div class="mb-3">
                    <label for="new_password" class="form-label">新しいパスワード <span class="text-danger">*</span></label>
                    <input type="password" name="new_password" id="new_password"
                           class="form-control @error('new_password') is-invalid @enderror"
                           minlength="8" required
                           autocomplete="new-password"
                           style="max-width: 700px;">
                    <x-form-error field="new_password" />
                </div>

                <div class="mb-3">
                    <label for="new_password_confirmation" class="form-label">新しいパスワード（確認） <span class="text-danger">*</span></label>
                    {{-- 確認用の欄に対する confirmed の文言は new_password 側に出る（他画面と同じ扱い）--}}
                    <input type="password" name="new_password_confirmation" id="new_password_confirmation"
                           class="form-control" minlength="8" required
                           autocomplete="new-password"
                           style="max-width: 700px;">
                </div>

                <div class="mb-4">
                    <div class="form-text">
                        パスワード要件：
                        <ul class="mb-0">
                            <li>8文字以上</li>
                            <li>大文字、小文字、数字、記号をそれぞれ1文字以上含む</li>
                            <li>よく使われるパスワードは使用できません</li>
                        </ul>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
