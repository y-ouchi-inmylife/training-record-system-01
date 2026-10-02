@extends('layouts.app')

@section('title', 'トレーニング記録一覧')

@section('content')
<div class="container">
    <h2 class="mb-4">トレーニング記録一覧</h2>

    {{-- 検索フォーム --}}
    <div class="card mb-4">
        <div class="card-body">
            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 日付の欄に pattern があるため、novalidate を付けないとブラウザの吹き出しが出うる。
                 本画面は小さなフォーム（日付の 2 欄だけがエラー対象）のため、上部の案内
                 （<x-form-error-summary />）は置かない（§2-7「画面上部の短い案内」の例外）。 --}}
            <form method="GET" action="{{ route('training-records.index') }}" novalidate>
                {{-- 行1: 内部ID + 名前 + 担当1、担当2 --}}
                <div class="row g-3 mb-2">
                    <div class="col-md-3">
                        <div class="row g-2 align-items-center">
                            <label for="internal_id" class="col-md-auto col-form-label text-md-end form-label-fixed">内部ID</label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control" id="internal_id" name="internal_id"
                                       value="{{ request('internal_id') }}" placeholder="部分一致">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label for="name" class="col-md-auto col-form-label text-md-end form-label-fixed">名前</label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control" id="name" name="name"
                                       inputmode="text"
                                       value="{{ request('name') }}" placeholder="姓名・かなで検索（部分一致）">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="trainer_id" class="col-md-auto col-form-label text-md-end form-label-fixed">担当1、担当2</label>
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
                </div>

                {{-- 行2: トレーニング日（範囲） + キーワード --}}
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label class="col-md-auto col-form-label text-md-end form-label-fixed">トレーニング日</label>
                            <div class="col">
                                <input type="text" class="form-control datepicker @error('date_from') is-invalid @enderror" id="date_from" name="date_from"
                                       value="{{ old('date_from', request('date_from')) }}"
                                       placeholder="例: 2026-04-01"
                                       pattern="\d{4}-\d{2}-\d{2}"
                                       maxlength="10">
                                <x-form-error field="date_from" />
                            </div>
                            <div class="col-md-auto px-1">～</div>
                            <div class="col">
                                <input type="text" class="form-control datepicker @error('date_to') is-invalid @enderror" id="date_to" name="date_to"
                                       value="{{ old('date_to', request('date_to')) }}"
                                       placeholder="例: 2026-04-01"
                                       pattern="\d{4}-\d{2}-\d{2}"
                                       maxlength="10">
                                <x-form-error field="date_to" />
                            </div>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="row g-2 align-items-center">
                            <label for="keyword" class="col-md-auto col-form-label text-md-end form-label-fixed">キーワード</label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control" id="keyword" name="keyword"
                                       inputmode="text"
                                       value="{{ request('keyword') }}" maxlength="100" placeholder="トレーニング記録・所感を検索">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a href="{{ route('training-records.index') }}" class="btn btn-secondary">クリア</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> 検索
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- 検索結果 --}}
    <div class="mb-3">
        <p class="text-muted mb-0">{{ $records->total() }}件</p>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>
                        <a href="{{ route('training-records.index', array_merge(request()->query(), ['sort' => 'internal_id', 'direction' => request('sort') === 'internal_id' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="text-decoration-none text-dark">
                            内部ID
                            @if(request('sort') === 'internal_id')
                                {{ request('direction') === 'asc' ? '▲' : '▼' }}
                            @endif
                        </a>
                    </th>
                    <th>
                        <a href="{{ route('training-records.index', array_merge(request()->query(), ['sort' => 'client_name', 'direction' => request('sort') === 'client_name' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="text-decoration-none text-dark">
                            名前
                            @if(request('sort') === 'client_name')
                                {{ request('direction') === 'asc' ? '▲' : '▼' }}
                            @endif
                        </a>
                    </th>
                    {{-- トレーニー列（2026-09 追加、設計書 S-0402 設計方針参照）。
                         複合値のためソート対象外。表示文字列は Client::trainees_label アクセサ（S-0304 で導入済み）を共用する。 --}}
                    <th>トレーニー</th>
                    <th>
                        <a href="{{ route('training-records.index', array_merge(request()->query(), ['sort' => 'training_date', 'direction' => request('sort') === 'training_date' && request('direction', 'desc') === 'desc' ? 'asc' : 'desc'])) }}" class="text-decoration-none text-dark">
                            トレーニング日
                            @if(request('sort', 'training_date') === 'training_date')
                                {{ request('direction', 'desc') === 'desc' ? '▼' : '▲' }}
                            @endif
                        </a>
                    </th>
                    <th>担当1</th>
                    <th>担当2</th>
                    <th>メディア</th>
                </tr>
            </thead>
            <tbody>
                @forelse($records as $record)
                    <tr style="cursor: pointer;" onclick="location.href='{{ route('training-records.show', $record) }}'">
                        <td>{{ $record->client->internal_id ?? '' }}</td>
                        <td>{{ $record->client->display_name ?? '' }}</td>
                        <td>{{ $record->client->trainees_label ?? '' }}</td>
                        <td>{{ $record->training_date->format('Y/m/d') }}</td>
                        <td>{{ $record->trainer1->name ?? '' }}</td>
                        <td>{{ $record->trainer2->name ?? '' }}</td>
                        <td>{{ $record->media_records_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">該当するトレーニング記録がありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $records->links() }}
</div>
@endsection
