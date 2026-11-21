{{-- 回数券型の「マス（利用済み）」1 つ分（S-0305 セクション5「回数」）。
     契約のマスと「（契約なし）」の行（未割り当て）のマスで共用する。押すとその利用の編集モーダル（S-0305-M05）を開く。
     data-client-contract-id は今割り当てられている契約（未割り当ては空）。編集モーダルの「契約」の初期値に使う。
     data-trainee-id（「トレーニーなし」は空）・data-start-time・data-end-time（HH:MM）は編集モーダルの初期値に使う（2026-11 追加）。
     利用 1 件は 1 回分で、1 マスに対応する（2 回分使ったときは利用が 2 件あり、マスも 2 つ）。
     メモがある利用には小さな印（アイコン）を付け、内容は編集モーダルで確かめる。

     2 行で出す（2026-11 変更。設計書 S-0305 セクション5「マス（利用済み）」）：
       1 行目：利用日（Y/m/d）。今日は「今日」、今日より後（予定）は「予定」の小さなラベルを付ける
       2 行目：トレーニーの表示名（トレーナー側。長いときは「…」）と時刻（8:00〜19:00）を小さめの文字で。
               「トレーニーなし」の利用は時刻だけ
     見た目：実施済みは今のまま、今日は太い枠（border-2）、予定は薄い青の背景（bg-info-subtle）。
     実施済み・今日・予定の判定は ContractTicketService（usageStates）で済ませ、ここでは受け取った状態で出し分けるだけ。

     期待する変数:
       - $usage : App\Models\ClientUsage（trainee を読み込んでおく）
       - $state : string ContractTicketService::STATE_PAST / STATE_TODAY / STATE_UPCOMING --}}
<button type="button" @class([
            'btn btn-sm btn-outline-secondary ticket-slot',
            'border-2' => $state === \App\Services\ContractTicketService::STATE_TODAY,
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
        onclick="window.usageModal.openForEdit(this.dataset)"><span class="d-block">{{ $usage->used_on->format('Y/m/d') }}@if($state === \App\Services\ContractTicketService::STATE_TODAY)<span class="badge text-bg-dark ms-1">今日</span>@elseif($state === \App\Services\ContractTicketService::STATE_UPCOMING)<span class="badge text-bg-info ms-1">予定</span>@endif @if($usage->note)<i class="bi bi-chat-left-text ms-1" aria-hidden="true"></i><span class="visually-hidden">（メモあり）</span>@endif</span><span class="d-flex justify-content-center gap-1 small">@if($usage->trainee)<span class="text-truncate ticket-slot-trainee">{{ $usage->trainee->display_name }}</span>@endif<span class="text-nowrap">{{ $usage->time_range_label }}</span></span></button>
