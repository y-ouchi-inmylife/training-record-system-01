{{-- 案内を見分ける帯＝ページの見出し（S-0307 マイページ登録案内・S-0311 愛犬アンケート案内・S-0312 性格診断の案内で共用。2026-10 追加）。
     案内を取り違えないよう、ページの上（操作エリアの下）に案内ごとの色の帯とアイコンを付け、帯の文字を
     ページの見出し（h1。ページの中の見出しはこれ 1 つ）にする（設計書 screen-design.md S-0307 備考「案内を見分ける帯」）。
       - label：帯の文字＝見出し（例：「マイページ登録のご案内」。読み上げる）
       - variant：色（mypage＝青、intake＝緑、personality＝紫）。色の指定は resources/sass/app.scss の .print-band--{variant}
       - icon：Bootstrap Icons の名前（bi- を除いた部分）。見た目の印なので読み上げない（aria-hidden）
     印刷でも帯の色が出るよう、色は背景ではなく枠線で塗る（app.scss のコメント参照）。
     2026-10-18 変更：案内の画面は印刷しなくなったが、帯は見出しとして残す（名前の print- はそのまま） --}}
@props(['label', 'variant', 'icon'])
<h1 class="print-band print-band--{{ $variant }}"><span class="print-band-label"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</span></h1>
