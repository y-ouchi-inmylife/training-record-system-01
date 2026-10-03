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

                {{-- §4-5 の横並び（ラベルを左〔幅 140px・右寄せ〕、入力欄を右）。2026-10 に縦積みからそろえた。
                     見出しのボタンと右端をそろえるため、全体を幅 700px に収め、1 行 1 項目にする
                     （2 項目を並べると入力欄が 200px ほどになり、説明文が折り返すため）。
                     説明文（form-text）は入力欄の下、エラーの赤字のさらに下に置く。説明文のある行は
                     align-items-start にして、ラベルを入力欄の高さにそろえる。スマホの幅ではラベルが上に積まれる --}}
                <div style="max-width: 700px;">
                <div class="row g-2 align-items-start mb-2">
                    <label for="login_id" class="col-md-auto col-form-label text-md-end form-label-fixed">
                        ログインID <span class="text-danger">*</span>
                    </label>
                    <div class="col-12 col-md">
                        <input type="text" name="login_id" id="login_id"
                               class="form-control @error('login_id') is-invalid @enderror"
                               value="{{ old('login_id') }}" maxlength="50" required
                               style="max-width: 700px;">
                        <x-form-error field="login_id" />
                        <div class="form-text">※半角英数字とアンダースコア(_)のみ</div>
                    </div>
                </div>

                <div class="row g-2 align-items-center mb-2">
                    <label for="name" class="col-md-auto col-form-label text-md-end form-label-fixed">名前 <span class="text-danger">*</span></label>
                    <div class="col-12 col-md">
                        <input type="text" name="name" id="name"
                               class="form-control @error('name') is-invalid @enderror"
                               inputmode="text" value="{{ old('name') }}" maxlength="100" required
                               style="max-width: 700px;">
                        <x-form-error field="name" />
                    </div>
                </div>

                <div class="row g-2 align-items-start mb-2">
                    <label for="password" class="col-md-auto col-form-label text-md-end form-label-fixed">
                        パスワード <span class="text-danger">*</span>
                    </label>
                    <div class="col-12 col-md">
                        <input type="password" name="password" id="password"
                               class="form-control @error('password') is-invalid @enderror"
                               minlength="8" required
                               style="max-width: 700px;">
                        <x-form-error field="password" />
                        <div class="form-text">※初回ログイン時に変更が求められます。</div>
                    </div>
                </div>

                <div class="row g-2 align-items-start mb-2">
                    <label for="password_confirmation" class="col-md-auto col-form-label text-md-end form-label-fixed">パスワード（確認） <span class="text-danger">*</span></label>
                    <div class="col-12 col-md">
                        {{-- 確認用の欄に対するエラー文言は confirmed ルールが password 側に出すため、
                             ここは is-invalid・<x-form-error> とも置かない（他画面と同じ扱い）。 --}}
                        <input type="password" name="password_confirmation" id="password_confirmation"
                               class="form-control" minlength="8" required
                               style="max-width: 700px;">
                        {{-- パスワード要件は、以前と同じくパスワード（確認）のあとに置く（項目の並び順を変えない） --}}
                        <div class="form-text">
                            パスワード要件：
                            <ul class="mb-0">
                                <li>8文字以上</li>
                                <li>大文字、小文字、数字、記号をそれぞれ1文字以上含む</li>
                                <li>よく使われるパスワードは使用できません</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="row g-2 align-items-center">
                    <label for="role" class="col-md-auto col-form-label text-md-end form-label-fixed">権限 <span class="text-danger">*</span></label>
                    <div class="col-12 col-md">
                        <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" required
                                style="max-width: 250px;">
                            <option value="staff" {{ old('role', 'staff') === 'staff' ? 'selected' : '' }}>一般</option>
                            <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>管理者</option>
                        </select>
                        <x-form-error field="role" />
                    </div>
                </div>
                </div>{{-- 幅 700px のまとまりの終わり --}}
            </form>
        </div>
    </div>
</div>
@endsection
