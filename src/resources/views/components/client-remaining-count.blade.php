{{-- 会員向けの全体の数（設計書 S-1412「全体の数」・S-1402「ご利用回数（入口）」で共用）。
     - 0 以上：「あと n回」（0 のときは「あと 0回」）。「ご利用回数」の数字は使った回数と読まれる
       おそれがあるため、必ず「あと」を付けて残りの数であることが分かるようにする
     - マイナス：「ご契約の回数を n回超えてご利用中です」（n はマイナスの絶対値）。
       会員に問題があったように受け取られないよう、赤字にせず通常の文字色の文で伝える
     契約も利用もないとき（$value が null）は呼び出し側で出さない。
     数の計算は ContractTicketService::remaining() で済ませてから渡す。
     大きさなどのクラスは、呼び出し側で class 属性として渡す。 --}}
@props(['value'])
@if($value < 0)
    <span {{ $attributes->class(['c-remaining', 'c-remaining--over']) }}>ご契約の回数を <span class="num-tabular">{{ abs($value) }}</span>回超えてご利用中です</span>
@else
    <span {{ $attributes->class(['c-remaining']) }}>あと <span class="num-tabular c-remaining-num">{{ $value }}</span>回</span>
@endif
