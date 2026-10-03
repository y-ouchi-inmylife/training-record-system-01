@extends('layouts.app')

@section('title', 'マイプロフィール')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4" style="max-width: 700px;">
                <h2 class="mb-0">マイプロフィール</h2>
                <div class="d-flex gap-2">
                    <button type="submit" form="profile-edit-form" class="btn btn-success">更新</button>
                </div>
            </div>

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 本画面はこのフォーム 1 つだけ（パスワード変更は別画面 S-1202）。 --}}
            <form id="profile-edit-form" method="POST" action="{{ route('profile.update') }}" novalidate>
                @csrf
                @method('PUT')

                {{-- 画面上部の 1 文の案内（設計書 §2-7） --}}
                <x-form-error-summary />

                {{-- §4-5 の横並び（ラベルを左〔幅 140px・右寄せ〕、入力欄を右）。2026-10 に縦積みからそろえた
                     （トレーナーの登録・編集〔S-0801・S-0803〕と同じ形）。見出しのボタンと右端をそろえるため、
                     全体を幅 700px に収め、1 行 1 項目にする。説明文（form-text）は入力欄の下、エラーの赤字の
                     さらに下に置き、説明文のある行は align-items-start にする。スマホの幅ではラベルが上に積まれる --}}
                <div style="max-width: 700px;">
                <div class="row g-2 align-items-center mb-2">
                    <label for="login_id" class="col-md-auto col-form-label text-md-end form-label-fixed">ログインID</label>
                    <div class="col-12 col-md">
                        <input type="text" id="login_id" class="form-control"
                               value="{{ $trainer->login_id }}" disabled
                               style="max-width: 700px;">
                    </div>
                </div>

                <div class="row g-2 align-items-center mb-2">
                    <label for="name" class="col-md-auto col-form-label text-md-end form-label-fixed">名前 <span class="text-danger">*</span></label>
                    <div class="col-12 col-md">
                        <input type="text" name="name" id="name"
                               class="form-control @error('name') is-invalid @enderror"
                               inputmode="text"
                               value="{{ old('name', $trainer->name) }}" maxlength="100" required
                               style="max-width: 700px;">
                        <x-form-error field="name" />
                    </div>
                </div>

                <div class="row g-2 align-items-center">
                    <label for="role" class="col-md-auto col-form-label text-md-end form-label-fixed">権限</label>
                    <div class="col-12 col-md">
                        <input type="text" id="role" class="form-control"
                               value="{{ $trainer->role_display_name }}" disabled
                               style="max-width: 700px;">
                    </div>
                </div>
                </div>{{-- 幅 700px のまとまりの終わり --}}
            </form>

            {{-- 「パスワード変更」のリンクは、入力欄の列に左端をそろえる（ラベルの幅の空きを左に取る。
                 スマホの幅では空きを消す。検索フォームの日付のエラーの行と同じ形）。
                 縦の余白（mt-4）とぶつからないよう、横のガターだけの gx-2 にする --}}
            <div class="row gx-2 mt-4" style="max-width: 700px;">
                <div class="col-md-auto form-label-fixed d-none d-md-block"></div>
                <div class="col-12 col-md">
                    <a href="{{ route('profile.password.edit') }}">パスワード変更</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
