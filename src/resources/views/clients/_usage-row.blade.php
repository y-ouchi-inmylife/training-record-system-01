{{-- ご利用回数（S-0305 セクション5「回数」）の「利用の行」1 つ分（2026-11 追加。設計書 S-0305 セクション5「利用の行」）。
     1 行に 1 利用。その前の回数券型のマス（clients/_usage-slot.blade.php）に代わる部品で、会員側（S-1412）は使わない
     （S-1412 は client/contracts/index.blade.php の中で回数券型のマスを組み立てる）。
     契約の下の行と、「（契約なし）」のまとまりの行で共用する。押すとその利用の編集モーダル（S-0305-M05）を開く。

     列の順：ラベル（今日・予定。実施済みは空欄）→ 日付（曜日）→ 時刻 → トレーニー → メモの印
     - ラベル・日付・時刻の列は幅をそろえる（show.blade.php の .usage-row-*）。狭い幅では行の中で折り返す
     - トレーニーはトレーナー側の表示名（名前未登録なら「名前未登録」）。「トレーニーなし」は空欄。入らなければ「…」
     - 見た目：今日は左端に太い線（.usage-row-today）、予定は薄い青の背景（bg-info-subtle）
     実施済み・今日・予定の判定と曜日付きの日付は ContractTicketService（usageStates・usageDates）で済ませ、ここでは出し分けるだけ。

     data-*（編集モーダルの初期値）：data-client-contract-id（未割り当ては空）・data-trainee-id（「トレーニーなし」は空）・
     data-used-on（Y-m-d）・data-start-time・data-end-time（HH:MM）・data-note

     期待する変数:
       - $usage : App\Models\ClientUsage（trainee を読み込んでおく）
       - $state : string ContractTicketService::STATE_PAST / STATE_TODAY / STATE_UPCOMING
       - $date  : string 曜日付きの利用日（「2026/11/26(木)」） --}}
<button type="button" @class([
            'list-group-item list-group-item-action usage-row d-flex flex-wrap align-items-center column-gap-2 py-1 px-2 small',
            'usage-row-today' => $state === \App\Services\ContractTicketService::STATE_TODAY,
            'bg-info-subtle' => $state === \App\Services\ContractTicketService::STATE_UPCOMING,
        ])
        data-id="{{ $usage->id }}"
        data-client-contract-id="{{ $usage->client_contract_id }}"
        data-trainee-id="{{ $usage->trainee_id }}"
        data-used-on="{{ $usage->used_on->format('Y-m-d') }}"
        data-start-time="{{ $usage->start_time_input }}"
        data-end-time="{{ $usage->end_time_input }}"
        data-note="{{ $usage->note }}"
        data-usage-state="{{ $state }}"
        onclick="window.usageModal.openForEdit(this.dataset)"><span class="usage-row-label">@if($state === \App\Services\ContractTicketService::STATE_TODAY)<span class="badge text-bg-dark">今日</span>@elseif($state === \App\Services\ContractTicketService::STATE_UPCOMING)<span class="badge text-bg-info">予定</span>@endif</span><span class="usage-row-date">{{ $date }}</span><span class="usage-row-time">{{ $usage->time_range_label }}</span><span class="usage-row-trainee text-truncate">{{ $usage->trainee?->display_name }}</span>@if($usage->note)<i class="bi bi-chat-left-text" aria-hidden="true"></i><span class="visually-hidden">（メモあり）</span>@endif</button>
