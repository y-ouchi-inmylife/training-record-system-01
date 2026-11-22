{{-- ご利用回数（S-1412）の利用のマス 1 つ（2026-11 追加。契約のカードと「契約に含まれないご利用」で使う）。
     $slot は Client\ContractController::slot() で組み立てた配列（date・state・mark）。
     利用日が今日・今日より後のマスは、日付の上に「本日」「ご予約」の印を出し、面と枠を変える（設計書 S-1412「マスの予約の印」）。
     読むだけの画面なので data-* は付けない。利用のメモは出さない --}}
<span @class(['c-ticket-slot', 'num-tabular', 'c-ticket-slot--today' => $slot['state'] === \App\Services\ContractTicketService::STATE_TODAY, 'c-ticket-slot--upcoming' => $slot['state'] === \App\Services\ContractTicketService::STATE_UPCOMING])>@if($slot['mark'] !== null)<span class="c-ticket-slot-mark">{{ $slot['mark'] }}</span>@endif<span>{{ $slot['date'] }}</span></span>
