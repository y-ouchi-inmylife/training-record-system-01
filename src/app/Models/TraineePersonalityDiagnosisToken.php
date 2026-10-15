<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 性格診断用 URL のトークン（DS-1100。2026-10 追加）。
 *
 * トレーナーがトレーニーごとに発行する「性格診断の案内」の実体。お客様はこの URL から
 * ログインせずに性格診断に回答する（要件定義書 6-15-18・6-16-11）。受付アンケート用 URL のトークン
 * （TraineeIntakeSurveyToken）と同じ作り。
 *
 * - 有効期限は持たない。回答の送信で使い切り（answered_at を記録）、放置されたものは
 *   トレーナーが取り消す（物理削除。6-16-12）
 * - 同じトレーニーに有効な（answered_at が NULL の）案内は 1 つまで。受付アンケートと違い、
 *   前の結果があっても次の案内を発行できる（何回でも受けられる）
 */
class TraineePersonalityDiagnosisToken extends Model
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
