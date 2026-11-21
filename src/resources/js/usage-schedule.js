/**
 * 利用の予約状況の部品（カレンダーとその日の予約。2026-11 追加。設計書 screen-design.md S-0305-M05「予約状況」、api-design.md 4-2-6）。
 *
 * マークアップは匿名コンポーネント <x-usage-schedule>（resources/views/components/usage-schedule.blade.php）。
 * 利用モーダル（S-0305-M05）から切り離して作り、後でカレンダー画面（S-1602。まだ作らない）でも使い回す。
 * 部品は利用日の欄を直接は触らない。日付を押したことを onSelect で知らせ、使う側（利用モーダルのスクリプト）が欄に入れる。
 *
 * 使い方：
 *   const schedule = new window.UsageSchedule(rootEl, { onSelect: (ymd) => { ... } });
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
    capacityCheckQuery,
    capacityConfirmMessage,
    capacityState,
    countText,
    dayCellClasses,
    dayHeadingParts,
    initialState,
    monthHeading,
    monthWeeks,
    moveMonth,
    selectDate,
    usageLine,
} from './usage-schedule-core.js';

const LOADING = '読み込み中…';
const FAILED = '予約状況を読み込めませんでした。';

class UsageSchedule {
    constructor(root, { onSelect = () => {} } = {}) {
        this.root = root;
        this.onSelect = onSelect;
        this.monthUrl = root.dataset.monthUrl;
        this.dayUrl = root.dataset.dayUrl;
        this.state = null;
        this.excludeUsageId = null;
        this.today = null;          // サーバーの今日（月の JSON の today）
        this.capacity = null;       // 同時に預かれる数（JSON の capacity）
        this.counts = {};           // 出している月の日ごとの預かる数
        this.monthSeq = 0;
        this.daySeq = 0;

        this.headingEl = root.querySelector('[data-usage-schedule-heading]');
        this.daysEl = root.querySelector('[data-usage-schedule-days]');
        this.statusEl = root.querySelector('[data-usage-schedule-status]');
        this.dayHeadingEl = root.querySelector('[data-usage-schedule-day-heading]');
        this.dayListEl = root.querySelector('[data-usage-schedule-day-list]');

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
        this.state = next;
        if (monthChanged) {
            this.counts = {};
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
            // 「n/上限」の部分だけを別の要素にし、カレンダーと同じ色のクラスを付ける（2026-11 変更）
            const heading = dayHeadingParts(date, data.count, data.capacity);
            const countEl = document.createElement('span');
            countEl.className = heading.countClasses.join(' ');
            countEl.dataset.usageScheduleDayCount = '';
            countEl.textContent = heading.count;
            this.dayHeadingEl.replaceChildren(document.createTextNode(heading.label), countEl);
            this.renderDayList(data.usages || []);
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
        // 空きあり・満・超過（判定は usage-schedule-core.js）。上限をまだ読んでいない間は色を付けない
        const state = this.capacity === null ? null : capacityState(count, this.capacity);
        const selected = ymd === this.state.selected;
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.date = ymd;
        // 「n/上限」の文字の色（空きあり・満・超過）・今日の背景・選んでいる日の枠は usage-schedule-core.js の dayCellClasses で決める
        const classes = dayCellClasses({ state, selected, today: ymd === this.today });
        button.className = classes.button.join(' ');
        if (selected) button.setAttribute('aria-pressed', 'true');

        const day = document.createElement('span');
        day.className = classes.day.join(' ');
        day.textContent = String(parseInt(ymd.slice(8), 10));
        button.appendChild(day);

        const countEl = document.createElement('span');
        countEl.className = classes.count.join(' ');
        // 預かる数がない日も高さをそろえるため、ノーブレークスペースを入れる
        const text = this.capacity === null ? '' : countText(count, this.capacity);
        countEl.textContent = text === '' ? '\u00a0' : text;
        button.appendChild(countEl);

        const weekday = WEEKDAYS[new Date(`${ymd}T00:00:00Z`).getUTCDay()];
        const label = [`${parseInt(ymd.slice(5, 7), 10)}月${parseInt(ymd.slice(8), 10)}日(${weekday})`];
        if (count > 0 && this.capacity !== null) label.push(`${count}/${this.capacity}`);
        if (ymd === this.today) label.push('今日');
        button.setAttribute('aria-label', label.join(' '));

        return button;
    }

    renderDayMessage(text, isError = false) {
        const li = document.createElement('li');
        li.className = `list-group-item small ${isError ? 'text-danger' : 'text-body-secondary'}`;
        li.textContent = text;
        this.dayListEl.replaceChildren(li);
    }

    renderDayList(usages) {
        if (usages.length === 0) {
            this.renderDayMessage('予約はありません');
            return;
        }
        this.dayListEl.replaceChildren(...usages.map((usage) => {
            const li = document.createElement('li');
            li.className = 'list-group-item small py-1';
            li.textContent = usageLine(usage);

            return li;
        }));
    }
}

// 利用の保存の前の確認（2026-11 追加）で使う、DOM に触れない計算。利用モーダルのスクリプト（インライン）から使う
UsageSchedule.capacityCheckQuery = capacityCheckQuery;
UsageSchedule.capacityConfirmMessage = capacityConfirmMessage;

window.UsageSchedule = UsageSchedule;
