<?php

namespace App\Models;

use App\Support\TraineeAge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 愛犬アンケートの回答（D-1100。2026-10 追加）。
 *
 * お客様が愛犬アンケート（S-1413）で答えた内容を、トレーニーの属性とは別に「答えたとおり」に残す
 * （回答の基本情報でトレーニーを上書きする。空・未選択の項目は変えない。要件定義書 6-15-17）。1 頭に複数件持てる（第1段階では 1 件）。
 * トレーナー側では閲覧のみ（S-0309。要件定義書 6-16-10）。お客様の操作で作られるため、
 * created_by / updated_by は持たない。
 */
class TraineeIntakeSurveyResponse extends Model
{
    /*
    |--------------------------------------------------------------------------
    | 性別・去勢・避妊の値とラベル
    |--------------------------------------------------------------------------
    | トレーニー（D-0700。5-20.・5-21.）と同じ値・同じラベルを使う（2026-10 変更。お客様の回答でトレーニーを
    | 上書きするため、選択肢をトレーナー側にそろえた）。二重に定義しないよう、値と対応表は Trainee の定数と
    | Trainee::sexLabels()・Trainee::neuterStatusLabels() をそのまま使う。
    | 経緯：当初は性別に「不明」を置かず（男の子・女の子だけ）、去勢・避妊は「分からない」（dont_know）を
    | トレーニーの「不明」（unknown）と別の値で持ち、反映のときに読み替えていた（db-schema.md 5-22。廃止）。
    */

    /*
    |--------------------------------------------------------------------------
    | 暮らしの様子の 4 問（2026-10-19 に愛犬性格診断へ移した）
    |--------------------------------------------------------------------------
    | 自由記述 4 欄（wish・recent_events・ideal_days・health_concerns）は、愛犬性格診断の回答（D-1200）へ移した
    | （設問文は PersonalityDiagnosisQuestions::lifeQuestions()、空の判定は TraineePersonalityDiagnosisResult::hasLifeAnswers()）。
    | トレーナーさんの元の仕組み（Google フォーム）では 4 問は性格診断の一部だったため。愛犬アンケートは基本情報だけになり、
    | この表の 4 列は 2026_10_19_000007 で外した（今あった答えは消した）
    */

    protected $fillable = [
        'trainee_id',
        'trainee_intake_survey_token_id',
        'answered_at',
        'name',
        'breed',
        'sex',
        'birth_year',
        'birth_month',
        'birth_day',
        'neuter_status',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'birth_year' => 'integer',
            'birth_month' => 'integer',
            'birth_day' => 'integer',
        ];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    /**
     * 回答に使った愛犬アンケート用 URL のトークン（トークンが削除されていれば null）
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(TraineeIntakeSurveyToken::class, 'trainee_intake_survey_token_id');
    }

    /**
     * 性別の日本語表示（答えたとおり）。未回答なら null
     */
    public function getSexLabelAttribute(): ?string
    {
        return $this->sex ? (Trainee::sexLabels()[$this->sex] ?? $this->sex) : null;
    }

    /**
     * 去勢・避妊の日本語表示（答えたとおり）。未回答なら null
     */
    public function getNeuterStatusLabelAttribute(): ?string
    {
        return $this->neuter_status ? (Trainee::neuterStatusLabels()[$this->neuter_status] ?? $this->neuter_status) : null;
    }

    /**
     * 誕生日の部分だけの表示（「2020/03/15」「2020年3月生まれ」「2020年生まれ」）。年齢は付けない
     * （回答時点の記録のため。screen-design.md S-0309）。未回答なら null
     */
    public function getBirthdayLabelAttribute(): ?string
    {
        return TraineeAge::birthdayLabel($this->birth_year, $this->birth_month, $this->birth_day);
    }
}
