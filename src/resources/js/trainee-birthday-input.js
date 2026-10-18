/**
 * トレーニーの誕生日（年・月・日の 3 つの欄）の入力中の表示（トレーナー側の S-0308／S-0310、会員側の愛犬アンケート S-1413）。
 *
 * - 欄と表示先は data-* 属性で見つける（画面に固有のクラスや ID に依存しない）：
 *     <div data-birthday-input>
 *       <input data-birthday-part="year"> <input data-birthday-part="month"> <input data-birthday-part="day">
 *       <span data-birthday-display aria-live="polite"></span>
 *     </div>
 * - 入力のたびに判定し、正しければ年齢（全角のカッコで囲む。カッコは trainee-age.js の birthdayInputDisplay で付ける）、
 *   空なら何も出さず、それ以外は入力中の案内（カッコなし）を出す
 *   （年齢は本文と同じ色、入力中の案内は控えめな色〔text-muted〕。送信時のエラーの赤字とは別に出す）
 * - 欄から離れたとき（blur）に、欄の値の全角の数字を半角に直し、前後の空白を取る
 *   （送信される値と画面の値をそろえるため。送信時も同じく直して受け付ける）
 * - ページを開いたとき（編集・エラーで戻ったとき）にも、入っている値で一度表示する
 *
 * 判定と計算は trainee-age.js（PHP と同じ手順）。基準日は日本時間の今日。
 */
import { STATE, birthdayInputDisplay, normalize, todayInTokyo } from './trainee-age.js';

function setUp(container) {
    const parts = {};
    for (const part of ['year', 'month', 'day']) {
        parts[part] = container.querySelector(`[data-birthday-part="${part}"]`);
    }
    const display = container.querySelector('[data-birthday-display]');
    if (!parts.year || !parts.month || !parts.day || !display) {
        return;
    }

    const update = () => {
        const today = todayInTokyo();
        const { state, text } = birthdayInputDisplay(
            parts.year.value, parts.month.value, parts.day.value,
            today.year, today.month, today.day,
        );
        display.textContent = text;
        // 年齢は本文と同じ色、入力中の案内は控えめな色
        display.classList.toggle('text-muted', state !== STATE.OK && state !== STATE.EMPTY);
    };

    for (const input of Object.values(parts)) {
        input.addEventListener('input', update);
        input.addEventListener('blur', () => {
            const normalized = normalize(input.value);
            const value = normalized === null ? '' : String(normalized);
            if (input.value !== value) {
                input.value = value;
                update();
            }
        });
    }

    update();
}

function init() {
    document.querySelectorAll('[data-birthday-input]').forEach(setUp);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
