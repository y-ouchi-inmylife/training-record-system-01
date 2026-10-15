{{-- 案内を見分ける帯（S-0307 マイページ登録案内・S-0311 受付アンケート案内で共用。2026-10 追加）。
     2 つの案内を取り違えないよう、ページの上（操作エリアの下、見出しの上）に案内ごとの色の帯とアイコンを付ける
     （設計書 screen-design.md S-0307 備考「案内を見分ける帯」）。
       - label：帯の文字（読み上げる）
       - variant：色（mypage＝青、intake＝緑）。色の指定は resources/sass/app.scss の .print-band--{variant}
       - icon：Bootstrap Icons の名前（bi- を除いた部分）。見た目の印なので読み上げない（aria-hidden）
     印刷でも帯の色が出るよう、色は背景ではなく太い枠線で塗る（app.scss のコメント参照）。 --}}
@props(['label', 'variant', 'icon'])
<div class="print-band print-band--{{ $variant }}">
    <p class="print-band-label"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</p>
</div>
