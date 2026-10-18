@extends('layouts.client')

@php
    // 曜日の日本語表記(ダッシュボードと同じ配列を使う)
    $weekdaysJp = ['日', '月', '火', '水', '木', '金', '土'];
    $rec = $trainingRecord;

    // トレーナー名を 1 つに連結(内部語彙「担当1/担当2」を排除・設計書 §6)
    // 空はフィルタするので trainer2 だけ NULL なら trainer1 のみ表示される
    $trainerNames = collect([$rec->trainer1?->name, $rec->trainer2?->name])
        ->filter()->values();

    // 時刻は HH:MM に整形(DB は HH:MM:SS だが表示は分まで)
    $trainingTime = $rec->training_time ? substr($rec->training_time, 0, 5) : null;

    // ダッシュボードと同じ日付文字列(飼い主が2画面で同じ表現を見る)
    $dateLabel = $rec->training_date->month . '月'
        . $rec->training_date->day . '日'
        . '（' . $weekdaysJp[$rec->training_date->dayOfWeek] . '）';
@endphp

@section('title', $dateLabel . 'の記録')

@section('content')
<div class="container py-4 c-detail">
    {{-- 戻る導線: 「戻る」ではなく行き先を書く(設計書 §6)。
         ラベルはフッターの HOME と同じ「HOME」で、矢印は付けない。
         矢印は「一つ前の画面へ戻る」方向を示す記号として「← 戻る」だけに
         使い(S-1409/S-1410/S-1411 の .c-detail-back)、HOME は行き先を
         名指しするリンクなので付けない(設計書 §4-0・§4-10)。 --}}
    <a href="{{ route('client-portal.dashboard') }}" class="c-detail-back">
        HOME
    </a>

    {{-- 詳細タイトル: 「トレーニング記録詳細」ではなく日付主体(§6・§4-4)。
         記録の種別にトレーニング以外(事前相談等)があるため
         ラベルは「記録」(設計書 §6)。 --}}
    <h1 class="c-detail-title">
        <span class="num-tabular">{{ $rec->training_date->month }}</span>月<span class="num-tabular">{{ $rec->training_date->day }}</span>日（{{ $weekdaysJp[$rec->training_date->dayOfWeek] }}）の記録
    </h1>

    {{-- メタ情報(時刻 / トレーナー)。時刻と担当を 1 行に並べ、狭い幅では
         折り返す(§4-4「タイトル下の並び」)。間は記号を入れず 8px の余白
         (Bootstrap の column-gap-2 = 0.5rem)で区切る — 担当どうしの
         区切りの「／」と区別するため。時刻が未登録なら時刻を、担当が
         未登録なら担当を出さず、両方無ければ行ごと出さない(§6 空値
         の扱い)。 --}}
    @if($trainerNames->isNotEmpty() || $trainingTime)
        <div class="c-detail-meta meta d-flex flex-wrap column-gap-2">
            @if($trainingTime)
                <div class="num-tabular">{{ $trainingTime }}</div>
            @endif
            @if($trainerNames->isNotEmpty())
                <div>{{ $trainerNames->join(' ／ ') }}</div>
            @endif
        </div>
    @endif

    {{-- 写真と動画: 0件ならブロックごと出さない(§4-4) --}}
    @if(count($mediaItems) > 0)
        <section class="c-detail-section" aria-labelledby="c-media-heading">
            <p class="eyebrow" id="c-media-heading">写真と動画</p>
            <div class="c-session-media c-session-media--hero" data-media-grid>
                @foreach($mediaItems as $m)
                    {{-- 属性値にメディアの表示名・ファイル名は入れない（設計書 S-1404 セクション2 メディア）。
                         alt / aria-label / data-display-title はいずれも種別（写真／動画）のみ。
                         data-display-title は JS 経由で Lightbox の img.alt にセットされる。
                         typeLabel はコントローラから受け取る（Blade で @php(...) を書くと
                         周囲の @php ... @endphp ブロックとパースが衝突する事故があったため）。 --}}
                    <div class="c-media-thumb"
                         data-media-id="{{ $m['id'] }}"
                         data-media-type="{{ $m['type'] }}"
                         data-conversion-status="{{ $m['conversionStatus'] }}"
                         data-display-title="{{ $m['typeLabel'] }}"
                         role="button"
                         tabindex="0"
                         aria-label="{{ $m['typeLabel'] }}を開く">
                        @if($m['thumbnailUrl'])
                            <img src="{{ $m['thumbnailUrl'] }}" alt="{{ $m['typeLabel'] }}">
                            @if($m['type'] === 'video')
                                @include('media-records._video-play-overlay')
                            @endif
                        @else
                            <span class="c-media-placeholder">{{ $m['typeLabel'] }}</span>
                        @endif
                        @if($m['conversionStatus'] !== 'not_required' && $m['conversionStatus'] !== 'done')
                            <span class="c-media-badge">準備中</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 愛犬性格診断(2026-10-18 追加。要件定義書 6-15-7・6-16-16、設計書 S-1404 セクション2.5)。評価文を公開して作った記録だけ。
         写真と動画の下・ノートの上(S-0403 と同じ位置)。見出しは会員側の呼び名(「アイちゃん」)。
         タイプと、その結果の時点のレーダーチャート(マイページの愛犬のカードと同じ小さな表示〔compact〕・同じ部品)。
         点数の表・50 問の回答・評価文の状態・S-0313 へのリンクは出さない。注記も出さない(マイページと同じ)。
         結び付きがない・結果が削除された記録には出さない --}}
    @if($personalityDiagnosis)
        <section class="c-detail-section" aria-labelledby="c-personality-heading" data-record-personality-diagnosis>
            <p class="eyebrow" id="c-personality-heading">{{ $personalityDiagnosis['trainee']->client_call_name }}の{{ __('messages.personality_diagnosis_name') }}（{{ $personalityDiagnosis['result']->answered_at->format('Y/m/d') }}）</p>
            <p class="fw-bold mb-1" style="word-break: keep-all;" data-record-personality-type>{!! collect(explode('・', $personalityDiagnosis['result']->personality_type_label))->map(fn ($part) => e($part))->implode('・<wbr>') !!}</p>
            <div class="c-personality-radar">
                <canvas id="record-personality-radar" role="img" aria-label="{{ $personalityDiagnosis['trainee']->client_call_name }}の{{ __('messages.personality_diagnosis_name') }}のグラフ"
                        data-personality-radar="{{ json_encode($personalityDiagnosis['radar'], JSON_UNESCAPED_UNICODE) }}"
                        data-personality-radar-compact></canvas>
            </div>
            <ul class="c-personality-legend list-unstyled d-flex flex-wrap column-gap-3 row-gap-0 mt-1 mb-0"
                data-personality-radar-legend="record-personality-radar"></ul>
        </section>
    @endif

    {{-- トレーナーからのノート(record_content): 空ならブロックごと出さない(§4-4)
         注: impression は非開示フィールドのため一切出さない(§8-1-1) --}}
    @if($rec->record_content)
        <section class="c-detail-section" aria-labelledby="c-note-heading">
            <p class="eyebrow" id="c-note-heading">トレーナーからのノート</p>
            <div class="c-detail-body">{{ $rec->record_content }}</div>
        </section>
    @endif

