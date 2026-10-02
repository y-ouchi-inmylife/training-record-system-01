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

                <div class="mb-3">
                    <label for="login_id" class="form-label">
                        ログインID <span class="text-danger">*</span>
                        <span class="form-text">※半角英数字とアンダースコア(_)のみ</span>
                    </label>
                    <input type="text" name="login_id" id="login_id"
                           class="form-control @error('login_id') is-invalid @enderror"
                           value="{{ old('login_id', $trainer->login_id) }}" maxlength="50" required
                           style="max-width: 700px;">
                    <x-form-error field="login_id" />
                </div>

                <div class="mb-3">
                    <label for="name" class="form-label">名前 <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="name"
                           class="form-control @error('name') is-invalid @enderror"
                           inputmode="text"
                           value="{{ old('name', $trainer->name) }}" maxlength="100" required
                           style="max-width: 700px;">
                    <x-form-error field="name" />
                </div>

                <div class="mb-4">
                    <label for="role" class="form-label">権限 <span class="text-danger">*</span></label>
                    <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" required
                            style="max-width: 250px;">
                        <option value="staff" {{ old('role', $trainer->role) === 'staff' ? 'selected' : '' }}>一般</option>
                        <option value="admin" {{ old('role', $trainer->role) === 'admin' ? 'selected' : '' }}>管理者</option>
                    </select>
                    <x-form-error field="role" />
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
