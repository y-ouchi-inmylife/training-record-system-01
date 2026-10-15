<?php

namespace App\Models;

use App\Support\PersonalityDiagnosisQuestions;
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

    /**
     * 7 カテゴリの平均の保存の値（カテゴリの値 → 小数第2位の文字列。例 "1.13"）。並びは PersonalityDiagnosisQuestions::categories()
     *
     * @return array<string, string>
     */
    public function scores(): array
    {
        $scores = [];
        foreach (array_keys(PersonalityDiagnosisQuestions::categories()) as $category) {
            $scores[$category] = (string) $this->{'score_'.$category};
        }

        return $scores;
    }

    /**
     * 7 カテゴリの平均の表示の値（カテゴリの値 → 小数第1位の文字列。例 "1.1"）。
     *
     * 保存の値（小数第2位）から四捨五入する（ちょうど半分は切り上げ。設計書 S-0309「性格診断の欄」・要件定義書 6-16）。
     * 問数は多くても 8 なので、丸める前の値から第1位に丸めたとき（採点のクラスの表示の値・GAS の toFixed(1)）と
     * 必ず同じ結果になる。浮動小数点の誤差を避けるため、文字列を百分の一の整数にしてから丸める
     *
     * @return array<string, string>
     */
    public function displayScores(): array
    {
        return array_map(function (string $score) {
            [$integer, $fraction] = array_pad(explode('.', $score, 2), 2, '0');
            $hundredths = (int) $integer * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
            $tenths = intdiv($hundredths + 5, 10);

            return intdiv($tenths, 10).'.'.($tenths % 10);
        }, $this->scores());
    }

    /**
     * タイプの表示名（db-schema.md 5-24.）
     */
    public function getPersonalityTypeLabelAttribute(): string
    {
        return PersonalityDiagnosisQuestions::types()[$this->personality_type] ?? $this->personality_type;
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
