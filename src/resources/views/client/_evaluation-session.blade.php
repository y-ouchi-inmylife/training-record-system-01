{{-- 公開済みの愛犬性格診断のアドバイスのカード（2026-10-18 追加。要件定義書 6-15-6・6-16-16、設計書 S-1402「愛犬性格診断のアドバイスを混ぜる」）。
     マイページの最新の記録のカード（$isHero = true）と、これまでの記録（false）で使う。記録のカードと同じ形（左に日付のブロック、右に中身）。
     - 日付のブロック：公開した日（記録のカードと同じ書き方）
     - 中身：いちばん上に小さな札「愛犬性格診断」（.c-session-tag）、その下に「{呼び名}の愛犬性格診断」、
       アドバイスのはじめの部分（記録のノートと同じ文字数で切る：最新のカードは 120 字、これまでの記録は 70 字）、「続きを読む →」
     - 写真・動画のサムネイルはない。押すと会員の愛犬性格診断のアドバイス（S-1418）
     - その回のグラフとタイプ（2026-10-20 追加：記録の一覧でも、その回の結果がひと目で分かるように。設計書 S-1402「その回のグラフとタイプ」）：
       グラフはその回の結果だけの線 1 本（S-1418 と同じ作り方。TraineePersonalityDiagnosisResult::personalityRadarDataOnly。凡例なし）。
       前回・初回と比べるのは上の愛犬のカードのグラフの役目なので、ここには重ねない。値はこの結果の点数だけから作る（問い合わせはしない）。
       sm 以上はグラフとタイプを左、アドバイスのはじめと「続きを読む →」を右に並べ、タイプはグラフの下。スマホの幅は縦並びで、タイプはグラフの上
       （.c-session-personality。client.scss）。グラフは画面に入ってから描く（data-personality-radar-lazy。personality-radar.js）
     $result は公開済みの結果（trainee を読み込んでおく） --}}
@php
    $evaluationWeekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $publishedAt = $result->evaluation_published_at;
    $evaluationTitle = $result->trainee->client_call_name.'の'.__('messages.personality_diagnosis_name');
@endphp
<article class="c-session{{ $isHero ? ' c-session--hero' : '' }}" data-session-evaluation="{{ $result->id }}">
    <div class="c-date-block" aria-hidden="true">
        <span class="c-date-month">{{ $publishedAt->month }}月</span>
        <span class="c-date-day num-tabular">{{ $publishedAt->day }}</span>
        <span class="c-date-weekday">（{{ $evaluationWeekdays[$publishedAt->dayOfWeek] }}）</span>
        <span class="c-date-inline">{{ $publishedAt->month }}月 {{ $publishedAt->day }}日（{{ $evaluationWeekdays[$publishedAt->dayOfWeek] }}）</span>
    </div>
    <div class="c-session-body">
        <div class="c-session-content">
            <p class="visually-hidden">{{ $publishedAt->format('Y年n月j日') }}（{{ $evaluationWeekdays[$publishedAt->dayOfWeek] }}）の{{ $evaluationTitle }}</p>

            <div>
                <p class="c-session-tag mb-1" aria-hidden="true">{{ __('messages.personality_diagnosis_name') }}</p>
                <p class="c-session-heading">{{ $evaluationTitle }}</p>
            </div>

            <div class="c-session-personality" data-session-personality>
                {{-- タイプとグラフ（タイプは「・」のあとでだけ折り返す。S-1418 と同じ）。canvas の id は結果の id で、画面の中で重ならない --}}
                <div class="c-session-personality-chart">
                    <p class="c-session-personality-type" data-session-personality-type>{!! collect(explode('・', $result->personality_type_label))->map(fn ($part) => e($part))->implode('・<wbr>') !!}</p>
                    <div class="c-session-radar">
                        <canvas id="session-personality-radar-{{ $result->id }}" role="img" aria-label="{{ $evaluationTitle }}のグラフ"
                                data-personality-radar="{{ json_encode($result->personalityRadarDataOnly(), JSON_UNESCAPED_UNICODE) }}"
                                data-personality-radar-compact data-personality-radar-card data-personality-radar-lazy></canvas>
                    </div>
                </div>

                <div class="c-session-personality-text">
                    <p class="c-session-note">{{ \Illuminate\Support\Str::limit($result->evaluation_text, $isHero ? 120 : 70, '…') }}</p>

                    <a href="{{ route('client-portal.personality-diagnosis-results.show', $result) }}" class="c-session-cta">
                        続きを読む <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</article>
