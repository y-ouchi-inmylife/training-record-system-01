@extends('layouts.app')

@section('title', '契約一覧')

{{-- 契約一覧（S-0315。2026-10-28 追加）。会員をまたいで契約を 1 件ずつ出す、見るだけの画面（支払いの状況などとの突き合わせ用）。
     契約・利用の登録・編集・削除は会員詳細（S-0305）で行うので、新規登録のボタンも操作のボタンも置かない。
     見出し・検索フォーム・表・ページ送りの作りは会員一覧（S-0304）に合わせる。 --}}
@section('content')
<div class="container">
    {{-- ヘッダー --}}
    <h2 class="mb-4">契約一覧</h2>

    {{-- 検索フォーム --}}
    <div class="card mb-4">
        <div class="card-body">
            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 会員一覧と同じく小さなフォーム（日付の 2 欄だけがエラー対象）のため、上部の案内
                 （<x-form-error-summary />）は置かない（§2-7「画面上部の短い案内」の例外）。 --}}
            <form method="GET" action="{{ route('contracts.index') }}" novalidate>
                {{-- 検索時も並び順を維持するため、コントローラーで確定した並び順（実効値）を持たせる（会員一覧と同じ） --}}
                <input type="hidden" name="sort" value="{{ $sortBy }}">
                <input type="hidden" name="direction" value="{{ $sortDir }}">

                {{-- 行1: 内部ID + 名前 --}}
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
                </div>

                {{-- 行2: 契約日（範囲） --}}
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label class="col-md-auto col-form-label text-md-end form-label-fixed">契約日</label>
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
                        {{-- 日付の範囲のエラーの文言（§2-7「項目どうしの関係のチェック」の但し書き）。会員一覧と同じく、
                             範囲のまとまり（日付行）の下に 1 行で出し、左端は開始日の入力欄の左端に揃える。
                             エラーがないときは行ごと出さない。 --}}
                        @if($errors->hasAny(['date_from', 'date_to']))
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
                    <a href="{{ route('contracts.index') }}" class="btn btn-secondary">クリア</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> 検索
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- 件数表示（見るだけの画面なので、新規登録のボタンは置かない） --}}
    <div class="mb-3">
        <p class="text-muted mb-0">{{ $contracts->total() }}件</p>
    </div>

    <div class="table-responsive">
        <table class="table table-hover" data-contracts>
            <thead class="table-light">
                <tr>
                    {{-- ▲▼ と切り替え先は、リクエストの値ではなくコントローラーで確定した並び順（$sortBy / $sortDir）で判定する（会員一覧と同じ）。
                         見出しで並び替えたら 1 ページ目に戻すため、今のクエリから page を除いて検索条件だけを引き継ぐ。
                         内部ID・会員は別の列から切り替えたときは昇順、契約日は降順（新しい順）から始める。 --}}
                    <th class="text-nowrap">
                        <a href="{{ route('contracts.index', array_merge(\Illuminate\Support\Arr::except(request()->query(), ['page']), ['sort' => 'internal_id', 'direction' => $sortBy === 'internal_id' && $sortDir === 'asc' ? 'desc' : 'asc'])) }}" class="text-decoration-none text-dark">
                            内部ID
                            @if($sortBy === 'internal_id')
                                {{ $sortDir === 'asc' ? '▲' : '▼' }}
                            @endif
                        </a>
                    </th>
                    <th class="text-nowrap">
                        <a href="{{ route('contracts.index', array_merge(\Illuminate\Support\Arr::except(request()->query(), ['page']), ['sort' => 'last_name', 'direction' => $sortBy === 'last_name' && $sortDir === 'asc' ? 'desc' : 'asc'])) }}" class="text-decoration-none text-dark">
                            会員
                            @if($sortBy === 'last_name')
                                {{ $sortDir === 'asc' ? '▲' : '▼' }}
                            @endif
                        </a>
                    </th>
                    <th class="text-nowrap">
                        <a href="{{ route('contracts.index', array_merge(\Illuminate\Support\Arr::except(request()->query(), ['page']), ['sort' => 'contracted_on', 'direction' => $sortBy === 'contracted_on' && $sortDir === 'desc' ? 'asc' : 'desc'])) }}" class="text-decoration-none text-dark">
                            契約日
                            @if($sortBy === 'contracted_on')
                                {{ $sortDir === 'asc' ? '▲' : '▼' }}
                            @endif
                        </a>
                    </th>
                    <th class="text-nowrap">回数</th>
                    {{-- 利用：その契約に割り当てた利用の件数（withCount('usages')）。未割り当ての利用は数えない --}}
                    <th class="text-nowrap">利用</th>
                    <th class="text-nowrap">有効期限</th>
                    {{-- メモだけは全文を出し、長ければ折り返す（ほかの列は text-nowrap） --}}
                    <th>メモ</th>
                </tr>
            </thead>
            <tbody>
                @forelse($contracts as $contract)
                    {{-- 行全体のクリックで、その契約の会員の会員詳細（S-0305）へ（会員一覧と同じ作り） --}}
                    <tr style="cursor: pointer;" onclick="location.href='{{ route('clients.show', $contract->client) }}'">
                        <td class="text-nowrap">{{ $contract->client->internal_id }}</td>
                        <td class="text-nowrap">{{ $contract->client->display_name }}</td>
                        <td class="text-nowrap">{{ $contract->contracted_on->format('Y/m/d') }}</td>
                        <td class="text-nowrap">{{ $contract->count }}</td>
                        <td class="text-nowrap">{{ $contract->usages_count }}</td>
                        {{-- 有効期限を過ぎていて残りがある契約は、会員詳細と同じく日付を赤字にする（判定は ClientContract::isExpiredWithRemaining。
                             空なら空欄。§2-4） --}}
                        <td class="text-nowrap">@if($contract->expires_on)@if($contract->expired_with_remaining)<span class="text-danger">{{ $contract->expires_on->format('Y/m/d') }}</span>@else{{ $contract->expires_on->format('Y/m/d') }}@endif @endif</td>
                        <td>{{ $contract->note }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">該当する契約がありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ページネーション --}}
    @if($contracts->hasPages())
        <div class="d-flex justify-content-center mt-4">
            {{ $contracts->links() }}
        </div>
    @endif
</div>
@endsection