</div>

{{-- インライン通知(「準備中」等) 用の Bootstrap Toast。
     ダッシュボードと同じ方式(設計書 §6: alert() 廃止、内部状態語彙を
     出さない飼い主向け文言に置き換え)。 --}}
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;">
    <div id="c-toast" class="toast align-items-center c-toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
        <div class="d-flex">
            <div class="toast-body" id="c-toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="閉じる"></button>
        </div>
    </div>
</div>

{{-- 原寸ライトボックス(写真拡大・動画再生) — 共有パーシャル --}}
@include('media-records._lightbox')

{{-- 愛犬性格診断のレーダーチャート(欄があるときだけ読み込む。マイページと同じ部品。2026-10-18 追加) --}}
@if($personalityDiagnosis)
    @vite('resources/js/personality-radar.js')
@endif
@endsection

@push('scripts')
<script>
// ダッシュボードと同一の media click ハンドラ。alert() を廃止し
// 「準備中」等はお客様向け文言で Bootstrap Toast に表示する(§6)。
document.addEventListener('DOMContentLoaded', function () {
    const grids = document.querySelectorAll('[data-media-grid]');
    if (grids.length === 0) return;

    const toastEl = document.getElementById('c-toast');
    const toastBody = document.getElementById('c-toast-body');

    function showToast(msg) {
        if (!toastEl || !toastBody || typeof bootstrap === 'undefined') return;
        toastBody.textContent = msg;
        bootstrap.Toast.getOrCreateInstance(toastEl).show();
    }

    async function openMedia(card) {
        const id = card.dataset.mediaId;
        const type = card.dataset.mediaType;
        const status = card.dataset.conversionStatus;
        const title = card.dataset.displayTitle || '';

        // 変換未完(pending/processing/error)はお客様向け文言で通知
        if (status !== 'not_required' && status !== 'done') {
            showToast('この写真/動画は準備中です。少ししてから開いてみてください。');
            return;
        }

        try {
            const res = await fetch('/client-portal/media/' + encodeURIComponent(id) + '/play', {
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error('写真/動画を開けませんでした。時間をおいて試してみてください。');
            const body = await res.json();
            const url = body.data && body.data.url;
            if (!url) throw new Error('写真/動画を開けませんでした。時間をおいて試してみてください。');
            if (typeof window.openLightbox !== 'function') {
                throw new Error('この画面ではまだ写真/動画を開けません。ページを再読み込みしてみてください。');
            }
            window.openLightbox(type === 'photo' ? 'IMG' : 'VIDEO', url, title);
        } catch (err) {
            showToast(err.message || '写真/動画を開けませんでした。');
        }
    }

    grids.forEach(function (grid) {
        grid.addEventListener('click', function (e) {
            const card = e.target.closest('.c-media-thumb');
            if (!card) return;
            openMedia(card);
        });
        grid.addEventListener('keydown', function (e) {
            const card = e.target.closest('.c-media-thumb');
            if (!card) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openMedia(card);
            }
        });
    });
});
</script>
@endpush
