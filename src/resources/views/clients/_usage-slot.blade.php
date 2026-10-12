{{-- 回数券型の「マス（利用済み）」1 つ分（S-0305 セクション5「回数」）。
     契約のマスと超過のマスで共用する。押すとその利用の編集モーダル（S-0305-M05）を開く。
     2 回分の利用は 2 マスに出るが、どちらも同じ 1 件の data-* を持つため同じ編集が開く。
     メモがある利用には小さな印（アイコン）を付け、内容は編集モーダルで確かめる。

     期待する変数:
       - $usage : App\Models\ClientUsage --}}
<button type="button" class="btn btn-sm btn-outline-secondary ticket-slot"
        data-id="{{ $usage->id }}"
        data-used-on="{{ $usage->used_on->format('Y-m-d') }}"
        data-count="{{ $usage->count }}"
        data-note="{{ $usage->note }}"
        onclick="window.usageModal.openForEdit(this.dataset)">{{ $usage->used_on->format('Y/m/d') }}@if($usage->note)<i class="bi bi-chat-left-text ms-1" aria-hidden="true"></i><span class="visually-hidden">（メモあり）</span>@endif</button>
