{{-- 利用の予約状況の部品（<x-usage-schedule>）の、その日の予約の枠（2026-11 追加。<x-usage-schedule> の中だけで使う）。
     並び（カレンダーの下か上か）は <x-usage-schedule> の layout で決まるので、枠を 2 か所に書かないよう、ここに分けた。
     見出し「M/D(曜)　n件　同時に預かる数 m」と、開始時刻の順の一覧（カレンダー画面の広い画面ではタイムテーブルも）は JS（usage-schedule.js）が入れる。
     data-usage-schedule-day-body は一覧・タイムテーブル・知らせの入れ物（カレンダー画面では、最低の高さをここに付ける。2026-11 追加） --}}
<div class="card">
    <div class="card-header small fw-bold py-1" data-usage-schedule-day-heading></div>
    <div data-usage-schedule-day-body>
        <ul class="list-group list-group-flush" data-usage-schedule-day-list></ul>
    </div>
</div>
