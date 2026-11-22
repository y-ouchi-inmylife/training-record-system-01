/**
 * 利用の予約状況の部品（カレンダーとその日の予約。2026-11 追加。設計書 screen-design.md S-0305-M05「予約状況」、api-design.md 4-2-6）。
 *
 * マークアップは匿名コンポーネント <x-usage-schedule>（resources/views/components/usage-schedule.blade.php）。
 * 利用モーダル（S-0305-M05）から切り離して作り、カレンダー画面（S-1602。2026-11 追加）でも使う。
 * 部品は利用日の欄を直接は触らない。日付を押したことを onSelect で知らせ、使う側（利用モーダルのスクリプト）が欄に入れる。
 *
 * 設定（2026-11 追加。設計書 S-1602「利用モーダルとの違い」。初期値は利用モーダルの動き）：
 *   onSelect    ：日付を押したときに呼ぶ（利用モーダルが利用日の欄に入れる）
 *   linkClients ：true なら、その日の予約の会員名を会員詳細へのリンクにする（URL の型は rootEl の data-client-url）。初期値 false
 *   syncUrl     ：true なら、選んでいる日が変わったときに URL の date を書き換える（history.replaceState）。初期値 false
 *   timetable   ：true なら、広い画面（lg 以上）でその日の予約を横向きのタイムテーブルにする（狭い画面はリストのまま。
 *                 リストとタイムテーブルを両方作り、CSS の d-lg-none・d-none d-lg-block で出し分ける）。初期値 false。2026-11 追加
 *   判定と組み立ては usage-schedule-core.js（usageLineParts・scheduleDateUrl・timetableModel など）
 *
 * 使い方：
 *   const schedule = new window.UsageSchedule(rootEl, { onSelect: (ymd) => { ... } });
 *   new window.UsageSchedule(rootEl, { linkClients: true, syncUrl: true, timetable: true });  // カレンダー画面
 *   schedule.open('2026-11-26', { excludeUsageId: 12, fallbackDate: '2026-11-21' }); // 開いたとき（編集は除く利用の id を渡す）
 *   schedule.setDate(inputValue);                     // 利用日の欄に入力したとき（正しい日付のときだけ動く）
 *
 * 預かる数・予約はサーバーから JSON で読む（rootEl の data-month-url・data-day-url。権限は利用の登録・編集と同じ）。
 * 読み込み中は「読み込み中…」、失敗したら「予約状況を読み込めませんでした。」を出す。失敗しても使う側の入力・保存は止めない。
 * 月を移したとき・選んだ日を変えたときに読み直す。古い要求の答えが後から届いても、新しい表示を上書きしない（連番で見分ける）。
 * 計算（月のマス・状態の移り方）は usage-schedule-core.js。
 */
import {
    WEEKDAYS,
    applyInput,
    usageCheckQuery,
    usageConfirmMessage,
    capacityState,
    cellCountAriaText,
    cellBottomRow,
    dayCellClasses,
    dayHeadingParts,
    isPastDay,
    initialState,
    monthHeading,
    monthWeeks,
    moveMonth,
    scheduleDateUrl,
    selectDate,
    usageLineParts,
    timetableModel,
    timetableHours,
    timetableLabelStep,
    timetableLabelPlacement,
} from './usage-schedule-core.js';

const LOADING = '読み込み中…';
const FAILED = '予約状況を読み込めませんでした。';

