{{-- ご利用回数（S-0305 セクション5「回数」）の「利用の行」1 つ分（2026-11 追加。設計書 S-0305 セクション5「利用の行」）。
     1 行に 1 利用。その前の回数券型のマス（clients/_usage-slot.blade.php）に代わる部品で、会員側（S-1412）は使わない
     （S-1412 は client/contracts/index.blade.php の中で回数券型のマスを組み立てる）。
     契約の下の行と、「（契約なし）」のまとまりの行で共用する。押すとその利用の編集モーダル（S-0305-M05）を開く。
     行の枠は div（list-group-item）で、中にボタンと、今日以降の利用だけ「Googleカレンダーに追加」のリンク（アイコンだけ）を横に並べる
     （2026-11 変更：その前は行の全体がボタン。ボタンの中にリンクは置けないため）。リンク以外の部分を押すと編集モーダルが開く。

     列の順：ラベル（今日・予定。実施済みは空欄）→ 日付（曜日）→ 時刻 → トレーニー → メモ（文字）
     - ラベル・日付・時刻・トレーニーの列は幅をそろえる（show.blade.php の .usage-row-*）。狭い幅では行の中で折り返す
     - トレーニーはトレーナー側の表示名（名前未登録なら「名前未登録」）。列は固定幅で、入らなければ「…」。
       「トレーニーなし」は空欄（列は残すので、メモの位置はそろう）
     - メモは文字で残りの幅に出す（2026-11 変更：その前はメモの印〔bi-chat-left-text〕だけ）。見た目は契約の見出しのメモと同じ
       （小さめ〔行全体が small〕・薄い色・1 行に収めて「…」）。全文は編集モーダルで見る。メモがない行は何も出さない
     - 見た目：今日は左端に太い線（.usage-row-today）、予定は薄い青の背景（bg-info-subtle）。どちらも行の枠（div）に付ける。
       予定の行のボタンは、マウスを乗せても薄い青のままにする（bg-transparent。前と同じ）
     実施済み・今日・予定の判定と曜日付きの日付は ContractTicketService（usageStates・usageDates）で済ませ、ここでは出し分けるだけ。

     data-*（編集モーダルの初期値）：data-client-contract-id（未割り当ては空）・data-trainee-id（「トレーニーなし」は空）・
     data-used-on（Y-m-d）・data-start-time・data-end-time（HH:MM）・data-note

     期待する変数:
       - $usage : App\Models\ClientUsage（trainee を読み込んでおく）
       - $state : string ContractTicketService::STATE_PAST / STATE_TODAY / STATE_UPCOMING
       - $date  : string 曜日付きの利用日（「2026/11/26(木)」）
       - $note  : ?string 文字で出すメモ（ContractTicketService の usageNotes。メモがなければ null）
       - $googleCalendarUrl : ?string 「Googleカレンダーに追加」の URL（GoogleCalendarLinkService。過去の利用は null） --}}
<div @class([
        'list-group-item usage-row-item d-flex align-items-stretch p-0 small',
        'usage-row-today' => $state === \App\Services\ContractTicketService::STATE_TODAY,
        'bg-info-subtle' => $state === \App\Services\ContractTicketService::STATE_UPCOMING,
    ])>
<button type="button" @class([
            'list-group-item-action usage-row d-flex flex-wrap align-items-center column-gap-2 py-1 px-2',
            'bg-transparent' => $state === \App\Services\ContractTicketService::STATE_UPCOMING,
        ])
        data-id="{{ $usage->id }}"
        data-client-contract-id="{{ $usage->client_contract_id }}"
        data-trainee-id="{{ $usage->trainee_id }}"
        data-used-on="{{ $usage->used_on->format('Y-m-d') }}"
        data-start-time="{{ $usage->start_time_input }}"
        data-end-time="{{ $usage->end_time_input }}"
        data-note="{{ $usage->note }}"
        data-usage-state="{{ $state }}"
        onclick="window.usageModal.openForEdit(this.dataset)"><span class="usage-row-label">@if($state === \App\Services\ContractTicketService::STATE_TODAY)<span class="badge text-bg-dark">今日</span>@elseif($state === \App\Services\ContractTicketService::STATE_UPCOMING)<span class="badge text-bg-info">予定</span>@endif</span><span class="usage-row-date">{{ $date }}</span><span class="usage-row-time">{{ $usage->time_range_label }}</span><span class="usage-row-trainee text-truncate">{{ $usage->trainee?->display_name }}</span>@if($note)<span class="usage-row-note text-body-secondary text-truncate">{{ $note }}</span>@endif</button>
@if($googleCalendarUrl !== null)
    <x-google-calendar-link :url="$googleCalendarUrl" class="usage-row-calendar d-flex align-items-center flex-shrink-0 px-1" />
@endif
</div>
