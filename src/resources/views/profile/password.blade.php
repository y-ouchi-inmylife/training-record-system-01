@extends('layouts.app')

@section('title', 'パスワード変更')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4" style="max-width: 700px;">
                <h2 class="mb-0">パスワード変更</h2>
                <div class="d-flex gap-2">
                    <button type="submit" form="profile-password-form" class="btn btn-success">更新</button>
                    <a href="{{ route('profile.edit') }}" class="btn btn-secondary">キャンセル</a>
                </div>
            </div>

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 本画面はこのフォーム 1 つだけ（プロフィール本体は別画面 S-1201）。 --}}
            <form id="profile-password-form" method="POST" action="{{ route('profile.password') }}" novalidate>
                @csrf
                @method('PUT')

                {{-- 画面上部の 1 文の案内（設計書 §2-7） --}}
                <x-form-error-summary />

                <div class="mb-3">
                    <label for="current_password" class="form-label">現在のパスワード <span class="text-danger">*</span></label>
                    <input type="password" name="current_password" id="current_password"
                           class="form-control @error('current_password') is-invalid @enderror"
                           required
                           style="max-width: 700px;">
                    <x-form-error field="current_password" />
                </div>

                <div class="mb-3">
                    <label for="new_password" class="form-label">新しいパスワード <span class="text-danger">*</span></label>
                    <input type="password" name="new_password" id="new_password"
                           class="form-control @error('new_password') is-invalid @enderror"
                           minlength="8" required
                           style="max-width: 700px;">
                    <x-form-error field="new_password" />
                </div>

                <div class="mb-3">
                    <label for="new_password_confirmation" class="form-label">新しいパスワード（確認） <span class="text-danger">*</span></label>
                    {{-- 確認用の欄に対する confirmed の文言は new_password 側に出る（他画面と同じ扱い）--}}
                    <input type="password" name="new_password_confirmation" id="new_password_confirmation"
                           class="form-control" minlength="8" required
                           style="max-width: 700px;">
                </div>

                <div class="mb-3">
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
