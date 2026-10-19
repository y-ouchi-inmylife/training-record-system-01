<?php

namespace App\Models;

use App\Models\Concerns\ExpiresAtEndOfDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 性格診断用 URL のトークン（DS-1100。2026-10 追加）。
 *
 * トレーナーがトレーニーごとに発行する「性格診断の案内」の実体。お客様はこの URL から
 * ログインせずに性格診断に回答する（要件定義書 6-15-18・6-16-11）。愛犬アンケート用 URL のトークン
 * （TraineeIntakeSurveyToken）と同じ作り。
 *
 * - 有効期限は発行した日から 7 日後の日の終わり（expires_at。2026-10-19 変更：ユーザーレビューで、期限がないと
 *   お客様が答えるのを忘れてしまうので付けた。その前は有効期限を持たなかった）。期限を付ける前に発行した案内は
 *   expires_at が NULL で、期限なし（答えるまで使える）。期限の決め方・判定は trait ExpiresAtEndOfDay（3 つの案内で共通）
 * - 回答の送信で使い切り（answered_at を記録）、放置されたものはトレーナーが取り消すか、期限が切れる
 *   （取消は物理削除。6-16-12。期限が切れた行は消さずに残す）
 * - 同じトレーニーに有効な（answered_at が NULL で期限内の）案内は 1 つまで。愛犬アンケートと違い、
 *   前の結果があっても次の案内を発行できる（何回でも受けられる）
 */
class TraineePersonalityDiagnosisToken extends Model
{
    use ExpiresAtEndOfDay;

    protected $fillable = [
        'token',
        'trainee_id',
        'expires_at',
        'answered_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    /** 有効日数のキー（2026-10-19 追加） */
    protected static function expiresDaysConfigKey(): string
    {
        return 'client_tokens.personality_diagnosis_expires_days';
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    /**
     * この案内と結び付いた愛犬アンケートの案内（2026-10-19 追加。「愛犬アンケート＆愛犬性格診断」の案内のとき。単独の案内なら null）。
     * 回答の画面の進み具合（2/2）を出すかどうかに使う
     */
    public function combinedIntakeSurveyToken(): HasOne
    {
        return $this->hasOne(TraineeIntakeSurveyToken::class, 'trainee_personality_diagnosis_token_id');
    }

    /**
     * 発行したトレーナー
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'created_by');
    }

    /**
     * 有効か（まだ回答されておらず、期限内か。2026-10-19 変更：期限を付けたため、期限切れなら有効でない。
     * その前は answered_at だけで決まっていた。期限が空なら期限なし）
     */
    public function isActive(): bool
    {
        return $this->answered_at === null && ! $this->isExpired();
    }

    /** 有効な（未回答で期限内の）案内だけに絞る（2026-10-19 追加） */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('answered_at'))->unexpired();
    }
}
