{{-- 利用の予約状況の部品（カレンダーとその日の予約。2026-11 追加。設計書 screen-design.md S-0305-M05「予約状況」）。

     利用モーダル（clients/_usage-modal.blade.php）から切り離した部品。カレンダー画面（S-1602。schedule/index.blade.php。2026-11 追加）でも使う。
     中身（月のマス・預かる数・その日の予約）は JS（resources/js/usage-schedule.js の UsageSchedule）が組み立て、
     サーバーの JSON（api-design.md 4-2-6。data-month-url・data-day-url）から読む。ここは入れ物だけを置く。

     使い方：<x-usage-schedule id="usageSchedule" />
       JS：new window.UsageSchedule(document.getElementById('usageSchedule'), { onSelect: (ymd) => { ... } })

     設定（2026-11 追加。設計書 S-1602「利用モーダルとの違い」）：
       layout     ：'stacked'（初期値。カレンダーの下にその日の予約。利用モーダル）／'day-first'（上にその日の予定を横いっぱい、
                    下にカレンダーを今までの大きさで左寄せ。どの幅でも同じ。カレンダー画面。2026-11 変更：その前は 'side'〔広い画面で左右に並べる〕）。
                    'day-first' は、その日の予定をカレンダーより前に置く（見た目の順と読み上げ・タブの順をそろえる）
       client-url ：会員詳細の URL の型（会員の id のところを __CLIENT_ID__ にしたもの）。JS の linkClients: true のときに、
                    一覧の会員名をリンクにするのに使う。リンクにしない利用モーダルでは渡さない --}}
@props(['id', 'layout' => 'stacked', 'clientUrl' => null])

<div id="{{ $id }}" class="usage-schedule"
     data-usage-schedule
     data-month-url="{{ route('api.usage-schedule.month') }}"
     data-day-url="{{ route('api.usage-schedule.day') }}"
     @if($clientUrl !== null) data-client-url="{{ $clientUrl }}" @endif>
    {{-- その日の予定（カレンダー画面 'day-first' では上。2026-11 変更） --}}
    @if($layout === 'day-first')
        <x-usage-schedule-day />
    @endif

    {{-- カレンダー。'day-first' では下に、今までの大きさで左寄せ（.usage-schedule-calendar。最大の幅 34rem）にし、上の予定とのあいだを空ける --}}
    <div @class(['usage-schedule-calendar mt-3' => $layout === 'day-first'])>
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
             「M/D(曜)　n件　同時に預かる数 m」で分かるため。画面を見られない人にはマスの読み上げの文で伝える）。
             カレンダーとその日の予約のあいだは、表の下の mb-3 で空ける（mb-1 のままだと、マスの行のあいだと同じ 8px になり、
             一覧がカレンダーの続きに見えるため） --}}
        {{-- 読み込み中・失敗の知らせ（失敗しても、使う側の入力と保存は止めない） --}}
        <div class="small text-body-secondary mb-2 d-none" data-usage-schedule-status role="status"></div>
    </div>

    {{-- その日の予約（利用モーダルではカレンダーの下） --}}
    @if($layout !== 'day-first')
        <x-usage-schedule-day />
    @endif
</div>

@vite('resources/js/usage-schedule.js')
