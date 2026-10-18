<?php

namespace App\Models\Concerns;

use App\Support\InvitationExpiry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 有効期限（expires_at）を持つご案内のトークンのモデルに付ける（2026-10-19 追加）。
 * 期限の決め方と判定は App\Support\InvitationExpiry（3 つの案内で共通）。日数は config/client_tokens.php の、
 * モデルごとのキー（expiresDaysConfigKey()）から読む
 */
trait ExpiresAtEndOfDay
{
    /** 有効日数を読む config のキー（例：client_tokens.email_registration_expires_days） */
    abstract protected static function expiresDaysConfigKey(): string;

    /** 今発行したときの有効期限（今日から N 日後の日の終わり 23:59:59） */
    public static function expiresAtForNewToken(): CarbonInterface
    {
        return InvitationExpiry::endOfDayAfter((int) config(static::expiresDaysConfigKey()));
    }

    /** 期限を過ぎたか（期限が空なら期限なし） */
    public function isExpired(): bool
    {
        return InvitationExpiry::isPast($this->expires_at);
    }

    /** 期限内のものだけ（期限が空のものを含む） */
    public function scopeUnexpired(Builder $query): Builder
    {
        return InvitationExpiry::whereUnexpired($query, $query->qualifyColumn('expires_at'));
    }
}
