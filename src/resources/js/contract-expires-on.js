/**
 * 契約の登録のモーダル（S-0305-M04）の有効期限の初期値（2026-10-18 追加。設計書 S-0305-M04「有効期限の初期値」）。
 *
 * - 有効期限の初期値は、契約日の月の最終日（月末の日数〔28〜31 日〕・うるう年を正しく扱う）
 * - 契約日を変えたとき、有効期限をまだ手で触っていなければ、変えた契約日の月の最終日に合わせる。
 *   手で直した（または空にした）あとは変えない
 *
 * ここは DOM に触れない純粋な関数だけを置く（vitest で確かめる）。モーダルの欄とのつなぎ込みは
 * clients/_contract-modal.blade.php のスクリプトで行い、window.ContractExpiresOn から使う
 * （window.UnsavedChangesGuard と同じ渡し方）。
 */

const pad2 = (n) => String(n).padStart(2, '0');

/**
 * 日付（YYYY-MM-DD）の月の最終日を YYYY-MM-DD で返す。形が違う・存在しない日付なら null
 * （入力の途中〔「2026-10-1」など〕では変えないため）。
 *
 * @param {string|null|undefined} ymd
 * @returns {string|null}
 */
export function endOfMonth(ymd) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(ymd ?? '').trim());
    if (!m) {
        return null;
    }
    const year = parseInt(m[1], 10);
    const month = parseInt(m[2], 10);
    const day = parseInt(m[3], 10);
    if (month < 1 || month > 12) {
        return null;
    }
    // 翌月の 0 日＝その月の最終日（UTC で数えて、時間帯の影響を受けないようにする）
    const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
    if (day < 1 || day > lastDay) {
        return null;
    }

    return `${m[1]}-${pad2(month)}-${pad2(lastDay)}`;
}

/**
 * 契約日を変えたあとの有効期限の値を返す。
 *
 * - 有効期限を手で触っていれば（touched）、今の値のまま
 * - 触っていなければ、契約日の月の最終日。契約日が入力の途中・正しくないときは今の値のまま
 *
 * @param {string} contractedOn 変えたあとの契約日
 * @param {string} currentExpiresOn 今の有効期限
 * @param {boolean} touched 有効期限を手で触ったか
 * @returns {string}
 */
export function followExpiresOn(contractedOn, currentExpiresOn, touched) {
    if (touched) {
        return currentExpiresOn;
    }

    return endOfMonth(contractedOn) ?? currentExpiresOn;
}

/**
 * エラーで戻ったときなど、欄に値が入った状態から始めるときに「触っていない」とみなすか。
 * 有効期限が契約日の月の最終日と同じなら、初期値のまま（触っていない）とみなす。空・違う日なら触ったとみなす
 *
 * @param {string} contractedOn
 * @param {string} expiresOn
 * @returns {boolean}
 */
export function isFollowingContractedOn(contractedOn, expiresOn) {
    const expected = endOfMonth(contractedOn);

    return expected !== null && expected === String(expiresOn ?? '').trim();
}

if (typeof window !== 'undefined') {
    window.ContractExpiresOn = { endOfMonth, followExpiresOn, isFollowingContractedOn };
}
