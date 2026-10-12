{{-- 残り回数の表示（S-0304 会員一覧の「残り回数」列・S-0305 会員詳細の「回数」カードの見出しで共用）。
     表示の規則（設計書 screen-design.md §2-4「例外：残り回数」・S-0305 セクション5「カードヘッダー」）：
       - 契約も利用も 1 件もない会員（$value が null）は空欄
       - 「回」を付けず数字だけで表示し、マイナスは赤字（text-danger）で「-n」
       - 残りが 0 なら「0」
     値の計算は ContractTicketService::remaining() で済ませてから渡す。

     大きさなどのクラスは、呼び出し側で class 属性として渡すと数字の要素に付く
     （会員詳細の回数カードの見出しでは class="fs-5 ms-2" を渡して数字を少し大きくする）。
     マイナスのときは渡したクラスに text-danger が加わる。
     class を渡さない呼び出し（会員一覧）は、従来と同じ出力（<span> / <span class="text-danger">）のまま。 --}}
@props(['value'])
@if(! is_null($value))
    @if($value < 0 || $attributes->has('class'))
        <span {{ $attributes->class(['text-danger' => $value < 0]) }}>{{ $value }}</span>
    @else
        <span>{{ $value }}</span>
    @endif
@endif
