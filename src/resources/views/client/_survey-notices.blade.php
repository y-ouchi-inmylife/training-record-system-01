{{-- 回答待ちの案内のお知らせ（S-1402 の愛犬のカードの中、名前の行のすぐ下。2026-10 追加。設計書 S-1402「回答待ちの案内のお知らせ」）。
     $notices は Client\DashboardController::surveyNotices（並びは 愛犬アンケート → 性格診断。kind と url）。
     - 文は lang/ja/messages.php の client_survey_notice（名称はお客様と相談中のため 1 か所で変える）
     - ［回答する］は印刷した案内と同じ回答の URL を同じタブで開く（会員側の主な操作なので btn-primary）
     - 見た目は印刷した案内の帯にそろえる（愛犬アンケートは緑・clipboard-check、性格診断は紫・heart-pulse）。
       会員側は Bootstrap Icons のフォントを読み込んでいないので、アイコンは 2 つだけ SVG を直接書く（見た目の印なので読み上げない）
     - スマホの幅（sm 未満）は文の下にボタン。閉じる（×）は付けない（回答すれば消えるため） --}}
@foreach($notices as $notice)
    <div class="c-survey-notice c-survey-notice--{{ $notice['kind'] }}" data-client-survey-notice="{{ $notice['kind'] }}">
        <p class="c-survey-notice-text mb-0">
            @if($notice['kind'] === 'intake')
                <svg class="c-survey-notice-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="M10.854 7.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 9.793l2.646-2.647a.5.5 0 0 1 .708 0"/><path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1z"/><path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0z"/></svg>
            @else
                <svg class="c-survey-notice-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="m8 2.748-.717-.737C5.6.281 2.514.878 1.4 3.053.918 3.995.78 5.323 1.508 7H.43c-2.128-5.697 4.165-8.83 7.394-5.857q.09.083.176.171a3 3 0 0 1 .176-.17c3.23-2.974 9.522.159 7.394 5.856h-1.078c.728-1.677.59-3.005.108-3.947C13.486.878 10.4.28 8.717 2.01zM2.212 10h1.315C4.593 11.183 6.05 12.458 8 13.795c1.949-1.337 3.407-2.612 4.473-3.795h1.315c-1.265 1.566-3.14 3.25-5.788 5-2.648-1.75-4.523-3.434-5.788-5"/><path d="M10.464 3.314a.5.5 0 0 0-.945.049L7.921 8.956 6.464 5.314a.5.5 0 0 0-.88-.091L3.732 8H.5a.5.5 0 0 0 0 1H4a.5.5 0 0 0 .416-.223l1.473-2.209 1.647 4.118a.5.5 0 0 0 .945-.049l1.598-5.593 1.457 3.642A.5.5 0 0 0 12 9h3.5a.5.5 0 0 0 0-1h-3.162z"/></svg>
            @endif
            <span>{{ __('messages.client_survey_notice.'.$notice['kind']) }}</span>
        </p>
        <a href="{{ $notice['url'] }}" class="btn btn-primary btn-sm text-nowrap">{{ __('messages.client_survey_notice.answer') }}</a>
    </div>
@endforeach
