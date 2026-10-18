<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * ご案内のリンク（マイページ登録・愛犬アンケート・愛犬性格診断）の有効期限の決め方と判定（2026-10-19 追加。
 * architecture.md §3-1）。3 つの案内で同じものを使う（同じことを 3 か所に書かない）。
 *
 * - 期限は、発行した日から N 日後の日の終わり（23:59:59。アプリの時間帯＝日本時間）
 * - 期限切れの判定は秒の単位で比べる（23:59:59 のうちは使え、翌日 0:00:00 から使えない）。
 *   DB の TIMESTAMP は秒までなので、今の時刻も秒の単位にそろえて比べる
 * - 期限が空（NULL）は期限なし（愛犬アンケート・愛犬性格診断で、期限を付ける前に発行した案内）。期限切れにしない
 *
 * 経緯：2026-10-18 にマイページ登録のご案内（ClientEmailRegistrationToken）に作った決め方を、
 * 2026-10-19 に愛犬アンケート・愛犬性格診断の案内にも期限を付けたときに、ここへ移して共通にした。
 * モデルからは trait App\Models\Concerns\ExpiresAtEndOfDay を通して使う
 */
final class InvitationExpiry
{
    /** 今発行したときの有効期限（今日から $days 日後の日の終わり 23:59:59。秒より下は 0） */
    public static function endOfDayAfter(int $days): CarbonInterface
    {
        return Carbon::now()->addDays($days)->endOfDay()->startOfSecond();
    }

    /** 期限を過ぎたか。期限が空なら期限なし（過ぎていない） */
    public static function isPast(?CarbonInterface $expiresAt): bool
    {
        return $expiresAt !== null && $expiresAt->lt(self::now());
    }

    /** 期限内のものだけに絞る（isPast と同じ境目。期限が空のものは期限内に数える） */
    public static function whereUnexpired(Builder $query, string $column): Builder
    {
        return $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '>=', self::now()));
    }

    /** 比べるときの今の時刻（秒の単位） */
    private static function now(): CarbonInterface
    {
        return Carbon::now()->startOfSecond();
    }
}
