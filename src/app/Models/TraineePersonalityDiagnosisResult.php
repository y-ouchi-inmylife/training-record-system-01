<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 性格診断の結果（D-1200。2026-10 追加）。
 *
 * お客様が性格診断（S-1415）で答えた 50 問の回答と、その採点の結果（7 カテゴリの平均・タイプ）。
 * 1 頭に何件でも持てる（新しい順に並べ、「前回」はその 1 つ前の結果。要件定義書 6-16-13）。
 * トレーナー側では閲覧のみ。お客様の操作で作られるため、created_by / updated_by は持たない。
 *
 * - answers：50 問の回答（キーは Q01〜Q50、値は 0〜4 の整数または未経験を表す "na"）。送られてきたとおりに残す
 * - score_*：7 カテゴリの平均（小数第2位に丸めた値）。列名の * はカテゴリの値（db-schema.md 5-23.）
 * - personality_type：タイプ（5-24.）。丸める前の平均から判定した値
 */
class TraineePersonalityDiagnosisResult extends Model
{
    protected $fillable = [
        'trainee_id',
        'trainee_personality_diagnosis_token_id',
        'answered_at',
        'question_version',
        'answers',
        'score_people',
        'score_dogs',
        'score_possessiveness',
        'score_separation',
        'score_fear',
        'score_trainability',
        'score_excitability',
        'personality_type',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'answers' => 'array',
            'score_people' => 'decimal:2',
            'score_dogs' => 'decimal:2',
            'score_possessiveness' => 'decimal:2',
            'score_separation' => 'decimal:2',
            'score_fear' => 'decimal:2',
            'score_trainability' => 'decimal:2',
            'score_excitability' => 'decimal:2',
        ];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    /**
     * 回答に使った性格診断用 URL のトークン（トークンが削除されていれば null）
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(TraineePersonalityDiagnosisToken::class, 'trainee_personality_diagnosis_token_id');
    }
}
