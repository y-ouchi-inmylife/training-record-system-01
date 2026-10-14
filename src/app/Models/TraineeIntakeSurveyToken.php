<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受付アンケート用 URL のトークン（DS-1000。2026-10 追加）。
 *
 * トレーナーがトレーニーごとに発行する「受付アンケート案内」の実体。お客様はこの URL から
 * ログインせずに受付アンケートに回答する（要件定義書 6-15-17・6-16-8）。
 *
 * - 有効期限は持たない。回答の送信で使い切り（answered_at を記録）、放置されたものは
 *   トレーナーが取り消す（物理削除。6-16-9）
 * - 同じトレーニーに有効な（answered_at が NULL の）案内は 1 つまで。発行は
 *   App\Services\TraineeIntakeSurveyTokenService で、トレーニーの行をロックして確かめる
 */
class TraineeIntakeSurveyToken extends Model
{
    protected $fillable = [
        'token',
        'trainee_id',
        'answered_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
        ];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    /**
     * 発行したトレーナー
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'created_by');
    }

    /**
     * 有効か（まだ回答されていないか）。有効期限は持たないため、answered_at だけで決まる
     */
    public function isActive(): bool
    {
        return $this->answered_at === null;
    }
}
