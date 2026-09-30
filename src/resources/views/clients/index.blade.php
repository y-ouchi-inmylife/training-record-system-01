@extends('layouts.app')

@section('title', '会員一覧')

@section('content')
<div class="container">
    {{-- ヘッダー --}}
    <h2 class="mb-4">会員一覧</h2>

    {{-- 検索フォーム --}}
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('clients.index') }}">
                {{-- 検索時も並び順を維持するため、コントローラーで確定した並び順（実効値）を持たせる（設計書 S-0304 設計方針「並び順」参照） --}}
                <input type="hidden" name="sort" value="{{ $sortBy }}">
                <input type="hidden" name="direction" value="{{ $sortDir }}">
                @if ($errors->has('date_to') || $errors->has('date_from'))
                    <div class="alert alert-danger">{{ $errors->first('date_to') ?: $errors->first('date_from') }}</div>
                @endif
                {{-- 行1: 内部ID + 名前 + 主担当 --}}
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
                            <label for="keyword" class="col-md-auto col-form-label text-md-end form-label-fixed">名前</label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control" id="keyword" name="keyword"
                                       inputmode="text"
                                       value="{{ request('keyword') }}" placeholder="姓名・かなで検索（部分一致）">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="primary_trainer_id" class="col-md-auto col-form-label text-md-end form-label-fixed">主担当</label>
                            <div class="col-12 col-md">
                                <select class="form-select" id="primary_trainer_id" name="primary_trainer_id">
                                    <option value="">すべて</option>
                                    @foreach($trainers as $trainer)
                                        <option value="{{ $trainer->id }}" {{ request('primary_trainer_id') == $trainer->id ? 'selected' : '' }}>
                                            {{ $trainer->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 行2: 最終トレーニング日（範囲） --}}
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label class="col-md-auto col-form-label text-md-end form-label-fixed">最終トレーニング日</label>
                            <div class="col">
                                <input type="text" class="form-control datepicker" id="date_from" name="date_from"
                                       value="{{ old('date_from', request('date_from')) }}"
                                       placeholder="例: 2026-04-01"
                                       pattern="\d{4}-\d{2}-\d{2}"
                                       maxlength="10">
                            </div>
                            <div class="col-md-auto px-1">～</div>
                            <div class="col">
                                <input type="text" class="form-control datepicker" id="date_to" name="date_to"
                                       value="{{ old('date_to', request('date_to')) }}"
                                       placeholder="例: 2026-04-01"
                                       pattern="\d{4}-\d{2}-\d{2}"
                                       maxlength="10">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a href="{{ route('clients.index') }}" class="btn btn-secondary">クリア</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> 検索
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- 件数表示・登録ボタン --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="text-muted mb-0">{{ $clients->total() }}件</p>
        <a href="{{ route('clients.create') }}" class="btn btn-primary">新規登録</a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    {{-- ▲▼ と切り替え先は、リクエストの値ではなくコントローラーで確定した並び順（$sortBy / $sortDir）で判定する。
                         既定（sort 未指定）でも実際に効いている並び順を見出しに示すため（設計書 S-0304 設計方針「並び順」参照）。 --}}
                    <th>
                        <a href="{{ route('clients.index', array_merge(request()->query(), ['sort' => 'internal_id', 'direction' => $sortBy === 'internal_id' && $sortDir === 'asc' ? 'desc' : 'asc'])) }}" class="text-decoration-none text-dark">
                            内部ID
                            @if($sortBy === 'internal_id')
                                {{ $sortDir === 'asc' ? '▲' : '▼' }}
                            @endif
                        </a>
                    </th>
                    <th>
                        <a href="{{ route('clients.index', array_merge(request()->query(), ['sort' => 'last_name', 'direction' => $sortBy === 'last_name' && $sortDir === 'asc' ? 'desc' : 'asc'])) }}" class="text-decoration-none text-dark">
                            名前
                            @if($sortBy === 'last_name')
                                {{ $sortDir === 'asc' ? '▲' : '▼' }}
                            @endif
                        </a>
                    </th>
                    {{-- トレーニー列（2026-09 追加、設計書 S-0304 設計方針参照）。
                         複合値のためソート対象外。表示文字列は Client::trainees_label アクセサで組み立てる
                         （犬種・性別の省略ルールと「／」区切りは Blade に条件式を書き散らかないためモデル側に集約）。 --}}
                    <th>トレーニー</th>
                    {{-- 初回日列（2026-09 追加、設計書 S-0304 設計方針「列の並べ替えと『初回日』の追加」参照）。未設定は空欄（§2-4 参照）。
                         既定の並び順のキー。別の列から切り替えたときは新しい順（desc）から始める（設計書 S-0304 設計方針「並び順」参照）。 --}}
                    <th>
                        <a href="{{ route('clients.index', array_merge(request()->query(), ['sort' => 'initial_consultation_date', 'direction' => $sortBy === 'initial_consultation_date' && $sortDir === 'desc' ? 'asc' : 'desc'])) }}" class="text-decoration-none text-dark">
                            初回日
                            @if($sortBy === 'initial_consultation_date')
                                {{ $sortDir === 'asc' ? '▲' : '▼' }}
                            @endif
                        </a>
                    </th>
                    <th>主担当</th>
                    {{-- メールアドレス列：状態バッジのみ表示（値は出さない）。カラムを持たない導出値のためソート不可（設計書 §S-0304）--}}
                    <th>メールアドレス</th>
                    <th>最終トレーニング日</th>
                </tr>
            </thead>
            <tbody>
                @forelse($clients as $client)
                    @php
                        $badge = $client->statusBadge();
                    @endphp
                    <tr style="cursor: pointer;" onclick="location.href='{{ route('clients.show', $client) }}'">
                        <td>{{ $client->internal_id }}</td>
                        <td>{{ $client->display_name }}</td>
                        <td>{{ $client->trainees_label }}</td>
                        <td>{{ $client->initial_consultation_date?->format('Y/m/d') }}</td>
                        <td>{{ $client->primaryTrainer?->name }}</td>
                        <td><span class="badge {{ $badge['class'] }}">{{ $badge['label'] }}</span></td>
                        <td>{{ $client->last_training_date ? \Carbon\Carbon::parse($client->last_training_date)->format('Y/m/d') : '' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">該当する会員がいません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ページネーション --}}
    @if($clients->hasPages())
        <div class="d-flex justify-content-center mt-4">
            {{ $clients->links() }}
        </div>
    @endif
</div>
@endsection
