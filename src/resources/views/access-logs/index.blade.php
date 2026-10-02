@extends('layouts.app')

@section('title', 'トレーナー操作履歴')

@section('content')
<div class="container">
    <h2 class="mb-4">トレーナー操作履歴</h2>

    {{-- 検索フォーム --}}
    <div class="card mb-4">
        <div class="card-body">
            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 日付の欄に pattern があるため、novalidate を付けないとブラウザの吹き出しが出うる。
                 本画面は小さなフォーム（日付の 2 欄だけがエラー対象）のため、上部の案内
                 （<x-form-error-summary />）は置かない（§2-7「画面上部の短い案内」の例外）。 --}}
            <form method="GET" action="{{ route('access-logs.index') }}" novalidate>
                {{-- 行1: トレーナー + 操作 --}}
                <div class="row g-3 mb-2">
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="trainer_id" class="col-md-auto col-form-label text-md-end form-label-fixed">トレーナー</label>
                            <div class="col-12 col-md">
                                <select class="form-select" id="trainer_id" name="trainer_id">
                                    <option value="">すべて</option>
                                    @foreach($trainers as $trainer)
                                        <option value="{{ $trainer->id }}" {{ request('trainer_id') == $trainer->id ? 'selected' : '' }}>
                                            {{ $trainer->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="action" class="col-md-auto col-form-label text-md-end form-label-fixed">操作</label>
                            <div class="col-12 col-md">
                                <select class="form-select" id="action" name="action">
                                    <option value="">すべて</option>
                                    @foreach(\App\Models\AccessLog::actionLabels() as $key => $label)
                                        <option value="{{ $key }}" {{ request('action') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 行2: 日付（範囲） --}}
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label class="col-md-auto col-form-label text-md-end form-label-fixed">日付</label>
                            <div class="col">
                                <input type="text" class="form-control datepicker @error('date_from') is-invalid @enderror" id="date_from" name="date_from"
                                       value="{{ old('date_from', request('date_from')) }}"
                                       placeholder="例: 2026-04-01"
                                       pattern="\d{4}-\d{2}-\d{2}"
                                       maxlength="10">
                            </div>
                            <div class="col-md-auto px-1">～</div>
                            <div class="col">
                                <input type="text" class="form-control datepicker @error('date_to') is-invalid @enderror" id="date_to" name="date_to"
                                       value="{{ old('date_to', request('date_to')) }}"
                                       placeholder="例: 2026-04-01"
                                       pattern="\d{4}-\d{2}-\d{2}"
                                       maxlength="10">
                            </div>
                        </div>
                        {{-- 日付の範囲のエラーの文言（§2-7「項目どうしの関係のチェック」の但し書き）。
                             各欄のすぐ下に出すと、狭い列で文言が折り返して入力欄の位置がずれるため、
                             範囲のまとまり（日付行）の下に 1 行で出す。左端は開始日の入力欄の左端に
                             揃える（md 以上はラベル分 .form-label-fixed = 140px を空ける、md 未満は
                             ラベルが上に積まれて開始日が全幅になるため空きは不要）。
                             エラーがないときは行ごと出さない（空の余白を作らない）。 --}}
                        @if($errors->hasAny(['date_from', 'date_to']))
                            {{-- 横ガターだけの gx-2 にし、縦ガターと mt-1 を足さないことで、
                                 入力欄との間隔を invalid-feedback の標準 0.25rem だけに揃える。 --}}
                            <div class="row gx-2">
                                <div class="col-md-auto form-label-fixed d-none d-md-block"></div>
                                <div class="col">
                                    <x-form-error field="date_from" block />
                                    <x-form-error field="date_to" block />
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a href="{{ route('access-logs.index') }}" class="btn btn-secondary">クリア</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> 検索
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ログ一覧 --}}
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>日時</th>
                        <th>トレーナー</th>
                        <th>操作</th>
                        <th>対象</th>
                        <th>IPアドレス</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td>{{ $log->created_at->format('Y/m/d H:i:s') }}</td>
                            <td>{{ $log->trainer?->name }}</td>
                            <td>{{ $log->action_label }}</td>
                            <td>
                                @if($log->target_type && $log->target_id)
                                    {{ $log->target_label }} #{{ $log->target_id }}
                                @endif
                            </td>
                            <td class="text-muted">{{ $log->ip_address }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">操作履歴がありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $logs->links() }}
    </div>
</div>
@endsection
