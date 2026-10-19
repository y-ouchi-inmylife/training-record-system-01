<?php

namespace App\Models;

use App\Support\CombinedSurveyName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * トレーナー操作履歴モデル
 */
class AccessLog extends Model
{
    protected $fillable = [
        'trainer_id',
        'action',
        'target_type',
        'target_id',
        'ip_address',
        'user_agent',
    ];

    /**
     * 操作名 → 日本語ラベルのマッピング
     *
     * アクセサ（getActionLabelAttribute）と Blade の操作フィルターの
     * 両方からこの定義を参照することで、ラベル定義を1箇所に集約する。
     */
    public static function actionLabels(): array
    {
        return [
            'login' => 'ログイン',
            'logout' => 'ログアウト',
            'view_client' => '会員詳細',
            'edit_client' => '会員編集',
            'create_client' => '会員登録',
            'delete_client' => '会員削除',
            'view_training_record' => 'トレーニング記録詳細',
            'edit_training_record' => 'トレーニング記録編集',
            'create_training_record' => 'トレーニング記録登録',
            'delete_training_record' => 'トレーニング記録削除',
            'create_trainee' => 'トレーニー登録',
            'edit_trainee' => 'トレーニー編集',
            'delete_trainee' => 'トレーニー削除',
            'create_trainee_measurement' => '計測値登録',
            'edit_trainee_measurement' => '計測値編集',
            'delete_trainee_measurement' => '計測値削除',
            'create_trainee_intake_survey_token' => __('messages.intake_survey_name').'案内発行',
            'delete_trainee_intake_survey_token' => __('messages.intake_survey_name').'案内取消',
            // 愛犬性格診断の名前は lang/ja/messages.php の 1 か所から読む（2026-10-18 変更：その前は「性格診断…」）
            'create_trainee_personality_diagnosis_token' => __('messages.personality_diagnosis_name').'案内発行',
            'delete_trainee_personality_diagnosis_token' => __('messages.personality_diagnosis_name').'案内取消',
            // 愛犬アンケート＆愛犬性格診断の案内（2026-10-19 追加）。名前は 2 つの名前から組み立てる
            'create_trainee_combined_survey_token' => CombinedSurveyName::name().'案内発行',
            'delete_trainee_combined_survey_token' => CombinedSurveyName::name().'案内取消',
            'delete_trainee_personality_diagnosis_result' => __('messages.personality_diagnosis_name').'結果削除',
            // アドバイスの更新の表示名は、ほかの edit_* の「〜編集」にそろえる（2026-10-18 変更：その前は「…評価文保存」）
            'edit_trainee_personality_diagnosis_evaluation' => __('messages.personality_diagnosis_name').'アドバイス編集',
            // スタッフ用メモの保存（2026-10-19 追加）
            'edit_trainee_personality_diagnosis_staff_memo' => __('messages.personality_diagnosis_name').'スタッフ用メモ編集',
            'publish_trainee_personality_diagnosis_evaluation' => __('messages.personality_diagnosis_name').'アドバイス公開',
            // 2026-10-18 変更：［公開取消］に合わせ、ほかの取消（「〜案内取消」）にそろえた。その前は「〜アドバイス公開停止」
            'unpublish_trainee_personality_diagnosis_evaluation' => __('messages.personality_diagnosis_name').'アドバイス公開取消',
            'create_client_contract' => '契約登録',
            'edit_client_contract' => '契約編集',
            'delete_client_contract' => '契約削除',
            'create_client_usage' => '利用登録',
            'edit_client_usage' => '利用編集',
            'delete_client_usage' => '利用削除',
        ];
    }

    /**
     * target_type → 日本語ラベルのマッピング
     *
     * アクセサ（getTargetLabelAttribute）から参照される。
     * 単一の真実の源として、この配列のみメンテナンスすればよい。
     */
    public static function targetLabels(): array
    {
        return [
            'Client' => '会員',
            'TrainingRecord' => 'トレーニング記録',
            'Trainee' => 'トレーニー',
            'TraineeMeasurement' => '計測値',
            'ClientContract' => '契約',
            'ClientUsage' => '利用',
        ];
    }

    /**
     * 操作名の日本語ラベル
     */
    public function getActionLabelAttribute(): string
    {
        return self::actionLabels()[$this->action] ?? $this->action;
    }

    /**
     * target_type の日本語ラベル
     *
     * target_type が null の場合は null を返す（ログインなど対象なしのケース）。
     * マッピングに無い値の場合は元の target_type を返す（フォールバック）。
     */
    public function getTargetLabelAttribute(): ?string
    {
        return $this->target_type
            ? (self::targetLabels()[$this->target_type] ?? $this->target_type)
            : null;
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
