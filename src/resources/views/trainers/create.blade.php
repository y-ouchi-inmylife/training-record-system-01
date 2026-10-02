@extends('layouts.app')

@section('title', 'トレーナー登録')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4" style="max-width: 700px;">
                <h2 class="mb-0">トレーナー登録</h2>
                <div class="d-flex gap-2">
                    <button type="submit" form="trainer-create-form" class="btn btn-success">登録</button>
                    <a href="{{ route('trainers.index') }}" class="btn btn-secondary">キャンセル</a>
                </div>
            </div>

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 required / minlength / maxlength などの属性は残す。 --}}
            <form id="trainer-create-form" method="POST" action="{{ route('trainers.store') }}" novalidate>
                @csrf

                {{-- 画面上部の 1 文の案内（設計書 §2-7）。個々のエラーは各欄の下に出す --}}
                <x-form-error-summary />

                <div class="mb-3">
                    <label for="login_id" class="form-label">
                        ログインID <span class="text-danger">*</span>
                        <span class="form-text">※半角英数字とアンダースコア(_)のみ</span>
                    </label>
                    <input type="text" name="login_id" id="login_id"
                           class="form-control @error('login_id') is-invalid @enderror"
                           value="{{ old('login_id') }}" maxlength="50" required
                           style="max-width: 700px;">
                    <x-form-error field="login_id" />
                </div>

                <div class="mb-3">
                    <label for="name" class="form-label">名前 <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="name"
                           class="form-control @error('name') is-invalid @enderror"
                           inputmode="text" value="{{ old('name') }}" maxlength="100" required
                           style="max-width: 700px;">
                    <x-form-error field="name" />
                </div>


                <div class="mb-3">
                    <label for="password" class="form-label">
                        パスワード <span class="text-danger">*</span>
                        <span class="form-text">※初回ログイン時に変更が求められます。</span>
                    </label>
                    <input type="password" name="password" id="password"
                           class="form-control @error('password') is-invalid @enderror"
                           minlength="8" required
                           style="max-width: 700px;">
                    <x-form-error field="password" />
                </div>

                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">パスワード（確認） <span class="text-danger">*</span></label>
                    {{-- 確認用の欄に対するエラー文言は confirmed ルールが password 側に出すため、
                         ここは is-invalid・<x-form-error> とも置かない（他画面と同じ扱い）。 --}}
                    <input type="password" name="password_confirmation" id="password_confirmation"
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

                <div class="mb-4">
                    <label for="role" class="form-label">権限 <span class="text-danger">*</span></label>
                    <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" required
                            style="max-width: 250px;">
                        <option value="staff" {{ old('role', 'staff') === 'staff' ? 'selected' : '' }}>一般</option>
                        <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>管理者</option>
                    </select>
                    <x-form-error field="role" />
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
