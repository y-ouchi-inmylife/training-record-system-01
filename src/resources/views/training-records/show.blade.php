@extends('layouts.app')

@section('title', 'トレーニング記録詳細')

@push('styles')
<style>
/* メディアセクション: 1行に横並びで配置し、収まらない場合は横スクロール。
   件数が増えてもセクションの高さは変わらない */
.media-scroll {
    display: flex;
    gap: 1rem;
    overflow-x: auto;
    padding-bottom: 0.5rem;
}
.media-scroll > .media-card {
    flex: 0 0 160px;
    width: 160px;
}
</style>
@endpush

@section('content')
<div class="container">
    <div class="mb-4">
        {{-- 1段目: 画面タイトル + 操作ボタン群 --}}
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="mb-0">トレーニング記録詳細</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('training-records.index') }}" class="btn btn-outline-secondary">&laquo; トレーニング記録一覧へ戻る</a>
                <a href="{{ route('training-records.edit', $trainingRecord) }}" class="btn btn-primary">編集</a>
                @if(auth()->user()->isAdmin())
                    <form method="POST" action="{{ route('training-records.destroy', $trainingRecord) }}" class="d-inline"
                          onsubmit="return confirm('このトレーニング記録を削除しますか？')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">削除</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- 2段目: 日付行 --}}
        <div class="d-flex align-items-baseline gap-2 flex-wrap mb-2">
            <div class="fs-4 fw-bold">
                {{ $trainingRecord->training_date->format('Y/m/d') }}@if($trainingRecord->training_time)<span class="ms-2">{{ substr($trainingRecord->training_time, 0, 5) }}</span>@endif
            </div>
            <a href="{{ route('clients.show', $trainingRecord->client_id) }}" class="ms-3 fs-3 fw-bold text-decoration-none">{{ $trainingRecord->client->display_name }}</a>
            <div class="d-flex align-items-baseline gap-2 ms-3">
                <span class="text-muted small">内部ID</span>
                <span class="font-monospace fs-5">{{ $trainingRecord->client->internal_id }}</span>
            </div>
        </div>

        {{-- 3段目: 属性3列 --}}
        @php
            $trainerNames = implode('・', array_filter([
                $trainingRecord->trainer1?->name,
                $trainingRecord->trainer2?->name,
            ]));
        @endphp
        <div class="row g-3 mt-2 pt-2 border-top">
            <div class="col-md-3">
                <div class="text-muted small">担当</div>
                <div style="min-height: 1.5rem;">{{ $trainerNames }}</div>
            </div>
        </div>
    </div>

    {{-- メディア（ヘッダーサマリーの直下：設計書 S-0403） --}}
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">メディア（{{ count($mediaItems) }}件）</h6>
        </div>
        <div class="card-body">
            @if(count($mediaItems) === 0)
                <div class="text-muted">この記録のメディアはありません。</div>
            @else
                <div class="media-scroll" id="mediaViewGrid">
                    @foreach($mediaItems as $m)
                        {{-- サムネイル下の表示名ラベルは出さない。属性値（alt / data-display-title）にも
                             メディアの表示名やファイル名を入れず、種別「写真」「動画」だけを載せる。
                             表示名はトレーナーの管理用で、メディア一覧（S-1302）で設定・参照する
                             （記録の文脈に名前を出すとお客様に見える名前だと誤解されるため）。
                             typeLabel はコントローラから受け取る（Blade で @php(...) を書くと
                             周囲の @php ... @endphp ブロックとパースが衝突する事故があったため）。
                             設計書 S-0403 セクション2 メディア参照。 --}}
                        <div class="card media-card"
                             data-media-id="{{ $m['id'] }}"
                             data-media-type="{{ $m['type'] }}"
                             data-conversion-status="{{ $m['conversionStatus'] }}"
                             data-display-title="{{ $m['typeLabel'] }}"
                             style="cursor: pointer;" role="button" tabindex="0">
                            <div class="ratio ratio-1x1 bg-light d-flex align-items-center justify-content-center">
                                @if($m['thumbnailUrl'])
                                    <img src="{{ $m['thumbnailUrl'] }}" alt="{{ $m['typeLabel'] }}" class="img-fluid">
                                    {{-- 動画のときだけ中央に▶をオーバーレイ（写真・プレースホルダには出さない） --}}
                                    @if($m['type'] === 'video')
                                        @include('media-records._video-play-overlay')
                                    @endif
                                @else
                                    <span class="text-muted">{{ $m['typeLabel'] }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- 愛犬性格診断の欄（2026-10-18 追加。要件定義書 6-4-4・6-16-16、設計書 S-0403 セクション2.5）。評価文を公開して作った記録だけ。
         メディアの下・ノートの上（評価文〔ノート〕を読む前に、タイプとグラフが目に入るように）。
         グラフはその結果の時点（その結果を今回、それより前の結果から前回・初回）。トレーニー詳細（S-0309）と同じ部品・同じ大きさ。
         点数は出さない（S-0313 で見る）。結び付きがない・結果が削除された記録には出さない --}}
    @if($personalityDiagnosis)
        <div class="card mb-3" data-record-personality-diagnosis>
            <div class="card-header">
                <h6 class="mb-0">{{ $personalityDiagnosis['trainee']->display_name }}の{{ __('messages.personality_diagnosis_name') }}（{{ $personalityDiagnosis['result']->answered_at->format('Y/m/d') }}）</h6>
            </div>
            <div class="card-body">
                <div class="row gy-3 align-items-center">
                    <div class="col-12 col-md-5 col-xl-4">
                        <div class="trainee-personality-radar mx-auto">
                            <canvas id="record-personality-radar" role="img" aria-label="{{ __('messages.personality_diagnosis_name') }}のレーダーチャート"
                                    data-personality-radar="{{ json_encode($personalityDiagnosis['radar'], JSON_UNESCAPED_UNICODE) }}"></canvas>
                        </div>
                        <div class="d-flex justify-content-center mt-2">
                            <ul class="list-unstyled d-flex flex-wrap justify-content-center column-gap-3 row-gap-1 small mb-0"
                                data-personality-radar-legend="record-personality-radar"></ul>
                        </div>
                    </div>
                    <div class="col-12 col-md-7 col-xl-8">
                        <div class="text-muted small">タイプ</div>
                        <p class="fs-5 mb-2" style="word-break: keep-all;" data-record-personality-type>{!! collect(explode('・', $personalityDiagnosis['result']->personality_type_label))->map(fn ($part) => e($part))->implode('・<wbr>') !!}</p>
                        <a href="{{ route('trainee-personality-diagnosis-results.show', [$personalityDiagnosis['trainee'], $personalityDiagnosis['result']]) }}">{{ __('messages.personality_diagnosis_name') }}の詳細</a>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- トレーナーからのノート --}}
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">トレーナーからのノート</h6>
        </div>
        <div class="card-body">
            @if($trainingRecord->record_content)
                <div>{!! nl2br(e($trainingRecord->record_content)) !!}</div>
            @else
                <div class="text-muted">未記入</div>
            @endif
        </div>
    </div>

    {{-- 所感 --}}
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">所感 <span class="text-muted">（会員には非開示）</span></h6>
        </div>
        <div class="card-body">
            @if($trainingRecord->impression)
                <div>{!! nl2br(e($trainingRecord->impression)) !!}</div>
            @else
                <div class="text-muted">未記入</div>
            @endif
        </div>
    </div>

    {{-- 最終更新 --}}
    <div class="text-end text-muted small mb-3">
        最終更新: {{ $trainingRecord->updated_at->format('Y/m/d H:i') }}@if($trainingRecord->updatedBy?->name) {{ $trainingRecord->updatedBy->name }}@endif
    </div>
