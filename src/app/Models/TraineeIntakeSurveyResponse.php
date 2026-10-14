<?php

namespace App\Models;

use App\Support\TraineeAge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受付アンケートの回答（D-1100。2026-10 追加）。
 *
 * お客様が受付アンケート（S-1413）で答えた内容を、トレーニーの属性とは別に「答えたとおり」に残す
 * （トレーニーには空欄の項目だけ反映する。要件定義書 6-15-17）。1 頭に複数件持てる（第1段階では 1 件）。
 * トレーナー側では閲覧のみ（S-0309。要件定義書 6-16-10）。お客様の操作で作られるため、
 * created_by / updated_by は持たない。
 */
class TraineeIntakeSurveyResponse extends Model
{
    /*
    |--------------------------------------------------------------------------
    | 性別（回答では「不明」を置かない。要件定義書 6-15-17 備考）
    |--------------------------------------------------------------------------
    */

    /**
     * 回答の性別のコード → 日本語ラベル（Trainee::sexLabels() のうち男の子・女の子だけ）
     */
    public static function sexLabels(): array
    {
        return [
            Trainee::SEX_MALE => Trainee::sexLabels()[Trainee::SEX_MALE],
            Trainee::SEX_FEMALE => Trainee::sexLabels()[Trainee::SEX_FEMALE],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 去勢・避妊（db-schema.md 5-22）
    |--------------------------------------------------------------------------
    | 「分からない」（dont_know）は、トレーナーが選ぶトレーニーの「不明」（unknown）とは別の値で持つ
    | （答えたとおりを残すため）。トレーニーに反映するときは unknown に読み替える。
    */

    public const NEUTER_NEUTERED = 'neutered';

    public const NEUTER_NOT_NEUTERED = 'not_neutered';

    public const NEUTER_DONT_KNOW = 'dont_know';

    /**
     * 回答の去勢・避妊のコード → 日本語ラベル
     */
    public static function neuterStatusLabels(): array
    {
        return [
            self::NEUTER_NEUTERED => 'している',
            self::NEUTER_NOT_NEUTERED => 'していない',
            self::NEUTER_DONT_KNOW => '分からない',
        ];
    }

    /**
     * 回答の去勢・避妊の値を、トレーニー（D-0700。5-21）の値に読み替える。
     * 「分からない」はトレーニーの「不明」として反映する（要件定義書 D-1100）。未回答は null
     */
    public static function neuterStatusForTrainee(?string $value): ?string
    {
        return match ($value) {
            self::NEUTER_NEUTERED => Trainee::NEUTER_NEUTERED,
            self::NEUTER_NOT_NEUTERED => Trainee::NEUTER_NOT_NEUTERED,
            self::NEUTER_DONT_KNOW => Trainee::NEUTER_UNKNOWN,
            default => null,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | 自由記述 4 欄の設問文と例文（要件定義書 D-1100）
    |--------------------------------------------------------------------------
    | 回答画面（S-1413）と回答の表示（S-0309）の両方から使う、ただ 1 つの置き場所。
    | 設問文と例文は、トレーナーが Google フォームで使っていた文面のまま（要件定義書 D-1100）。
    | 文面を直すときは、要件定義書 D-1100 も同時に直すこと。
    */

    /**
     * 自由記述 4 欄（カラム名 → 短い呼び名・設問文・例文 2 つ）。この並びで画面に出す
     *
     * @return array<string, array{label: string, question: string, examples: array<int, string>}>
     */
    public static function questions(): array
    {
        return [
            'wish' => [
                'label' => 'こうなったらいいな',
                'question' => '日々の暮らしの中で、もっとこうなったらいいな、と感じることはありますか？',
                'examples' => [
                    'インターホンが鳴るたびに激しく吠えるのを、もう少し落ち着かせたい',
                    'お散歩中に他のワンちゃんを見つけると、グイグイ引っ張って大変なので楽に歩けるようになりたい',
                ],
            ],
            'recent_events' => [
                'label' => '最近の様子',
                'question' => '最近の様子で、ふと気になったことや、印象に残っている出来事があれば教えてください。',
                'examples' => [
                    '昨日の夕方のお散歩中、急に後ろから来た自転車に驚いて飛びかかろうとしてしまった',
                    '動物病院の待合室で、他の子が鳴くとガタガタ震えて私の後ろにずっと隠れていた',
                ],
            ],
            'ideal_days' => [
                'label' => '理想の毎日',
                'question' => 'これから一緒に、どんな楽しいことをしたいですか？『理想の毎日』を聞かせてください。',
                'examples' => [
                    '10歳を過ぎても、自分の足で力強くお散歩に行ける筋肉モリモリな健康体を維持してあげたい',
                    'ノーリードで遊べる広場で、ボール投げを全力で楽しみたい',
                ],
            ],
            'health_concerns' => [
                'label' => '体のこと',
                'question' => '体のことで気になることや、お医者様から言われていることがあれば教えてください。',
                'examples' => [
                    '過去に前足を骨折したことがあり、今でも少し触られるのを嫌がることがある',
                    '健康診断で『左後ろ足のパテラ（膝蓋骨脱臼）がグレード2』と言われているので、足腰に負担をかけたくない',
                ],
            ],
        ];
    }

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
        'wish',
        'recent_events',
        'ideal_days',
        'health_concerns',
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
     * 回答に使った受付アンケート用 URL のトークン（トークンが削除されていれば null）
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
        return $this->sex ? (self::sexLabels()[$this->sex] ?? $this->sex) : null;
    }

    /**
     * 去勢・避妊の日本語表示（答えたとおり。「分からない」はそのまま）。未回答なら null
     */
    public function getNeuterStatusLabelAttribute(): ?string
    {
        return $this->neuter_status ? (self::neuterStatusLabels()[$this->neuter_status] ?? $this->neuter_status) : null;
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