class UsageSchedule {
    constructor(root, { onSelect = () => {}, linkClients = false, syncUrl = false, timetable = false } = {}) {
        this.root = root;
        this.onSelect = onSelect;
        // 会員名をリンクにするときの会員詳細の URL の型（linkClients が false なら null。2026-11 追加）
        this.clientUrlTemplate = linkClients ? (root.dataset.clientUrl || null) : null;
        this.syncUrl = syncUrl;
        // 広い画面のタイムテーブル（2026-11 追加。カレンダー画面だけ）。入れ物はリストの後ろに作る
        this.timetableEl = null;
        this.timetableRows = [];
        this.hourEls = [];
        if (timetable) {
            this.timetableEl = document.createElement('div');
            // 出すときだけ d-lg-block を付ける（hidden 属性は Bootstrap の d-lg-block〔!important〕に負けるので使わない）
            this.timetableEl.className = 'usage-timetable d-none';
            this.timetableEl.dataset.usageScheduleTimetable = '';
            // 幅が変わったら、時の数字の間引きと帯の時刻の置き場所を決め直す（狭い画面では出していないので何もしない）
            window.addEventListener('resize', () => {
                window.requestAnimationFrame(() => this.layoutTimetable());
            });
        }
        this.monthUrl = root.dataset.monthUrl;
        this.dayUrl = root.dataset.dayUrl;
        this.state = null;
        this.excludeUsageId = null;
        this.today = null;          // サーバーの今日（月の JSON の today）
        this.capacity = null;       // 同時に預かれる数（JSON の capacity）
        this.counts = {};           // 出している月の日ごとの預かる数
        this.usageCounts = {};      // 出している月の日ごとの予約の件数（2026-11 追加。月の JSON の usage_counts）
        this.countsLoaded = false;  // 出している月の預かる数を読み込んだか（読み込む前・失敗は同時に預かる数の数字を出さない。2026-11 追加）
        this.monthSeq = 0;
        this.daySeq = 0;

        this.headingEl = root.querySelector('[data-usage-schedule-heading]');
        this.daysEl = root.querySelector('[data-usage-schedule-days]');
        this.statusEl = root.querySelector('[data-usage-schedule-status]');
        this.dayHeadingEl = root.querySelector('[data-usage-schedule-day-heading]');
        this.dayListEl = root.querySelector('[data-usage-schedule-day-list]');
        if (this.timetableEl) {
            this.dayListEl.after(this.timetableEl);
        }

        root.querySelector('[data-usage-schedule-prev]').addEventListener('click', () => this.move(-1));
        root.querySelector('[data-usage-schedule-next]').addEventListener('click', () => this.move(1));
        this.daysEl.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-date]');
            if (!button) return;
            this.select(button.dataset.date);
            this.onSelect(button.dataset.date);
        });
    }

    /** 開いたとき。日付が正しくなければ fallbackDate（今日）の月・日にする */
    open(ymd, { excludeUsageId = null, fallbackDate } = {}) {
        this.excludeUsageId = excludeUsageId === null || excludeUsageId === '' ? null : String(excludeUsageId);
        this.state = initialState(ymd, fallbackDate);
        this.counts = {};
        this.usageCounts = {};
        this.countsLoaded = false;
        this.renderMonth();
        this.loadMonth();
        this.loadDay();
    }

    /** 利用日の欄に入力したとき。正しい日付ならその月に移ってその日を選ぶ */
    setDate(value) {
        if (!this.state) return;
        const next = applyInput(this.state, value);
        this.update(next);
    }

    select(ymd) {
        this.update(selectDate(this.state, ymd));
    }

    move(delta) {
        this.update(moveMonth(this.state, delta));
    }

    update(next) {
        const monthChanged = next.month !== this.state.month;
        const dayChanged = next.selected !== this.state.selected;
        if (!monthChanged && !dayChanged) return;
        // 選んでいる日が変わったら URL の date を書き換える（syncUrl のときだけ。前月・翌月では選んでいる日は変わらない。2026-11 追加）
        const url = scheduleDateUrl({ href: window.location.href, syncUrl: this.syncUrl, previous: this.state.selected, selected: next.selected });
        if (url !== null) {
            window.history.replaceState(window.history.state, '', url);
        }
        this.state = next;
        if (monthChanged) {
            this.counts = {};
            this.usageCounts = {};
            this.countsLoaded = false;
        }
        this.renderMonth();
        if (monthChanged) this.loadMonth();
        if (dayChanged) this.loadDay();
    }

    params(extra) {
        const params = new URLSearchParams(extra);
        if (this.excludeUsageId !== null) {
            params.set('exclude_usage_id', this.excludeUsageId);
        }

        return params.toString();
    }

    async fetchJson(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return response.json();
    }

    setStatus(text, isError = false) {
        this.statusEl.textContent = text;
        this.statusEl.classList.toggle('text-danger', isError);
        this.statusEl.classList.toggle('text-body-secondary', !isError);
        this.statusEl.classList.toggle('d-none', text === '');
    }

    async loadMonth() {
        const seq = ++this.monthSeq;
        const month = this.state.month;
        this.setStatus(LOADING);
        try {
            const data = await this.fetchJson(`${this.monthUrl}?${this.params({ month })}`);
            if (seq !== this.monthSeq) return;
            this.counts = data.days || {};
            this.usageCounts = data.usage_counts || {};
            this.countsLoaded = true;
            this.capacity = data.capacity;
            this.today = data.today;
            this.renderMonth();
            this.setStatus('');
        } catch (error) {
            if (seq !== this.monthSeq) return;
            console.error('予約状況（月）を読み込めませんでした', error);
            this.setStatus(FAILED, true);
        }
    }

    async loadDay() {
        const seq = ++this.daySeq;
        const date = this.state.selected;
        this.dayHeadingEl.textContent = '';
        this.renderDayMessage(LOADING);
        try {
            const data = await this.fetchJson(`${this.dayUrl}?${this.params({ date })}`);
            if (seq !== this.daySeq) return;
            this.capacity = data.capacity;
            // 「M/D(曜) の予約 n件　同時に預かる数 m」（2026-11 変更）。件数は一覧の行の数（編集中の利用自身は JSON の時点で除いてある）。
            // 同時に預かる数は予約が 1 件以上の日だけ出し、数字の部分だけを別の要素にして、満・超過はカレンダーと同じバッジにする
            const usages = data.usages || [];
            const heading = dayHeadingParts(date, usages.length, data.count, data.capacity);
            const nodes = [document.createTextNode(heading.label)];
            if (heading.showCount) {
                const countEl = document.createElement('span');
                countEl.className = heading.countClasses.join(' ');
                countEl.dataset.usageScheduleDayCount = '';
                countEl.textContent = heading.count;
                nodes.push(document.createTextNode(heading.countLabel), countEl);
            }
            this.dayHeadingEl.replaceChildren(...nodes);
            this.renderDayList(usages);
            this.renderTimetable(usages, data.open_time, data.close_time);
        } catch (error) {
            if (seq !== this.daySeq) return;
            console.error('予約状況（日）を読み込めませんでした', error);
            this.renderDayMessage(FAILED, true);
        }
    }

    renderMonth() {
        this.headingEl.textContent = monthHeading(this.state.month);
        const rows = monthWeeks(this.state.month).map((week) => {
            const tr = document.createElement('tr');
            week.forEach((ymd) => {
                const td = document.createElement('td');
                td.className = 'p-1';
                if (ymd) {
                    td.appendChild(this.dayButton(ymd));
                }
                tr.appendChild(td);
            });

            return tr;
        });
        this.daysEl.replaceChildren(...rows);
    }

    dayButton(ymd) {
        const count = this.counts[ymd] || 0;
        const usageCount = this.usageCounts[ymd] || 0;
        // 空きあり・満・超過（判定は usage-schedule-core.js）。月の預かる数をまだ読んでいない間は色を付けない
        const loaded = this.countsLoaded && this.capacity !== null;
        const state = loaded ? capacityState(count, this.capacity) : null;
        const past = isPastDay(ymd, this.today);
        const selected = ymd === this.state.selected;
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.date = ymd;
        // 同時に預かる数の数字の文字の色（空きあり・満・超過）・今日の背景・選んでいる日の枠は usage-schedule-core.js の dayCellClasses で決める
        const classes = dayCellClasses({ state, selected, today: ymd === this.today, past });
        button.className = classes.button.join(' ');
        if (selected) button.setAttribute('aria-pressed', 'true');

        const day = document.createElement('span');
        day.className = classes.day.join(' ');
        day.textContent = String(parseInt(ymd.slice(8), 10));
        button.appendChild(day);

        const countEl = document.createElement('span');
        countEl.className = classes.count.join(' ');
        // 下の行（2026-11 変更。出すものは usage-schedule-core.js の cellBottomRow で決める）：
        // 1 件以上の日は「灰色の太字の件数＋バッジ」（件数と同じ日も両方）、0 件の日は「0」だけ（バッジにしない）。
        // 読み込む前は何も出さない。そのときも高さをそろえるため、ノーブレークスペースを入れる
        const row = cellBottomRow({ count, usageCount, capacity: this.capacity, loaded: this.countsLoaded });
        if (row.count === '') {
            countEl.textContent = '\u00a0';
        } else {
            if (row.usageCount !== '') {
                // 予約の件数（1 件以上の日）。読み上げはボタンの aria-label で伝える
                const usageCountEl = document.createElement('span');
                usageCountEl.className = classes.usageCount.join(' ');
                usageCountEl.textContent = row.usageCount;
                usageCountEl.setAttribute('aria-hidden', 'true');
                countEl.appendChild(usageCountEl);
            }
            if (row.badge) {
                // 同時に預かる数のバッジ（空きあり＝灰色・満＝緑・超過＝赤）
                const badge = document.createElement('span');
                badge.className = classes.countBadge.join(' ');
                badge.textContent = row.count;
                countEl.appendChild(badge);
            } else {
                countEl.appendChild(document.createTextNode(row.count));
            }
        }
        button.appendChild(countEl);

        const weekday = WEEKDAYS[new Date(`${ymd}T00:00:00Z`).getUTCDay()];
        const label = [`${parseInt(ymd.slice(5, 7), 10)}月${parseInt(ymd.slice(8), 10)}日(${weekday})`];
        // 読み上げは「予約 n件 同時に預かる数 m」（数字だけだと何の数か分からないため。2026-11 変更：件数を足した）
        const ariaCount = cellCountAriaText({ count, usageCount, capacity: this.capacity, loaded: this.countsLoaded });
        if (ariaCount !== '') label.push(ariaCount);
        if (ymd === this.today) label.push('今日');
        if (past) label.push('過去の日');
        button.setAttribute('aria-label', label.join(' '));

        return button;
    }

    renderDayMessage(text, isError = false) {
        const li = document.createElement('li');
        li.className = `list-group-item small ${isError ? 'text-danger' : 'text-body-secondary'}`;
        li.textContent = text;
        this.dayListEl.replaceChildren(li);
        // 知らせ（読み込み中・失敗・予約はありません）は、どの幅でもリストの 1 行で出す（タイムテーブルは隠す）
        this.hideTimetable();
    }

    hideTimetable() {
        if (!this.timetableEl) return;
        this.timetableEl.classList.remove('d-lg-block');
        this.timetableEl.replaceChildren();
        this.timetableRows = [];
        this.dayListEl.classList.remove('d-lg-none');
    }

    /**
     * 名前の部分（文字とリンク）を要素に入れる。リンクにするか（linkClients）は usage-schedule-core.js で決める
     */
    appendParts(el, parts) {
        parts.forEach((part) => {
            if (part.href === undefined) {
                el.appendChild(document.createTextNode(part.text));
                return;
            }
            const a = document.createElement('a');
            a.href = part.href;
            a.textContent = part.text;
            el.appendChild(a);
        });
    }

    /**
     * その日の予約のタイムテーブル（2026-11 追加。設計書 S-1602「その日の予約のタイムテーブル」）。timetable の設定のときだけ。
     * 位置・幅・時間の幅・灰色の部分は usage-schedule-core.js の timetableModel で決める。帯と目盛りは見た目だけ（aria-hidden）で、
     * 各行の左の列に、見えない文字で時刻と区切り（「8:00〜19:00、」）を入れて、リストと同じ内容を読み上げる
     */
    renderTimetable(usages, openTime, closeTime) {
        if (!this.timetableEl) return;
        const model = timetableModel({ usages, openTime, closeTime, clientUrlTemplate: this.clientUrlTemplate });
        if (model === null) {
            this.hideTimetable();
            return;
        }
        this.timetableEl.style.setProperty('--usage-timetable-hours', String(model.hourCount));

        const outsideEls = () => model.outside.map((segment) => {
            const span = document.createElement('span');
            span.className = 'usage-timetable-outside';
            span.style.left = `${segment.left}%`;
            span.style.width = `${segment.width}%`;
            return span;
        });

        // いちばん上の目盛りの行（時の数字。読み上げない）
        const head = document.createElement('div');
        head.className = 'usage-timetable-row usage-timetable-head';
        head.setAttribute('aria-hidden', 'true');
        const headName = document.createElement('div');
        headName.className = 'usage-timetable-name';
        const scale = document.createElement('div');
        scale.className = 'usage-timetable-scale';
        this.hourEls = timetableHours(model.range).map((hour) => {
            const span = document.createElement('span');
            span.className = 'usage-timetable-hour';
            span.style.left = `${hour.left}%`;
            span.textContent = String(hour.hour);
            scale.appendChild(span);
            return span;
        });
        head.append(headName, scale);

        const list = document.createElement('ul');
        list.className = 'usage-timetable-rows list-unstyled mb-0';
        this.timetableRows = model.rows.map((row) => {
            const li = document.createElement('li');
            li.className = 'usage-timetable-row';

            const name = document.createElement('div');
            name.className = 'usage-timetable-name';
            const hiddenTime = document.createElement('span');
            hiddenTime.className = 'visually-hidden';
            hiddenTime.textContent = `${row.timeRange}、`;
            name.appendChild(hiddenTime);
            this.appendParts(name, row.nameParts);

            const track = document.createElement('div');
            track.className = 'usage-timetable-track';
            track.setAttribute('aria-hidden', 'true');
            const band = document.createElement('span');
            band.className = 'usage-timetable-band';
            band.style.left = `${row.left}%`;
            band.style.width = `${row.width}%`;
            const time = document.createElement('span');
            time.className = 'usage-timetable-time';
            time.textContent = row.timeRange;
            track.append(...outsideEls(), band, time);

            li.append(name, track);
            list.appendChild(li);

            return { row, track, time };
        });

        this.timetableEl.replaceChildren(head, list);
        this.timetableEl.classList.add('d-lg-block');
        this.dayListEl.classList.add('d-lg-none');
        this.layoutTimetable();
    }

    /**
     * 幅に合わせて、時の数字の間引き（1 時間ごと・2 時間ごと）と、帯の時刻の置き場所（帯の中・右・左）を決める。
     * 判定は usage-schedule-core.js の timetableLabelStep・timetableLabelPlacement。出していない（狭い画面）ときは幅が 0 なので何もしない
     */
    layoutTimetable() {
        if (!this.timetableEl || this.timetableRows.length === 0) return;
        const trackWidth = this.timetableRows[0].track.clientWidth;
        if (trackWidth === 0) return;

        const step = timetableLabelStep(trackWidth, this.hourEls.length - 1);
        this.hourEls.forEach((el, index) => {
            el.classList.toggle('usage-timetable-hour--hidden', index % step !== 0);
        });

        this.timetableRows.forEach(({ row, time }) => {
            // 前の置き場所の幅の指定を外してから、文字の幅を測る（帯の中のときは帯の幅にしているため）
            time.classList.remove('usage-timetable-time--inside', 'usage-timetable-time--right', 'usage-timetable-time--left');
            time.style.left = '';
            time.style.right = '';
            time.style.width = '';
            const placement = timetableLabelPlacement({
                bandLeftPx: (row.left / 100) * trackWidth,
                bandWidthPx: (row.width / 100) * trackWidth,
                labelWidthPx: time.getBoundingClientRect().width,
                trackWidthPx: trackWidth,
            });
            time.classList.add(`usage-timetable-time--${placement}`);
            if (placement === 'inside') {
                time.style.left = `${row.left}%`;
                time.style.width = `${row.width}%`;
            } else if (placement === 'right') {
                time.style.left = `calc(${row.left + row.width}% + 4px)`;
            } else {
                time.style.right = `calc(${100 - row.left}% + 4px)`;
            }
        });
    }

    renderDayList(usages) {
        if (usages.length === 0) {
            this.renderDayMessage('予約はありません');
            return;
        }
        this.dayListEl.replaceChildren(...usages.map((usage) => {
            const li = document.createElement('li');
            li.className = 'list-group-item small py-1';
            // 会員名をリンクにするか（linkClients）は usage-schedule-core.js の usageLineParts で決める（2026-11 追加）
            this.appendParts(li, usageLineParts(usage, this.clientUrlTemplate));

            return li;
        }));
    }
}

// 利用の保存の前の確認（2026-11 追加）で使う、DOM に触れない計算。利用モーダルのスクリプト（インライン）から使う
UsageSchedule.usageCheckQuery = usageCheckQuery;
UsageSchedule.usageConfirmMessage = usageConfirmMessage;

window.UsageSchedule = UsageSchedule;
