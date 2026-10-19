@extends('layouts.client')

{{-- 会員の愛犬性格診断のアドバイス画面（S-1418。2026-10-18 追加。要件定義書 6-15-21、設計書 S-1418）。
     マイページ（S-1402）の記録の一覧に混ぜたアドバイスから開く。作りは会員トレーニング記録詳細（S-1404）にそろえる
     （HOME への戻り、見出し、見出しの下の小さな文字、.c-detail-section の区切り）。
     出さないもの：点数の表・50 問の回答・注記（マイページと同じ）、アドバイスの状態、トレーナー側の画面へのリンク --}}
@php
    $pageTitle = $callName.'の'.__('messages.personality_diagnosis_name');
@endphp

@section('title', $pageTitle)

@section('content')
<div class="container py-4 c-detail">
    {{-- 戻る導線：S-1404 と同じく行き先の「HOME」（マイページ）。矢印は付けない --}}
    <a href="{{ route('client-portal.dashboard') }}" class="c-detail-back">
        HOME
    </a>

    {{-- 見出し：「{呼び名}の愛犬性格診断（{回答日}）」 --}}
    <h1 class="c-detail-title">{{ $pageTitle }}（<span class="num-tabular">{{ $answeredAt->format('Y/m/d') }}</span>）</h1>

    {{-- 公開した日と公開したトレーナー：S-1404 の時刻・担当と同じ並び・同じ小さな文字。トレーナーが削除されていれば日だけ --}}
    <div class="c-detail-meta meta d-flex flex-wrap column-gap-2" data-evaluation-meta>
        <div><span class="num-tabular">{{ $publishedAt->format('Y/m/d') }}</span> 公開</div>
        @if($publishedByName)
            <div>{{ $publishedByName }}</div>
        @endif
    </div>

    {{-- タイプと、その結果の時点のレーダーチャート（今回＝この結果、前回・初回＝それより前の結果）。
         マイページの愛犬のカードと同じ小さな表示（.c-personality-radar・data-personality-radar-compact）・同じ部品（personality-radar.js）。
         タイプは「・」のあとでだけ折り返す。読み上げは「{呼び名}の愛犬性格診断のグラフ」だけ（点数は出さないものなので読み上げにも出さない） --}}
    <section aria-label="タイプとグラフ" data-evaluation-personality>
        <p class="fw-bold mb-1" style="word-break: keep-all;" data-evaluation-type>{!! collect(explode('・', $typeLabel))->map(fn ($part) => e($part))->implode('・<wbr>') !!}</p>
        @if($radarData)
            <div class="c-personality-radar">
                <canvas id="evaluation-personality-radar" role="img" aria-label="{{ $pageTitle }}のグラフ"
                        data-personality-radar="{{ json_encode($radarData, JSON_UNESCAPED_UNICODE) }}"
                        data-personality-radar-compact></canvas>
            </div>
            <ul class="c-personality-legend list-unstyled d-flex flex-wrap column-gap-3 row-gap-0 mt-1 mb-0"
                data-personality-radar-legend="evaluation-personality-radar"></ul>
        @endif
    </section>

    {{-- アドバイス：小見出し「トレーナーからのアドバイス」の下に、改行を保持して出す（S-1404 の「トレーナーからのノート」と同じ見た目） --}}
    <section class="c-detail-section" aria-labelledby="c-evaluation-heading">
        <p class="eyebrow" id="c-evaluation-heading">トレーナーからのアドバイス</p>
        <div class="c-detail-body" data-evaluation-text>{{ $evaluationText }}</div>
    </section>
</div>

@vite('resources/js/personality-radar.js')
@endsection
