@extends('layouts.app')

@section('title', 'トレーナー編集')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4" style="max-width: 700px;">
                <h2 class="mb-0">トレーナー編集</h2>
                <div class="d-flex gap-2">
                    <button type="submit" form="trainer-edit-form" class="btn btn-success">更新</button>
                    <a href="{{ route('trainers.index') }}" class="btn btn-secondary">キャンセル</a>
                </div>
            </div>

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。 --}}
            <form id="trainer-edit-form" method="POST" action="{{ route('trainers.update', $trainer) }}" novalidate>
                @csrf
                @method('PUT')

                {{-- 画面上部の 1 文の案内（設計書 §2-7） --}}
                <x-form-error-summary />

                {{-- §4-5 の横並び（ラベルを左〔幅 140px・右寄せ〕、入力欄を右）。2026-10 に縦積みからそろえた（登録〔S-0801〕と同じ形）。
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
                               value="{{ old('login_id', $trainer->login_id) }}" maxlength="50" required
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
                               inputmode="text"
                               value="{{ old('name', $trainer->name) }}" maxlength="100" required
                               style="max-width: 700px;">
                        <x-form-error field="name" />
                    </div>
                </div>

                <div class="row g-2 align-items-center">
                    <label for="role" class="col-md-auto col-form-label text-md-end form-label-fixed">権限 <span class="text-danger">*</span></label>
                    <div class="col-12 col-md">
                        <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" required
                                style="max-width: 250px;">
                            <option value="staff" {{ old('role', $trainer->role) === 'staff' ? 'selected' : '' }}>一般</option>
                            <option value="admin" {{ old('role', $trainer->role) === 'admin' ? 'selected' : '' }}>管理者</option>
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
