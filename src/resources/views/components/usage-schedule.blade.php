{{-- 利用の予約状況の部品（カレンダーとその日の予約。2026-11 追加。設計書 screen-design.md S-0305-M05「予約状況」）。

     利用モーダル（clients/_usage-modal.blade.php）から切り離した部品。カレンダー画面（S-1602。schedule/index.blade.php。2026-11 追加）でも使う。
     中身（月のマス・預かる数・その日の予約）は JS（resources/js/usage-schedule.js の UsageSchedule）が組み立て、
     サーバーの JSON（api-design.md 4-2-6。data-month-url・data-day-url）から読む。ここは入れ物だけを置く。

     使い方：<x-usage-schedule id="usageSchedule" />
       JS：new window.UsageSchedule(document.getElementById('usageSchedule'), { onSelect: (ymd) => { ... } })

     設定（2026-11 追加。設計書 S-1602「利用モーダルとの違い」）：
       layout     ：'stacked'（初期値。カレンダーの下にその日の予約。利用モーダル）／'side'（広い画面〔lg 以上〕で、左にカレンダー、
                    右にその日の予約。狭い画面では下に回す。カレンダー画面）
       client-url ：会員詳細の URL の型（会員の id のところを __CLIENT_ID__ にしたもの）。JS の linkClients: true のときに、
                    一覧の会員名をリンクにするのに使う。リンクにしない利用モーダルでは渡さない --}}
@props(['id', 'layout' => 'stacked', 'clientUrl' => null])

<div id="{{ $id }}" class="usage-schedule"
     data-usage-schedule
     data-month-url="{{ route('api.usage-schedule.month') }}"
     data-day-url="{{ route('api.usage-schedule.day') }}"
     @if($clientUrl !== null) data-client-url="{{ $clientUrl }}" @endif>
    <div @class(['row gx-4' => $layout === 'side'])>
        <div @class(['col-lg-6' => $layout === 'side'])>
            {{-- カレンダー（月の表示。［＜］［＞］で前月・翌月へ） --}}
            <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                <button type="button" class="btn btn-sm btn-link text-decoration-none" data-usage-schedule-prev aria-label="前の月">＜</button>
                <div class="fw-bold text-center" style="min-width: 8em;" data-usage-schedule-heading aria-live="polite"></div>
                <button type="button" class="btn btn-sm btn-link text-decoration-none" data-usage-schedule-next aria-label="次の月">＞</button>
            </div>
            <table class="table table-borderless table-sm text-center mb-3" style="table-layout: fixed;">
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
                <tbody data-usage-schedule-days></tbody>
            </table>
            {{-- マスの数字の説明の行は置かない（2026-11 変更。マスの 2 つの数字の意味は、その日の予約の見出し
                 「M/D(曜) の予約 n件　同時に預かる数 m」で分かるため。画面を見られない人にはマスの読み上げの文で伝える）。
                 カレンダーとその日の予約のあいだは、表の下の mb-3 で空ける（mb-1 のままだと、マスの行のあいだと同じ 8px になり、
                 一覧がカレンダーの続きに見えるため） --}}
            {{-- 読み込み中・失敗の知らせ（失敗しても、使う側の入力と保存は止めない） --}}
            <div class="small text-body-secondary mb-2 d-none" data-usage-schedule-status role="status"></div>
        </div>

        <div @class(['col-lg-6' => $layout === 'side'])>
            {{-- その日の予約（見出し「M/D(曜) の予約 n件　同時に預かる数 m」と、開始時刻の順の一覧） --}}
            <div class="card">
                <div class="card-header small fw-bold py-1" data-usage-schedule-day-heading></div>
                <ul class="list-group list-group-flush" data-usage-schedule-day-list></ul>
            </div>
        </div>
    </div>
</div>

@vite('resources/js/usage-schedule.js')
