{{--
    肉球のマーク（2026-11 追加。メディア一覧 S-1302 のカードの「トレーニー・会員」の行の先頭）

    Bootstrap Icons に肉球がないため、自前の小さなインライン SVG にした（外部のアイコン集から取ってきたものではない）。
    形は、大きな手のひら 1 つ（下の楕円。最初の ellipse）と指 4 つ（上に弧を描いて並ぶ小さな楕円。左から順）だけの
    単純なもの。viewBox は 16×16。

    - 大きさは文字の高さ（1em）。色は fill="currentColor" で、呼ぶ側のクラスで決める（例：text-primary）
    - 飾りなので aria-hidden="true"・focusable="false"（読み上げは隣の文字で足りる）
    - 文字と縦の位置をそろえるため、vertical-align を -0.125em にする（Bootstrap Icons の .bi と同じ値）。
      d-flex align-items-center の中で使うときは、flex の縦中央でそろう

    使い方:
      <x-icon-paw class="text-primary flex-shrink-0" />
--}}
<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false" style="vertical-align: -0.125em;" {{ $attributes->merge(['class' => 'icon-paw']) }}>
    <ellipse cx="8" cy="11.2" rx="3.6" ry="3.2" />
    <ellipse cx="2.6" cy="6.9" rx="1.5" ry="1.9" />
    <ellipse cx="5.7" cy="3.8" rx="1.5" ry="1.9" />
    <ellipse cx="10.3" cy="3.8" rx="1.5" ry="1.9" />
    <ellipse cx="13.4" cy="6.9" rx="1.5" ry="1.9" />
</svg>
