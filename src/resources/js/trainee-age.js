/**
 * トレーニーの誕生日（年・月・日の 3 つの欄）の判定と、年齢の表示の計算（DOM に触れない）。
 *
 * PHP の App\Support\TraineeBirthdayInput（判定）・App\Support\TraineeAge（年齢）と**同じ手順**で書く。
 * 入力中の表示（trainee-birthday-input.js）と送信時の検証・保存後の表示が食い違わないようにするため。
 * 両方のテストは同じ固定データで確かめる：
 *   - tests/fixtures/trainee-age-cases.json（年齢の表示）
 *   - tests/fixtures/trainee-birthday-input-cases.json（判定の状態）
 *
 * 規則は要件定義書 6-16「年齢の表示規則」、判定の順・状態の記号・入力中の案内は
 * screen-design.md §2-8「誕生日（年・月・日の 3 つの欄）の文言」の表。
 */

// 状態の記号（判定の順。PHP の TraineeBirthdayInput の定数と同じ値）
export const STATE = Object.freeze({
    NOT_NUMERIC: 'not_numeric',
    MONTH_OUT_OF_RANGE: 'month_out_of_range',
    MONTH_WITHOUT_YEAR: 'month_without_year',
    DAY_WITHOUT_YEAR: 'day_without_year',
    DAY_WITHOUT_MONTH: 'day_without_month',
    INVALID_DATE: 'invalid_date',
    FUTURE: 'future',
    TOO_OLD: 'too_old',
    OK: 'ok',
    EMPTY: 'empty',
});

/** 年の下限（今年から何年前まで受け付けるか） */
export const MAX_YEARS_AGO = 30;

// 状態の記号 → 入力中の案内（要件定義書 6-16「入力中の表示」の表。送信時の文言より短い）。
// ok は年齢、empty は何も出さないため、ここには持たない。
export const INPUT_HINTS = Object.freeze({
    [STATE.NOT_NUMERIC]: '数字で入力してください',
    [STATE.MONTH_OUT_OF_RANGE]: '日付を確認してください',
    [STATE.MONTH_WITHOUT_YEAR]: '年も入力してください',
    [STATE.DAY_WITHOUT_YEAR]: '年も入力してください',
    [STATE.DAY_WITHOUT_MONTH]: '月も入力してください',
    [STATE.INVALID_DATE]: '日付を確認してください',
    [STATE.FUTURE]: '未来の日付になっています',
    [STATE.TOO_OLD]: '年を確認してください',
});

// 前後の空白。PHP の正規表現 [\s　]（\s は ASCII の空白）と同じ文字に限る
const EDGE_SPACES = /^[ \t\n\v\f\r　]+|[ \t\n\v\f\r　]+$/g;

/**
 * 欄の値を直す：全角の数字を半角に、前後の空白（全角を含む）を取る。空になったら null。
 * PHP の TraineeBirthdayInput::normalize()（mb_convert_kana($value, 'n') と前後の空白の除去）と同じ。
 */
export function normalize(value) {
    if (value === null || value === undefined) {
        return null;
    }
    if (typeof value !== 'string') {
        return value;
    }
    const converted = value
        .replace(/[０-９]/g, (ch) => String.fromCharCode(ch.charCodeAt(0) - 0xfee0))
        .replace(EDGE_SPACES, '');

    return converted === '' ? null : converted;
}

