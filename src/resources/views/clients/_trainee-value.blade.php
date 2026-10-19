{{-- 会員詳細のトレーニーの欄の値（犬種・性別・誕生日・去勢・避妊。2026-10-19 追加。設計書 S-0305「名前の下の項目の並びと出し方」）。
     値が空（null・空文字・空白だけ。全角の空白も含む）なら「—」（全角ダッシュ。トレーニー詳細の点数の表で空の値に使っている印と同じ）を
     薄い灰色（「診断なし」「計測なし」と同じ text-muted）で出し、ほかの行と同じ高さにそろえる。「—」は読み上げず、隠し文字で「未入力」と読む。
     $value：出す値（文字列か null） --}}
@if(preg_replace('/[\s\x{3000}]+/u', '', (string) $value) === '')
    <span class="text-muted" data-trainee-value-empty><span aria-hidden="true">—</span><span class="visually-hidden">未入力</span></span>
@else
    {{ $value }}
@endif
