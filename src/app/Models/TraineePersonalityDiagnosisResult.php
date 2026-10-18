<?php

namespace App\Models;

use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 性格診断の結果（D-1200。2026-10 追加）。
 *
 * お客様が性格診断（S-1415）で答えた 50 問の回答と、その採点の結果（7 カテゴリの平均・タイプ）。
 * 1 頭に何件でも持てる（新しい順に並べ、「前回」はその 1 つ前の結果。要件定義書 6-16-13）。
 * トレーナー側では閲覧のみ。お客様の操作で作られるため、created_by / updated_by は持たない。
 *
 * - answers：50 問の回答（キーは Q01〜Q50、値は 0〜4 の整数または未経験を表す "na"）。送られてきたとおりに残す
 * - score_*：7 カテゴリの平均（小数第2位に丸めた値）。列名の * はカテゴリの値（db-schema.md 5-23.）。
 *   全問「未経験」のカテゴリは null（点数なし。2026-10-18 変更）
 * - personality_type：タイプ（5-24.）。丸める前の平均から判定した値。全カテゴリが点数なしなら null（判定なし。2026-10-18 変更）
 * - evaluation_text：評価文（会員様向けの文章。2026-10-18 追加。要件定義書 6-16-15）。トレーナーが愛犬性格診断詳細（S-0313）で書く。
 *   evaluation_updated_by・evaluation_updated_at は最後に評価文を保存したトレーナーと日時（結果そのものの更新者ではないので
 *   updated_by の名前にしない）
 *
 * 評価文の状態（未作成・下書き・公開済み）は列に持たず、評価文と、結び付いたトレーニング記録（trainingRecord）の有無から決める
 * （evaluation_status）。公開済みの記録を削除すると、結び付きもなくなるので下書きに戻る
 */
class TraineePersonalityDiagnosisResult extends Model
{
    /** タイプが判定なし（全カテゴリが点数なし）のときの表示（2026-10-18 追加） */
    public const NO_TYPE_LABEL = '判定なし';

    /** 評価文の状態（2026-10-18 追加。要件定義書 6-16-15）：未作成（評価文が空・結び付いた記録なし） */
    public const EVALUATION_NONE = 'none';

    /** 評価文の状態：下書き（評価文あり・結び付いた記録なし） */
    public const EVALUATION_DRAFT = 'draft';

    /** 評価文の状態：公開済み（結び付いたトレーニング記録がある） */
    public const EVALUATION_PUBLISHED = 'published';

    /** 評価文の上限の文字数（改行を含む。設計書 S-0313） */
    public const EVALUATION_MAX_LENGTH = 10000;

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
        'evaluation_text',
        'evaluation_updated_by',
        'evaluation_updated_at',
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
            'evaluation_updated_at' => 'datetime',
        ];
    }

    /**
     * 7 カテゴリの平均の保存の値（カテゴリの値 → 小数第2位の文字列。例 "1.13"）。並びは PersonalityDiagnosisQuestions::categories()。
     * 点数なし（全問「未経験」）は null
     *
     * @return array<string, string|null>
     */
    public function scores(): array
    {
        $scores = [];
        foreach (array_keys(PersonalityDiagnosisQuestions::categories()) as $category) {
            $score = $this->{'score_'.$category};
            $scores[$category] = $score === null ? null : (string) $score;
        }

        return $scores;
    }

    /**
     * 7 カテゴリの平均の表示の値（カテゴリの値 → 小数第1位の文字列。例 "1.1"）。
     *
     * 保存の値（小数第2位）から四捨五入する（ちょうど半分は切り上げ。設計書 S-0309「性格診断の欄」・要件定義書 6-16）。
     * 問数は多くても 8 なので、丸める前の値から第1位に丸めたとき（採点のクラスの表示の値・GAS の toFixed(1)）と
     * 必ず同じ結果になる。浮動小数点の誤差を避けるため、文字列を百分の一の整数にしてから丸める。点数なしは null のまま
     *
     * @return array<string, string|null>
     */
    public function displayScores(): array
    {
        return array_map(function (?string $score) {
            if ($score === null) {
                return null;
            }
            [$integer, $fraction] = array_pad(explode('.', $score, 2), 2, '0');
            $hundredths = (int) $integer * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
            $tenths = intdiv($hundredths + 5, 10);

            return intdiv($tenths, 10).'.'.($tenths % 10);
        }, $this->scores());
    }

    /**
     * タイプの表示名（db-schema.md 5-24.）。判定なし（全カテゴリが点数なし）は「判定なし」（2026-10-18 追加）
     */
    public function getPersonalityTypeLabelAttribute(): string
    {
        if ($this->personality_type === null) {
            return self::NO_TYPE_LABEL;
        }

        return PersonalityDiagnosisQuestions::types()[$this->personality_type] ?? $this->personality_type;
    }

    /**
     * 評価文の状態の表示名（未作成／下書き／公開済み。2026-10-18 追加）
     *
     * @return array<string, string>
     */
    public static function evaluationStatusLabels(): array
    {
        return [
            self::EVALUATION_NONE => '未作成',
            self::EVALUATION_DRAFT => '下書き',
            self::EVALUATION_PUBLISHED => '公開済み',
        ];
    }

    /**
     * 評価文の状態（2026-10-18 追加。要件定義書 6-16-15）。結び付いたトレーニング記録があれば公開済み（評価文の有無によらない）、
     * なければ評価文の有無で下書き・未作成。trainingRecord は呼ぶ側で先に読み込んでおく（一覧で N+1 にしないため）
     */
    public function getEvaluationStatusAttribute(): string
    {
        if ($this->trainingRecord !== null) {
            return self::EVALUATION_PUBLISHED;
        }

        return blank($this->evaluation_text) ? self::EVALUATION_NONE : self::EVALUATION_DRAFT;
    }

    /**
     * 評価文の状態の表示名（未作成／下書き／公開済み）
     */
    public function getEvaluationStatusLabelAttribute(): string
    {
        return self::evaluationStatusLabels()[$this->evaluation_status];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    /**
     * 評価文を公開して作ったトレーニング記録（D-0200 の trainee_personality_diagnosis_result_id。1 つの結果に 1 件まで。2026-10-18 追加）。
     * なければ null（未作成・下書き）
     */
    public function trainingRecord(): HasOne
    {
        return $this->hasOne(TrainingRecord::class, 'trainee_personality_diagnosis_result_id');
    }

    /**
     * 最後に評価文を保存したトレーナー（2026-10-18 追加）。トレーナーが削除されていれば null
     */
    public function evaluationUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'evaluation_updated_by');
    }

    /**
     * 回答に使った性格診断用 URL のトークン（トークンが削除されていれば null）
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(TraineePersonalityDiagnosisToken::class, 'trainee_personality_diagnosis_token_id');
    }
}
