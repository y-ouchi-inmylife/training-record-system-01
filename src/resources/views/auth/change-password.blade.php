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

                {{-- §4-5 の横並び（ラベルを左〔幅 140px・右寄せ〕、入力欄を右）。2026-10 に縦積みからそろえた
                     （トレーナーの登録・編集〔S-0801・S-0803〕と同じ形）。見出しのボタンと右端をそろえるため、
                     全体を幅 700px に収め、1 行 1 項目にする。説明文（form-text）は入力欄の下、エラーの赤字の
                     さらに下に置き、説明文のある行は align-items-start にする。スマホの幅ではラベルが上に積まれる --}}
                <div style="max-width: 700px;">
                <div class="row g-2 align-items-center mb-2">
                    <label for="new_password" class="col-md-auto col-form-label text-md-end form-label-fixed">新しいパスワード <span class="text-danger">*</span></label>
                    <div class="col-12 col-md">
                        <input type="password" name="new_password" id="new_password"
                               class="form-control @error('new_password') is-invalid @enderror"
                               minlength="8" required
                               autocomplete="new-password"
                               style="max-width: 700px;">
                        <x-form-error field="new_password" />
                    </div>
                </div>

                <div class="row g-2 align-items-start">
                    {{-- 見える文言は「（確認）」だけにする（「新しいパスワード（確認）」は幅 140px に 1 行で収まらないため。
                         すぐ上の「新しいパスワード」の確認の欄であることは並びで分かる）。読み上げでは
                         見えない「新しいパスワード」と合わせて「新しいパスワード（確認）」と読まれる --}}
                    <label for="new_password_confirmation" class="col-md-auto col-form-label text-md-end form-label-fixed"><span class="visually-hidden">新しいパスワード</span>（確認） <span class="text-danger">*</span></label>
                    <div class="col-12 col-md">
                        {{-- 確認用の欄に対する confirmed の文言は new_password 側に出る（他画面と同じ扱い）--}}
                        <input type="password" name="new_password_confirmation" id="new_password_confirmation"
                               class="form-control" minlength="8" required
                               autocomplete="new-password"
                               style="max-width: 700px;">
                        {{-- パスワード要件は、以前と同じくこの欄のあとに置く（項目の並び順を変えない） --}}
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
                </div>{{-- 幅 700px のまとまりの終わり --}}
            </form>
        </div>
    </div>
</div>
@endsection
