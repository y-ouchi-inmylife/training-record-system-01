{{-- 利用の予約状況の部品（<x-usage-schedule>）の、1 か月分の表（曜日の見出しと日のマスの入れ物。2026-11 追加。<x-usage-schedule> の中だけで使う）。
     カレンダー画面で 2 か月並べるときに、表を 2 か所に書かないよう、ここに分けた。日のマスは JS（usage-schedule.js）が入れる。
     second：2 つ目（翌月）。広い画面（xl 以上）だけ出す --}}
@props(['second' => false])

<table @class(['table table-borderless table-sm text-center mb-3', 'd-none d-xl-table' => $second]) style="table-layout: fixed;">
    <thead>
        <tr class="small text-body-secondary">
            <th scope="col" class="fw-normal p-1">日</th>
            <th scope="col" class="fw-normal p-1">月</th>
            <th scope="col" class="fw-normal p-1">火</th>
            <th scope="col" class="fw-normal p-1">水</th>
            <th scope="col" class="fw-normal p-1">木</th>
            <th scope="col" class="fw-normal p-1">金</th>
            <th scope="col" class="fw-normal p-1">土</th>
        </tr>
    </thead>
    @if($second)<tbody data-usage-schedule-days-2></tbody>@else<tbody data-usage-schedule-days></tbody>@endif
</table>
