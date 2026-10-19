{{-- 愛犬アンケート＆愛犬性格診断の案内の進み具合（2026-10-19 追加。設計書 S-1413・S-1415「進み具合」）。組み合わせの案内のときだけ、
     回答の画面の見出しの上に出す。$step：1（愛犬アンケート）／2（愛犬性格診断）。
     「1/2」の札と、組み合わせの名前（どの案内の何問目かが分かるように）。札は読み上げでも分かるよう、隠した文字で「2つのうち…つ目」を添える --}}
<p class="c-survey-progress" data-survey-progress="{{ $step }}/2">
    <span class="c-survey-progress-count" aria-hidden="true">{{ $step }}/2</span>
    <span class="visually-hidden">2つのうち{{ $step }}つ目：</span>
    <span>{{ \App\Support\CombinedSurveyName::name() }}</span>
</p>
