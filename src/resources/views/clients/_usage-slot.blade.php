{{-- 回数券型の「マス（利用済み）」1 つ分（S-0305 セクション5「回数」）。
     契約のマスと「（契約なし）」の行（未割り当て）のマスで共用する。押すとその利用の編集モーダル（S-0305-M05）を開く。
     data-client-contract-id は今割り当てられている契約（未割り当ては空）。編集モーダルの「契約」の初期値に使う。
     利用 1 件は 1 回分で、1 マスに対応する（2 回分使ったときは利用が 2 件あり、マスも 2 つ）。
     メモがある利用には小さな印（アイコン）を付け、内容は編集モーダルで確かめる。

     期待する変数:
       - $usage : App\Models\ClientUsage --}}
<button type="button" class="btn btn-sm btn-outline-secondary ticket-slot"
        data-id="{{ $usage->id }}"
        data-client-contract-id="{{ $usage->client_contract_id }}"
        data-used-on="{{ $usage->used_on->format('Y-m-d') }}"
        data-note="{{ $usage->note }}"
        onclick="window.usageModal.openForEdit(this.dataset)">{{ $usage->used_on->format('Y/m/d') }}@if($usage->note)<i class="bi bi-chat-left-text ms-1" aria-hidden="true"></i><span class="visually-hidden">（メモあり）</span>@endif</button>
