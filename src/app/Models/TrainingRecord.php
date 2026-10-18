<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * トレーニング記録モデル
 */
class TrainingRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'training_date', 'training_time',
        'trainer1_id', 'trainer2_id',
        'record_content', 'impression',
        // 最終更新者
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'training_date' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function trainer1(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'trainer1_id');
    }

    public function trainer2(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'trainer2_id');
    }

    /**
     * 最終更新者（トレーナー）
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'updated_by');
    }

    /**
     * この記録に紐づくメディア（多対多）
     *
     * 中間テーブル: media_record_training_record（D-0600）
     * 並び順は中間テーブルの sort_order 昇順で固定（記録ごとの表示順）。
     */
    /**
     * 結び付けた愛犬性格診断の結果（D-0200 の trainee_personality_diagnosis_result_id。2026-10-18 追加。要件定義書 6-16-16）。
     * 評価文を公開して作った記録だけにある。結果が削除されると null（記録は残る）。
     * 列は fillable に入れない（記録の登録・編集の画面からは変えない。公開のときだけ入れる）
     */
    public function personalityDiagnosisResult(): BelongsTo
    {
        return $this->belongsTo(TraineePersonalityDiagnosisResult::class, 'trainee_personality_diagnosis_result_id');
    }

    /**
     * 記録の詳細（S-0403・S-1404）の「愛犬性格診断」の欄に渡す値（2026-10-18 追加。要件定義書 6-16-16）。
     * 結び付いた結果がなければ（結び付けていない記録・結果が削除された記録）null。
     *
     * - result：結び付いた結果（タイプ・回答日）。trainee：結果のトレーニー（見出しの名前）
     * - radar：レーダーチャートの値。**その結果の時点**（その結果を今回、それより前の結果から前回・初回。
     *   Trainee::personalityRadarDataAsOf）なので、あとで新しい結果が増えても変わらない
     *
     * 結果・トレーニー・トレーニーの結果を読み込む（結び付いていなければクエリは走らない）
     *
     * @return array{result: TraineePersonalityDiagnosisResult, trainee: Trainee, radar: array|null}|null
     */
    public function personalityDiagnosisSection(): ?array
    {
        if ($this->trainee_personality_diagnosis_result_id === null) {
            return null;
        }
        $this->loadMissing('personalityDiagnosisResult.trainee.personalityDiagnosisResults');
        $result = $this->personalityDiagnosisResult;
        if ($result === null) {
            return null;
        }

        return [
            'result' => $result,
            'trainee' => $result->trainee,
            'radar' => $result->trainee->personalityRadarDataAsOf($result),
        ];
    }

    public function mediaRecords(): BelongsToMany
    {
        return $this->belongsToMany(MediaRecord::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
