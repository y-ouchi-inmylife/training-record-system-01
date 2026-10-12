{{-- 残り回数の表示（S-0304 会員一覧の「残り回数」列・S-0305 会員詳細の「回数」カードの見出しで共用）。
     表示の規則（設計書 screen-design.md §2-4「例外：残り回数」・S-0305 セクション5「カードヘッダー」）：
       - 契約も利用も 1 件もない会員（$value が null）は空欄
       - 「回」を付けず数字だけで表示し、マイナスは赤字（text-danger）で「-n」
       - 残りが 0 なら「0」
     値の計算は ContractTicketService::remaining() で済ませてから渡す。 --}}
@props(['value'])
@if(! is_null($value))
    @if($value < 0)
        <span class="text-danger">{{ $value }}</span>
    @else
        <span>{{ $value }}</span>
    @endif
@endif