/** その月の日数（うるう年の 2 月は 29）。PHP の TraineeAge::daysInMonth() と同じ */
export function daysInMonth(year, month) {
    if (month === 2) {
        const leap = (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
        return leap ? 29 : 28;
    }
    return [4, 6, 9, 11].includes(month) ? 30 : 31;
}

/**
 * 3 つの欄の値（文字列）から状態の記号を返す。PHP の TraineeBirthdayInput::state() と同じ手順。
 * 基準日は年・月・日の整数で受け取る。
 */
export function birthdayInputState(year, month, day, refYear, refMonth, refDay) {
    const values = [normalize(year), normalize(month), normalize(day)];

    // 1. 数字であること（半角の数字だけ）
    for (const value of values) {
        if (value === null) {
            continue;
        }
        if (typeof value !== 'string' && typeof value !== 'number') {
            return STATE.NOT_NUMERIC;
        }
        if (!/^[0-9]+$/.test(String(value))) {
            return STATE.NOT_NUMERIC;
        }
    }

    const [y, m, d] = values.map((value) => (value === null ? null : parseInt(value, 10)));

    if (y === null && m === null && d === null) {
        return STATE.EMPTY;
    }

    // 2. 月は 1〜12
    if (m !== null && (m < 1 || m > 12)) {
        return STATE.MONTH_OUT_OF_RANGE;
    }

    // 3. 月があるなら年がある
    if (m !== null && y === null) {
        return STATE.MONTH_WITHOUT_YEAR;
    }

    // 4. 日があるなら年・月がある（入力中の案内を分けるため、年がない／月がないの 2 つにする）
    if (d !== null && y === null) {
        return STATE.DAY_WITHOUT_YEAR;
    }
    if (d !== null && m === null) {
        return STATE.DAY_WITHOUT_MONTH;
    }

    // 5. 3 つそろったら実在する日付（PHP の checkdate と同じ判定。checkdate は年が 1〜32767 の外も認めない）
    if (d !== null && (y < 1 || y > 32767 || d < 1 || d > daysInMonth(y, m))) {
        return STATE.INVALID_DATE;
    }

    // 6. 未来でない（年だけなら今年以前、年・月までなら今月以前、年・月・日なら今日以前）
    let future;
    if (m === null) {
        future = y > refYear;
    } else if (d === null) {
        future = y > refYear || (y === refYear && m > refMonth);
    } else {
        future = y > refYear
            || (y === refYear && m > refMonth)
            || (y === refYear && m === refMonth && d > refDay);
    }
    if (future) {
        return STATE.FUTURE;
    }

    // 7. 年は今年から 30 年前まで
    if (y < refYear - MAX_YEARS_AGO) {
        return STATE.TOO_OLD;
    }

    return STATE.OK;
}

/**
 * 誕生日から基準日までの満の月数。年・月までなら、その月の 1 日生まれとみなす。
 * 年だけ、または未来の誕生日なら null。PHP の TraineeAge::months() と同じ手順。
 * 生まれた日がその月にない場合は、その月の末日を生まれた日とみなす。
 */
export function ageMonths(year, month, day, refYear, refMonth, refDay) {
    if (month === null) {
        return null;
    }

    let months = (refYear - year) * 12 + (refMonth - month);

    // 基準日の月での「生まれた日」。年・月までなら 1 日。その月に生まれた日がなければ末日
    const birthDayInRefMonth = Math.min(day ?? 1, daysInMonth(refYear, refMonth));
    if (refDay < birthDayInRefMonth) {
        months -= 1;
    }

    return months < 0 ? null : months;
}

/**
 * 年齢の表示（「6歳7ヶ月」「約6歳」「10ヶ月」「1ヶ月未満」「1歳未満」）。
 * 誕生日が未登録、または未来の誕生日なら null。PHP の TraineeAge::ageLabel() と同じ。
 */
export function ageLabel(year, month, day, refYear, refMonth, refDay) {
    if (year === null) {
        return null;
    }

    // 年だけ：基準日の年から生まれ年を引いた数に「約」を付ける。0 なら「1歳未満」
    if (month === null) {
        const years = refYear - year;
        if (years < 0) {
            return null;
        }
        return years === 0 ? '1歳未満' : `約${years}歳`;
    }

    const months = ageMonths(year, month, day, refYear, refMonth, refDay);
    if (months === null) {
        return null;
    }
    if (months === 0) {
        return '1ヶ月未満';
    }
    if (months < 12) {
        return `${months}ヶ月`;
    }

    return `${Math.floor(months / 12)}歳${months % 12}ヶ月`;
}

/**
 * 入力中の表示を組み立てる。3 つの欄の値（文字列）と基準日を受け取り、
 * { state, text } を返す（text は ok なら全角のカッコで囲んだ年齢、empty なら ''、それ以外は入力中の案内）。
 *
 * 年齢は保存後の表示（「2020/03/15（6歳7ヶ月）」）にそろえて全角のカッコで囲む（2026-10 変更。
 * 要件定義書 6-16「入力中の表示」）。入力中の案内はメッセージなので囲まない。
 * カッコはこの表示の層だけで付け、年齢の計算（ageLabel。PHP の TraineeAge::ageLabel と同じ結果を返し、
 * 固定データ tests/fixtures/trainee-age-cases.json で確かめる）はカッコなしのままにする。
 */
export function birthdayInputDisplay(year, month, day, refYear, refMonth, refDay) {
    const state = birthdayInputState(year, month, day, refYear, refMonth, refDay);

    if (state === STATE.EMPTY) {
        return { state, text: '' };
    }
    if (state !== STATE.OK) {
        return { state, text: INPUT_HINTS[state] };
    }

    const [y, m, d] = [normalize(year), normalize(month), normalize(day)]
        .map((value) => (value === null ? null : parseInt(value, 10)));

    const age = ageLabel(y, m, d, refYear, refMonth, refDay);

    return { state, text: age === null ? '' : `（${age}）` };
}

/**
 * 基準日（日本時間の今日）を { year, month, day } で返す。PHP 側（Asia/Tokyo の today()）と
 * そろえるため、ブラウザの時間帯にかかわらず Asia/Tokyo の日付を使う。
 */
export function todayInTokyo(now = new Date()) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Tokyo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);
    const get = (type) => parseInt(parts.find((part) => part.type === type).value, 10);

    return { year: get('year'), month: get('month'), day: get('day') };
}
