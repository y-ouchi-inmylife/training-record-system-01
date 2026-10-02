@extends('layouts.app')

@section('title', 'パスワードリセット')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4" style="max-width: 700px;">
                <h2 class="mb-0">パスワードリセット</h2>
                <div class="d-flex gap-2">
                    <button type="submit" form="trainer-reset-password-form" class="btn btn-success"
                            onclick="return confirm('{{ $trainer->name }} のパスワードをリセットしますか？')">更新</button>
                    <a href="{{ route('trainers.index') }}" class="btn btn-secondary">キャンセル</a>
                </div>
            </div>

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 送信する項目の名前は new_password / new_password_confirmation（段階 3-4 で
                 他のパスワード変更画面と揃えた。設計書 S-0804）。 --}}
            <form id="trainer-reset-password-form" method="POST" action="{{ route('trainers.reset-password.update', $trainer) }}" novalidate>
                @csrf
                @method('PUT')

                {{-- 画面上部の 1 文の案内（設計書 §2-7） --}}
                <x-form-error-summary />

                <div class="mb-3">
                    <label for="login_id" class="form-label">ログインID</label>
                    <input type="text" id="login_id" class="form-control"
                           value="{{ $trainer->login_id }}" disabled
                           style="max-width: 700px;">
                </div>

                <div class="mb-3">
                    <label for="trainer_name" class="form-label">名前</label>
                    <input type="text" id="trainer_name" class="form-control"
                           value="{{ $trainer->name }}" disabled
                           style="max-width: 700px;">
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
