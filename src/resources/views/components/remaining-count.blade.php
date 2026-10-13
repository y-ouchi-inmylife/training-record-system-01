{{-- 残り回数の表示（S-0304 会員一覧の「ご利用回数」列・S-0305 会員詳細の「回数」カードの見出しで共用）。
     表示の規則（設計書 screen-design.md §2-4「例外：残り回数」・S-0305 セクション5「カードヘッダー」）：
       - 契約も利用も 1 件もない会員（$value が null）は空欄
       - 言葉は会員側（S-1412）にそろえて「あと n回」。残りが 0 なら「あと 0回」
       - マイナスは赤字（text-danger）で「契約 n回分不足」（n は絶対値。赤字は「契約 n回分不足」の全体）。
         「超過」は何を超えたか分かりにくいため、トレーナーがすること（契約を足す）が分かる言い方にしている
     値の計算は ContractTicketService::remaining() で済ませてから渡す。

     大きさなどのクラスは、呼び出し側で class 属性として渡すと**数字の部分の要素**に付く
     （会員詳細の回数カードの見出しでは class="fs-5" を渡して数字だけを少し大きくする。「あと」「回」は普通の大きさ）。
     class を渡さない呼び出し（会員一覧）は、数字を要素で囲まない。 --}}
@props(['value'])
@if(! is_null($value))
    @if($value < 0)
        <span class="text-danger">契約 @if($attributes->isNotEmpty())<span {{ $attributes }}>{{ abs($value) }}</span>@else{{ abs($value) }}@endif回分不足</span>
    @else
        <span>あと @if($attributes->isNotEmpty())<span {{ $attributes }}>{{ $value }}</span>@else{{ $value }}@endif回</span>
    @endif
@endif