</div>

{{-- 原寸ライトボックス（写真拡大・動画再生） --}}
@include('media-records._lightbox')

{{-- 愛犬性格診断のレーダーチャート（欄があるときだけ読み込む。2026-10-18 追加） --}}
@if($personalityDiagnosis)
    @vite('resources/js/personality-radar.js')
@endif
@endsection

@push('scripts')
<script>
// メディアサムネイルをクリック → play で presigned URL を取得 → ライトボックス表示
document.addEventListener('DOMContentLoaded', function () {
    const grid = document.getElementById('mediaViewGrid');
    if (!grid) return;

    grid.addEventListener('click', async function (e) {
        const card = e.target.closest('.media-card');
        if (!card) return;
        const id = card.dataset.mediaId;
        const type = card.dataset.mediaType;
        const status = card.dataset.conversionStatus;
        const title = card.dataset.displayTitle || '';

        // 変換未完（pending/processing/error）は先取りで弾く
        if (status !== 'not_required' && status !== 'done') {
            alert('現在このメディアは表示できません（変換状態: ' + status + '）。');
            return;
        }

        try {
            const res = await fetch('/api/media-records/' + encodeURIComponent(id) + '/play', {
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error('再生 URL の取得に失敗しました');
            const body = await res.json();
            const url = body.data && body.data.url;
            if (!url) throw new Error('URL が取得できませんでした');
            if (typeof window.openLightbox !== 'function') {
                throw new Error('ライトボックスが初期化されていません');
            }
            window.openLightbox(type === 'photo' ? 'IMG' : 'VIDEO', url, title);
        } catch (err) {
            alert(err.message || '再生に失敗しました');
        }
    });
});
</script>
@endpush
