{{-- 会員詳細のトレーニーの欄の「案内中」「期限切れ」の種類の名前（2026-10-19 追加。設計書 S-0305「愛犬アンケート＆愛犬性格診断の案内」）。
     - $lines が null（単独の案内）：今までどおり 1 行
     - $lines があるとき（愛犬アンケート＆愛犬性格診断）：「愛犬アンケート＆」「愛犬性格診断」の行ごとに分けて縦に並べる（App\Support\CombinedSurveyName::lines()）。
       行の中では折り返さない（text-nowrap。言葉の途中で折れないように）。読み上げは 1 つの名前のまま（隠した文字で 1 行の名前を読み、
       行に分けた見た目の文字は読まない） --}}
@if($lines === null)
    <span class="trainee-survey-line">{{ $name }}</span>
@else
    <span class="trainee-survey-line" data-survey-name-lines><span class="visually-hidden">{{ $name }}</span><span class="d-flex flex-column text-nowrap" aria-hidden="true">@foreach($lines as $line)<span>{{ $line }}</span>@endforeach</span></span>
@endif
