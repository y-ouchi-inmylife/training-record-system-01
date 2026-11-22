{{-- 「Googleカレンダーに追加」のリンク（2026-11 追加。設計書 S-0305 セクション5「Googleカレンダーに追加」）。
     別タブで Google カレンダーの登録画面を開く。URL はサーバー側（GoogleCalendarLinkService）で作ったものを渡す。
     会員詳細の利用の行（アイコンだけ）と、完了メッセージ（文字も出す。with-text）で使う。
     カレンダー画面（S-1602）の行は JS（usage-schedule.js）が同じ形で作る --}}
@props(['url', 'withText' => false])

<a href="{{ $url }}" target="_blank" rel="noopener noreferrer" title="Googleカレンダーに追加（別タブで開きます）" aria-label="Googleカレンダーに追加（別タブで開きます）" {{ $attributes }}><i class="bi bi-calendar-plus" aria-hidden="true"></i>@if($withText) Googleカレンダーに追加@endif</a>
