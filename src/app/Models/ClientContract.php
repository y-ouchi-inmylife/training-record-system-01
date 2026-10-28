<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 契約モデル（D-0900）
 *
 * 会員と回数で結んだ契約。利用（ClientUsage）は client_contract_id で
 * どの契約の分かを持つ。利用 1 件は 1 回分で、契約ごとの残り ＝ 回数 − 割り当てた利用の件数。
 * 回数券型の表示は、この割り当てのとおりに ContractTicketService で組み立てる。
 * 割り当て済みの利用の件数より少ない回数への編集は ClientContractRequest でエラーにする。
 * 有効期限（expires_on）は表示するだけで、計算には使わない。
 * 削除は物理削除、権限は一般トレーナーも可（設計書 requirements.md 6-17-3）。
 */
class ClientContract extends Model
{
    protected $fillable = [
        'client_id',
        'contracted_on',
        'count',
        'expires_on',
        'note',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'contracted_on' => 'date',
            'expires_on' => 'date',
            'count' => 'integer',
        ];
    }

    /**
     * 会員
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * この契約に割り当てた利用（D-1000）
     */
    public function usages(): HasMany
    {
        return $this->hasMany(ClientUsage::class, 'client_contract_id');
    }

    /**
     * 有効期限を過ぎていて残りがあるか（有効期限の日付を赤字にする条件。設計書 screen-design.md
     * S-0305 セクション5「契約の見出し」・S-0315「有効期限の表示」）。
     *
     * - 有効期限が設定されている
     * - 有効期限の日付が今日より前（当日はまだ有効なので偽）
     * - その契約に残りがある（使い切った契約は、期限を過ぎていても偽）。使い切りかは呼び出し側が $usedUp で渡す
     *
     * 表示のためだけの判定で、残り回数の計算・利用の割り当て・選択肢の選べるかどうかには使わない。
     * 日付どうしを Y-m-d の文字列で比べ、時刻やタイムゾーンの差を持ち込まない。
     * 会員詳細（ContractTicketService）と契約一覧（expired_with_remaining）の両方がここを使う（2026-10-28 に
     * ContractTicketService の private メソッドから移した。判定を 2 つ持たないため）。
     *
     * @param  string|null  $today  今日（Y-m-d）。省略時はアプリの時間帯の今日（today()。Carbon::setTestNow() で固定できる）
     */
    public function isExpiredWithRemaining(bool $usedUp, ?string $today = null): bool
    {
        if ($this->expires_on === null || $usedUp) {
            return false;
        }

        return $this->expires_on->format('Y-m-d') < ($today ?? today()->format('Y-m-d'));
    }

    /**
     * 契約一覧（S-0315）の有効期限を赤字にするか（2026-10-28 追加）。
     * 使い切りかは、割り当てた利用の件数（withCount('usages') の usages_count。なければ数える）が回数以上か
     * （ContractTicketService の「残り ＝ 回数 − 割り当て件数、0 なら使い切り」と同じ）
     */
    public function getExpiredWithRemainingAttribute(): bool
    {
        $assigned = $this->usages_count ?? $this->usages()->count();

        return $this->isExpiredWithRemaining((int) $assigned >= (int) $this->count);
    }

    /**
     * 最終更新者（トレーナー）
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'updated_by');
    }
}
