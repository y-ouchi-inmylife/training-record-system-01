@extends('layouts.app')

@section('title', 'カレンダー')

{{-- カレンダー（S-1602。2026-11 追加。設計書 screen-design.md S-1602）。会員を選ばずに、事業所全体の利用（予約）を見る、見るだけの画面。
     利用の登録・編集・削除は会員詳細（S-0305）の利用モーダルで行うので、ボタンは置かない。
     カレンダーとその日の予約は利用モーダルと同じ部品（<x-usage-schedule>・resources/js/usage-schedule.js）で、見た目と決まりは S-0305-M05「予約状況」のとおり。
     利用モーダルとの違いは部品の設定で切り替える：layout="side"（広い画面で左右に並べる）・client-url と linkClients（会員名を会員詳細へのリンクにする）・
     syncUrl（選んでいる日を URL の date に書き換える）・timetable（広い画面でその日の予約をタイムテーブルにする。2026-11 追加）。選んでいる日 $date・今日 $today はコントローラーで決める --}}
@section('content')
<div class="container">
    <h2 class="mb-4">カレンダー</h2>

    <x-usage-schedule id="scheduleCalendar" layout="side"
                      :client-url="route('clients.show', ['client' => '__CLIENT_ID__'])" />
</div>
@endsection

@push('scripts')
<script>
    // 部品の JS（Vite の module）は DOMContentLoaded より前に読み終わるので、ここで作る
    document.addEventListener('DOMContentLoaded', function () {
        const schedule = new window.UsageSchedule(document.getElementById('scheduleCalendar'), { linkClients: true, syncUrl: true, timetable: true });
        schedule.open(@json($date), { fallbackDate: @json($today) });
    });
</script>
@endpush
