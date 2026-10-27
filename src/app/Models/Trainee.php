<?php

namespace App\Models;

use App\Support\PersonalityDiagnosisQuestions;
use App\Support\TraineeAge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * トレーニーモデル（D-0700）
 *
 * 会員に紐づくトレーニング対象（当面は犬）。トレーニング記録（training_records）
 * は会員単位で、トレーニー単位ではない（設計書 requirements.md 6-16 参照）。
 */
class Trainee extends Model
{
    /*
    |--------------------------------------------------------------------------
    | 性別（sex）の定数と日本語ラベル
    |--------------------------------------------------------------------------
    | 値・ラベルの単一情報源。Blade からは sexLabels() を参照する。
    | AccessLog::actionLabels() と同じ発想で、ビューに条件分岐を書き散らさない。
    */

    public const SEX_MALE = 'male';
    public const SEX_FEMALE = 'female';
    public const SEX_UNKNOWN = 'unknown';

    /**
     * 性別コード → 日本語ラベルのマッピング
     */
    public static function sexLabels(): array
    {
        return [
            self::SEX_MALE => '男の子',
            self::SEX_FEMALE => '女の子',
            self::SEX_UNKNOWN => '不明',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 去勢・避妊（neuter_status）の定数と日本語ラベル（2026-10 追加。D-0700・5-21）
    |--------------------------------------------------------------------------
    | 性別と同じ作り。値・ラベルの単一情報源で、Blade からは neuterStatusLabels() を参照する。
    | 愛犬アンケートの回答の「分からない」（dont_know）は、トレーニーには unknown として反映する
    | （D-1100。回答の値は別のモデルで持つ）。
    */

    public const NEUTER_NEUTERED = 'neutered';
    public const NEUTER_NOT_NEUTERED = 'not_neutered';
    public const NEUTER_UNKNOWN = 'unknown';

    /**
     * 去勢・避妊のコード → 日本語ラベルのマッピング
     */
    public static function neuterStatusLabels(): array
    {
        return [
            self::NEUTER_NEUTERED => 'している',
            self::NEUTER_NOT_NEUTERED => 'していない',
            self::NEUTER_UNKNOWN => '不明',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 愛犬アンケートの状態（2026-10 追加。要件定義書 6-16-8）
    |--------------------------------------------------------------------------
    | 判定の順は「有効な案内あり → 回答待ち、なければ回答あり → 回答済み、どちらもない → 未発行」
    | （2026-10-19 変更：回答したあとも案内を発行し直せるようにしたため。その前は回答ありを先に見ていた）。
    */

    public const INTAKE_NOT_ISSUED = 'not_issued';
    public const INTAKE_AWAITING = 'awaiting';
    public const INTAKE_ANSWERED = 'answered';

    // 写真保存先の Filesystem ディスク名。
    // 既存メディアと同じ `media` ディスク（S3 互換）を共用し、キーの名前空間だけ
    // `trainees/` に分けて保存する（詳細は db-schema.md D-0700 注記「トレーニー写真の扱い」参照）。
    // MediaRecord::STORAGE_DISK と同じ流儀の定数として明示する。
    const STORAGE_DISK = 'media';

    // トレーニー写真の presigned 表示 URL の有効期限（分）
    // 既存の MediaRecordController::PLAY_URL_EXPIRES_MINUTES と揃える。
    const PHOTO_URL_EXPIRES_MINUTES = 15;

    protected $fillable = [
        'client_id',
        'name',
        'breed',
        'sex',
        'birth_year',
        'birth_month',
        'birth_day',
        'neuter_status',
        'photo_path',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            // 誕生日は年・月・日を別々に持つ（D-0700。2026-10 に birth_date から分けた）。
            // 分かるところだけ入るため、それぞれ NULL がありうる
            'birth_year' => 'integer',
            'birth_month' => 'integer',
            'birth_day' => 'integer',
        ];
    }

    /**
     * 会員
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * 計測値（D-0800）
     *
     * 並び順は計測日時の降順（新しい順）。トレーニー詳細（S-0309）で
     * この並び順のまま表示する。
     */
    public function measurements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TraineeMeasurement::class)
            ->orderBy('measured_date', 'desc')
            ->orderBy('measured_time', 'desc');
    }

    /**
     * 愛犬アンケート用 URL のトークン（DS-1000。回答済みのものを含む）
     */
    public function intakeSurveyTokens(): HasMany
    {
        return $this->hasMany(TraineeIntakeSurveyToken::class);
    }

    /**
     * 有効な（まだ回答されていない）愛犬アンケート用 URL のトークン。同じトレーニーに 1 つまで
     * （発行のサービスで守る）。会員詳細（S-0305）でトレーニーをまとめて読むときは
     * `trainees.activeIntakeSurveyToken` を eager load しておく（N+1 にしないため）
     */
    public function activeIntakeSurveyToken(): HasOne
    {
        // 未回答で期限内のもの（2026-10-19 変更：期限が切れた案内は有効に数えない。その前は未回答だけで決めていた）
        return $this->hasOne(TraineeIntakeSurveyToken::class)->active();
    }

    /**
     * 愛犬アンケートの回答（D-1100。1 頭に複数件持てる）
     */
    public function intakeSurveyResponses(): HasMany
    {
        return $this->hasMany(TraineeIntakeSurveyResponse::class);
    }

    /**
     * 最新の愛犬アンケートの回答（回答日時が最も新しいもの）。回答がなければ null。
     * 会員詳細（S-0305）でトレーニーをまとめて読むときは `trainees.latestIntakeSurveyResponse` を
     * eager load しておく（N+1 にしないため）
     */
    public function latestIntakeSurveyResponse(): HasOne
    {
        return $this->hasOne(TraineeIntakeSurveyResponse::class)->latestOfMany('answered_at');
    }

    /**
     * 性格診断用 URL のトークン（DS-1100。回答済みのものを含む。2026-10 追加）
     */
    public function personalityDiagnosisTokens(): HasMany
    {
        return $this->hasMany(TraineePersonalityDiagnosisToken::class);
    }

    /**
     * いちばん新しく発行した愛犬アンケート用 URL のトークン（回答済み・期限切れを含む。2026-10-19 追加）。
     * 会員詳細の「期限切れ」の行（expired_survey_kind）に使う。発行日時が同じなら id の大きい方
     */
    public function latestIntakeSurveyToken(): HasOne
    {
        return $this->hasOne(TraineeIntakeSurveyToken::class)->latestOfMany(['created_at', 'id']);
    }

    /**
     * いちばん新しく発行した愛犬性格診断用 URL のトークン（回答済み・期限切れを含む。2026-10-19 追加）
     */
    public function latestPersonalityDiagnosisToken(): HasOne
    {
        return $this->hasOne(TraineePersonalityDiagnosisToken::class)->latestOfMany(['created_at', 'id']);
    }

    /**
     * 有効な（まだ回答されていない）性格診断用 URL のトークン。同じトレーニーに 1 つまで
     * （発行のときに守る）。会員詳細（S-0305）でトレーニーをまとめて読むときは
     * `trainees.activePersonalityDiagnosisToken` を eager load しておく（N+1 にしないため）
     */
    public function activePersonalityDiagnosisToken(): HasOne
    {
        // 未回答で期限内のもの（2026-10-19 変更：期限が切れた案内は有効に数えない）
        return $this->hasOne(TraineePersonalityDiagnosisToken::class)->active();
    }

    /**
     * 性格診断の結果（D-1200。1 頭に何件でも持てる）。新しい順（回答日時の降順。同じ日時なら後に作ったものが先）。
     * トレーニー詳細（S-0309）の性格診断の欄はこの並び順のまま出し、「前回」は 1 つ後ろの要素
     */
    public function personalityDiagnosisResults(): HasMany
    {
        return $this->hasMany(TraineePersonalityDiagnosisResult::class)
            ->orderByDesc('answered_at')
            ->orderByDesc('id');
    }

    /**
     * 最新の性格診断の結果（回答日時が最も新しいもの）。結果がなければ null
     */
    public function latestPersonalityDiagnosisResult(): HasOne
    {
        return $this->hasOne(TraineePersonalityDiagnosisResult::class)->latestOfMany('answered_at');
    }

    /**
     * 最終更新者（トレーナー）
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'updated_by');
    }

    /**
     * トレーニーの項目のうち、どれかが入っていれば「空でない」とみなす列（2026-10-18 追加。要件定義書 6-3-5）。
     * 名前・犬種・性別・誕生日（年・月・日）・去勢避妊・写真。client_id・updated_by・日時は登録内容ではないので見ない
     */
    public const CONTENT_COLUMNS = ['name', 'breed', 'sex', 'birth_year', 'birth_month', 'birth_day', 'neuter_status', 'photo_path'];

    /**
     * トレーニーに結び付く表（2026-10-18 追加。要件定義書 6-3-5）。どれかに 1 行でもあれば「空でない」とみなす。
     * どの表も trainee_id の外部キーが ON DELETE CASCADE（トレーニーを消すと一緒に消える）なので、空でないトレーニーを
     * 会員の削除で一緒に消さないように、ここで見る。トレーニーに結び付く表を足したら、ここにも足す
     */
    public const CONTENT_RELATIONS = [
        'measurements',                 // 計測値（D-0800）
        'intakeSurveyTokens',           // 愛犬アンケートの案内（回答済み・未回答とも）
        'intakeSurveyResponses',        // 愛犬アンケートの回答（基本情報。2026-10-19 から自由記述の質問は愛犬性格診断の結果の側）
        'personalityDiagnosisTokens',   // 愛犬性格診断の案内（回答済み・未回答とも）
        'personalityDiagnosisResults',  // 愛犬性格診断の結果（アドバイス・自由記述の質問を含む）
    ];

    /**
     * 空でないトレーニーに絞る（2026-10-18 追加。要件定義書 6-3-5）。
     *
     * 「空のトレーニー」＝ CONTENT_COLUMNS がどれも空（NULL か空文字）で、CONTENT_RELATIONS のどの表にも行がないトレーニー。
     * 会員の登録で自動で作ったトレーニー（中身は空）だけの会員は、トレーニーごと削除できる（Client::hasNonBlankTrainees）。
     * 判定はこの 1 か所にまとめ、会員詳細（S-0305）の削除の確認と ClientController::destroy の両方で使う
     */
    public function scopeNotBlank(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (self::CONTENT_COLUMNS as $column) {
                // 誕生日（年・月・日）は数の列なので NULL かどうかだけを見る。文字の列は空文字も空とみなす
                $q->orWhere(fn (Builder $c) => str_starts_with($column, 'birth_')
                    ? $c->whereNotNull($column)
                    : $c->whereNotNull($column)->where($column, '!=', ''));
            }
            foreach (self::CONTENT_RELATIONS as $relation) {
                $q->orWhereHas($relation);
            }
        });
    }

    /**
     * 空のトレーニーか（2026-10-18 追加）。判定は scopeNotBlank と同じ（DB に問い合わせる）
     */
    public function isBlank(): bool
    {
        return ! self::query()->whereKey($this->id)->notBlank()->exists();
    }

    /**
     * 性別の日本語表示
     */
    public function getSexLabelAttribute(): ?string
    {
        return $this->sex ? (self::sexLabels()[$this->sex] ?? $this->sex) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | 名前の表示（2026-10 追加。screen-design.md §2-4 の例外・要件定義書 6-16）
    |--------------------------------------------------------------------------
    | 名前は任意（D-0700）で、未登録でも空欄にしない（名前はトレーニーを指し示す目印のため）。
    | 未登録のときの言い方がトレーナー側と会員側で違うため、Blade に条件式を散らさないよう
    | アクセサにまとめる。名前の登録の有無は blank() で見る（空文字も未登録として扱う。
    | マイグレーションの down() で NULL を空文字で埋めた行も、未登録として出るように）。
    */

    /**
     * 名前が登録されているか
     */
    public function getHasNameAttribute(): bool
    {
        return ! blank($this->name);
    }

    /**
     * トレーナー側の表示名。名前があれば名前、なければ「名前未登録」。
     * 「名前未登録」を薄い文字（text-muted）にするかは画面ごとに決める（has_name で判定する）。
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->has_name ? $this->name : '名前未登録';
    }

    /**
     * 会員側の表示名。名前があれば名前、なければ「愛犬」。
     */
    public function getClientDisplayNameAttribute(): string
    {
        return $this->has_name ? $this->name : '愛犬';
    }

    /**
     * 会員側の呼び名。名前があれば「{名前}ちゃん」、なければ「愛犬」（「ちゃん」を付けない）。
     * 会員ダッシュボード（S-1402）で名前のあとに「ちゃん」を付けている箇所（見出し・写真の
     * alt・aria-label・モーダルの題名）で使う。「ちゃん」の付け外しを Blade に散らさないため。
     */
    public function getClientCallNameAttribute(): string
    {
        return $this->has_name ? $this->name.'ちゃん' : '愛犬';
    }

    /**
     * 去勢・避妊の日本語表示。未登録なら null
     */
    public function getNeuterStatusLabelAttribute(): ?string
    {
        return $this->neuter_status ? (self::neuterStatusLabels()[$this->neuter_status] ?? $this->neuter_status) : null;
    }

    /**
     * 誕生日の部分だけの表示（「2020/03/15」「2020年3月生まれ」「2020年生まれ」）。未登録なら null。
     *
     * 規則は要件定義書 6-16「年齢の表示規則」。組み立ては App\Support\TraineeAge に集約する。
     */
    public function getBirthdayLabelAttribute(): ?string
    {
        return TraineeAge::birthdayLabel($this->birth_year, $this->birth_month, $this->birth_day);
    }

    /**
     * 誕生日と年齢を並べた表示（「2020/03/15（6歳7ヶ月）」「2020年3月生まれ（6歳7ヶ月）」
     * 「2020年生まれ（約6歳）」）。未登録なら null。会員詳細（S-0305）のトレーニーカードと
     * トレーニー詳細（S-0309）で使う。基準日は表示するその日（アプリのタイムゾーンでの今日）。
     *
     * 2026-10 変更：以前は `age` アクセサ（birth_date から年単位・切り捨て）で年齢だけを返し、
     * Blade で「Y/m/d（N歳）」に組み立てていた。誕生日を年・月・日に分けたため置き換えた。
     */
    public function getBirthdayWithAgeLabelAttribute(): ?string
    {
        $today = today();

        return TraineeAge::birthdayWithAgeLabel(
            $this->birth_year, $this->birth_month, $this->birth_day,
            $today->year, $today->month, $today->day,
        );
    }

    /**
     * 会員側のマイページ（S-1402）の愛犬のカードで、写真の下に出す 2 行（2026-10-18 追加）。
     *
     * 1 行目は「犬種（性別）」（例「マルチーズ（女の子）」）、2 行目は誕生日（例「2020/01/23生（6歳8ヶ月）」。
     * TraineeAge::clientBirthdayParts）。各行は、折り返してよい位置で分けた文字の並び（1 行目は「マルチーズ」と「（女の子）」、
     * 2 行目は「2020/01/23生」と「（6歳8ヶ月）」）。犬種・性別はトレーナー側の会員詳細（S-0305）と同じ値（性別は sex_label）。
     * 性別の「不明」は会員様には情報にならないので、値がないのと同じ扱い。値がないとき：
     * - 1 行目：犬種だけ →「マルチーズ」、性別だけ →「女の子」（括弧を付けない）、どちらもない → 1 行目を入れない
     * - 2 行目：誕生日がない → 入れない
     * すべてなければ空の配列（ビューは何も出さない）。去勢・避妊などほかの属性は入れない。
     * 2026-10-18 変更：最初は名前の下の 1 行に「 ／ 」でつないで出し、次に写真の下に 1 項目ずつ 3 行で出していた
     *
     * @return list<list<string>>
     */
    public function getClientProfilePartsAttribute(): array
    {
        $breed = blank($this->breed) ? null : $this->breed;
        $sex = $this->sex === self::SEX_UNKNOWN || blank($this->sex_label) ? null : $this->sex_label;
        $today = today();
        $birthday = TraineeAge::clientBirthdayParts(
            $this->birth_year, $this->birth_month, $this->birth_day,
            $today->year, $today->month, $today->day,
        );

        $lines = [];
        if ($breed !== null && $sex !== null) {
            $lines[] = [$breed, '（'.$sex.'）'];
        } elseif ($breed !== null || $sex !== null) {
            $lines[] = [$breed ?? $sex];
        }
        if ($birthday !== []) {
            $lines[] = $birthday;
        }

        return $lines;
    }

    /**
     * 会員側のマイページ（S-1402）の愛犬のカードで、写真の下の 3 行目に出す最新体重（2026-10-27 追加）。
     *
     * 「体重 5.00 kg（2026/10/15）」の 1 行を、折り返してよい位置で分けた文字の並び（「体重 5.00 kg」と「（2026/10/15）」）で返す。
     * 体重は小数第 2 位まで（グラフのツールチップとそろえる）、日付はゼロ埋めの yyyy/MM/dd（誕生日の行とそろえる）。時刻と前回比は出さない。
     * 最新は measurements（計測日・計測時刻の降順）の先頭。計測値が 0 件なら空の配列（行を出さない）。
     * client_profile_parts（犬種・性別・誕生日）とは関心事が違うので分け、マイページのコントローラーでうしろにつなぐ。
     * measurements は呼ぶ側で先に読み込んでおく（グラフ用に読み込み済み。N+1 にしないため）
     *
     * @return list<list<string>>
     */
    public function getClientLatestWeightPartsAttribute(): array
    {
        $latest = $this->measurements->first();
        if ($latest === null) {
            return [];
        }

        return [[
            '体重 '.number_format((float) $latest->weight_kg, 2, '.', '').' kg',
            '（'.$latest->measured_date->format('Y/m/d').'）',
        ]];
    }

    /**
     * 会員詳細の「期限切れ」の行に出す案内の種類（'intake'／'personality'／'combined'。出さないなら null。2026-10-19 追加。
     * 設計書 S-0305 セクション3「案内の操作」）。
     * 'combined'（2026-10-19 追加）：いちばん新しい案内が「愛犬アンケート＆愛犬性格診断」で、2 つとも未回答で期限切れのとき。
     * 愛犬アンケートだけ答えて愛犬性格診断が期限切れなら 'personality'（2 つは同じ日時に作るので、いちばん新しいのは愛犬性格診断の方）。
     *
     * そのトレーニーのいちばん新しく発行した案内（2 つの種類を合わせて、発行日時がいちばん新しいもの。同じなら愛犬性格診断）が、
     * 未回答で期限が切れているときだけ、その種類を返す。
     * - 回答待ち（未回答で期限内）の案内があれば null（「案内中」の行を出すため）
     * - いちばん新しい案内が回答済みなら null（その前に期限切れの案内があっても）。新しく発行すると、いちばん新しい案内が変わるので消える
     * - 期限が空の案内（期限を付ける前に発行したもの）は期限切れにならないので null
     *
     * 呼び出し側は activeIntakeSurveyToken・activePersonalityDiagnosisToken・latestIntakeSurveyToken・
     * latestPersonalityDiagnosisToken を eager load しておくこと（会員詳細でトレーニーごとに問い合わせを出さないため）
     */
    public function getExpiredSurveyKindAttribute(): ?string
    {
        if ($this->activeIntakeSurveyToken !== null || $this->activePersonalityDiagnosisToken !== null) {
            return null;
        }
        $intake = $this->latestIntakeSurveyToken;
        $personality = $this->latestPersonalityDiagnosisToken;
        if ($intake === null && $personality === null) {
            return null;
        }
        [$kind, $latest] = match (true) {
            $intake === null => ['personality', $personality],
            $personality === null => ['intake', $intake],
            $intake->created_at->gt($personality->created_at) => ['intake', $intake],
            default => ['personality', $personality],
        };

        if ($latest->answered_at !== null || ! $latest->isExpired()) {
            return null;
        }
        // 組み合わせの愛犬性格診断の案内で、結び付いた愛犬アンケートの案内も未回答なら、組み合わせとして出す
        if ($kind === 'personality' && $intake !== null && $intake->trainee_personality_diagnosis_token_id === $personality->id
            && $intake->answered_at === null) {
            return 'combined';
        }

        return $kind;
    }

    /**
     * 「愛犬アンケート＆愛犬性格診断」の案内が、2 つとも回答待ち（未回答で期限内）か（2026-10-19 追加）。
     * 会員詳細の「案内中」・マイページのお知らせで、2 つを 1 つの案内として出すときに使う。
     * 愛犬アンケートだけ答えたあとは false（残っている愛犬性格診断の案内は、単独の案内と同じに出す）。
     * 呼び出し側は activeIntakeSurveyToken・activePersonalityDiagnosisToken を eager load しておくこと
     */
    public function getHasActiveCombinedSurveyAttribute(): bool
    {
        return $this->activeIntakeSurveyToken !== null && $this->activePersonalityDiagnosisToken !== null
            && $this->activeIntakeSurveyToken->trainee_personality_diagnosis_token_id === $this->activePersonalityDiagnosisToken->id;
    }

    /**
     * 愛犬アンケートの状態（INTAKE_* の定数）。要件定義書 6-16-8 の判定の順
     * （有効な案内あり → 回答待ち、なければ回答あり → 回答済み、どちらもない → 未発行）。
     * 2026-10-19 変更：回答したあとも案内を発行し直して答え直してもらえるようにしたため、有効な案内（答え直しの案内）が
     * あれば、回答があっても「回答待ち」にする（会員詳細の［表示］［取消］、マイページのお知らせのため）。
     * その前は回答ありを先に見ていた
     *
     * 呼び出し側は latestIntakeSurveyResponse と activeIntakeSurveyToken を eager load しておくこと
     * （会員詳細でトレーニーごとに追加のクエリを出さないため）
     */
    public function getIntakeSurveyStatusAttribute(): string
    {
        if ($this->activeIntakeSurveyToken !== null) {
            return self::INTAKE_AWAITING;
        }

        return $this->latestIntakeSurveyResponse !== null ? self::INTAKE_ANSWERED : self::INTAKE_NOT_ISSUED;
    }

    /**
     * 最新の計測値（計測日時の降順で先頭）。計測値が 0 件なら null。
     *
     * `measurements()` リレーションが計測日時降順で並ぶ既定を利用し、
     * `first()` で先頭 1 件を取り出す。会員詳細（S-0305）のトレーニーカードで
     * 「最終計測」の表示に使う。呼び出し側は `trainees.measurements` を
     * eager load しておくこと（`ClientController::show()` 参照）。
     * 詳細は screen-design.md S-0305 セクション3「最終計測」参照。
     */
    public function getLatestMeasurementAttribute(): ?TraineeMeasurement
    {
        return $this->measurements->first();
    }

    /**
     * トレーニー写真の presigned 表示 URL を返す。
     *
     * `photo_path` が NULL のとき（写真未登録）は null。
     * それ以外は media ディスクの temporaryUrl で署名付き URL を発行する。
     * 期限は呼び出し側で有効期限をこの場で決めるより、モデルに固定値
     * （PHOTO_URL_EXPIRES_MINUTES）を持たせて Blade で毎回書かなくても済むようにする。
     * 既存の MediaRecord::temporaryThumbnailUrl(DateTimeInterface) は呼び出し側で
     * 期限を渡す設計だが、あちらは一覧で複数メディアの期限を揃える運用のため。
     * こちらはトレーニーごとに 1 枚で運用差が生じないため、モデル側で完結させる。
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }
        return Storage::disk(self::STORAGE_DISK)
            ->temporaryUrl($this->photo_path, now()->addMinutes(self::PHOTO_URL_EXPIRES_MINUTES));
    }

    /**
     * 体重推移グラフ 1 頭分のデータを組み立てる。
     *
     * 会員ダッシュボード（S-1402）とトレーナー側トレーニー詳細（S-0309）の両方で
     * 共用する。呼び出し側は本アクセサの返り値をトレーニーごとに集めるだけで足り、
     * 組み立てロジック（`measurements` の昇順ソート・`{x, y}` への変換・タイムゾーン
     * の扱い）が 1 か所に集約される。詳細な設計方針は screen-design.md S-1402
     * 「グラフ用データの組み立ては `Trainee` モデルのアクセサに集約」・S-0309
     * 「トレーナー側にも写真と体重推移グラフを表示する」参照。
     *
     * 返す配列の形（**計測値 0 件のトレーニーも呼べる**が、その場合は `datasets` が
     * 空配列。Blade 側で `empty($chart['datasets'])` を判定して「まだ計測値が
     * ありません」の案内に切り替える。詳細は screen-design.md S-1402
     * 「体重推移」設計方針の 2026-09 変更参照）:
     * [
     *   'id'       => (int) トレーニーID,
     *   'callName' => (string) 会員側の呼び名（「モモちゃん」／名前が未登録なら「愛犬」。
     *                 client_call_name。2026-10 に 'name'〔名前そのもの〕から置き換えた。
     *                 会員ダッシュボードで「ちゃん」を Blade に書かずに済むようにするため）,
     *   'photoUrl' => (?string) 写真の presigned URL または null,
     *   'datasets' => [                          // 常に 0 or 1 本（線の分割を廃止）
     *     [
     *       'data' => [                          // {x, y} オブジェクト配列（Chart.js の時間軸形式）
     *         ['x' => '2026-09-14T08:00:00', 'y' => 4.50],
     *         ['x' => '2026-09-14T18:00:00', 'y' => 4.55],
     *         ...
     *       ],
     *     ],
     *   ],
     * ]
     *
     * ## 前提：`measurements` は eager load しておくこと
     *
     * 一覧で多頭を回すケース（S-1402 で $client->trainees()->with('measurements')）
     * にも、単頭ケース（S-0309 で $trainee->load('measurements')）にも同じ形で
     * 使えるが、いずれも呼び出し側で `measurements` を eager load しておくこと
     * （load せずに呼ぶと本アクセサ内の `$this->measurements` で追加クエリが発生する）。
     *
     * ## 横軸の扱い（2026-09 変更、設計書 S-1402「横軸を時間軸に変更した経緯」参照）
     *
     * 従前は「計測があった日付をカテゴリとして等間隔に並べ、一定日数以上空いたら
     * 線を分割する」方式だったが、間隔が違う計測点が同じ幅で表示される問題のほうが
     * 重大だったため、時間軸（`type: 'time'`）に変更した。線は 1 本の連続した折れ線で
     * 結ぶ（分割ロジック・`chart_gap_split_days` 設定値・null 埋め処理はすべて廃止）。
     *
     * ## タイムゾーンの扱い
     *
     * `measured_date`（Y-m-d）と `measured_time`（HH:MM:SS）を naive な ISO 8601
     * 文字列（`YYYY-MM-DDTHH:MM:SS`、タイムゾーン指定なし）に連結する。UTC の `Z` や
     * `+HH:MM` オフセットは付けない。ブラウザ側の date-fns/Chart.js は naive 文字列を
     * **ローカル時間として解釈**するため、DB 上の値（アプリタイムゾーン基準で入っている）
     * がそのままの見た目で表示される。Carbon 経由で toIso8601String() 等を使うと
     * UTC 変換や `+09:00` オフセットが混入するため、あえて `format()` で手組みする。
     */
    public function getWeightChartDataAttribute(): array
    {
        // measurements は Trainee::measurements() で日時**降順**なので、
        // グラフ用には昇順に並べ直す（日付 → 時刻の順）。
        $measurements = $this->measurements
            ->sortBy([
                ['measured_date', 'asc'],
                ['measured_time', 'asc'],
            ])
            ->values();

        if ($measurements->isEmpty()) {
            // 計測値 0 件のトレーニーもカードを出す（Blade で空判定して
            // 「まだ計測値がありません」を表示。空のグラフは描かない）。
            // 設計書 S-1402「体重推移」設計方針の 2026-09 変更参照。
            return [
                'id' => $this->id,
                'callName' => $this->client_call_name,
                'photoUrl' => $this->photo_url,
                'datasets' => [],
            ];
        }

        // 各計測を {x: ISO8601 ローカル, y: 体重} に変換する。
        // measured_time は 'HH:MM:SS' で保存されているため、substr(0, 8) で HH:MM:SS を
        // 抜き出す。Chart.js の tooltipFormat 側で「Y/n/j HH:mm」まで丸めて表示する
        // （表示形式は measurement-chart.js で指定）。
        $points = [];
        foreach ($measurements as $m) {
            $points[] = [
                'x' => $m->measured_date->format('Y-m-d') . 'T' . substr($m->measured_time, 0, 8),
                'y' => (float) $m->weight_kg,
            ];
        }

        return [
            'id' => $this->id,
            'callName' => $this->client_call_name,
            // トレーニー写真の presigned URL（写真未登録なら null）。
            // photo_url アクセサは trainee 1 件で 1 回だけ presigned URL を発行するため、
            // 追加のクエリは走らない（photo_path はそのカラム値を使うだけ）。
            'photoUrl' => $this->photo_url,
            // datasets は常に 1 本のみ（線の分割を廃止したため。設計書 S-1402
            // 「横軸を時間軸に変更した経緯」参照）。0 件のトレーニーは上の isEmpty
            // 早期リターンで datasets: [] を返しているためここには来ない。
            'datasets' => [
                ['data' => $points],
            ],
        ];
    }

    /**
     * 性格診断のレーダーチャートに渡す値（canvas の data-personality-radar の JSON。2026-10 追加）。
     * トレーナー側のトレーニー詳細（S-0309）と、会員側のマイページ（S-1402）で同じものを使う。
     * 結果がなければ null。
     *
     * 重ねる回：今回＝いちばん新しい回、前回＝その 1 つ前、初回＝いちばん古い回（結果が 3 つ以上のときだけ。
     * 2 つのときは前回と初回が同じ回なので重ねない）。間の回は重ねない。値は表示の値（小数第 1 位）と回答日。
     * 結果は personalityDiagnosisResults（新しい順）。呼ぶ側で先に読み込んでおく（N+1 にしないため）
     *
     * keys はカテゴリの値（labels と同じ並び。2026-10-18 追加）。グラフで訓練性能の軸を真上に置くため、JS が訓練性能の位置を
     * 表示名ではなく値（trainability）で探すのに使う（personality-radar-config.js の radarStartAngle）
     *
     * @return array{labels: string[], keys: string[], series: array{key: string, date: string, values: string[]}[]}|null
     */
    public function getPersonalityRadarDataAttribute(): ?array
    {
        return self::buildPersonalityRadarData($this->personalityDiagnosisResults);
    }

    /**
     * 指定した結果の時点のレーダーチャートの値（2026-10-18 追加。要件定義書 6-16-15・6-16-16）。形は personality_radar_data と同じ。
     *
     * 指定した結果を今回にし、前回・初回は、それより前の結果（personalityDiagnosisResults の新しい順で、指定した結果より後ろ。
     * 同じ回答日時なら id の小さいほう）から取る。決まり（前回＝1 つ前、初回＝いちばん古い回で 3 つ以上のときだけ）は今の組み立てと同じ。
     * 指定した結果がこのトレーニーの結果でなければ null。結果は personalityDiagnosisResults（新しい順）。呼ぶ側で先に読み込んでおく
     *
     * @return array{labels: string[], keys: string[], series: array{key: string, date: string, values: (string|null)[]}[]}|null
     */
    public function personalityRadarDataAsOf(TraineePersonalityDiagnosisResult $result): ?array
    {
        $results = $this->personalityDiagnosisResults->values();
        $index = $results->search(fn (TraineePersonalityDiagnosisResult $item) => $item->id === $result->id);
        if ($index === false) {
            return null;
        }

        return self::buildPersonalityRadarData($results->slice($index)->values());
    }

    /**
     * 指定した結果だけのレーダーチャートの値（2026-10-19 追加。会員側の愛犬性格診断の画面 S-1418 用）。形は personality_radar_data と同じで、
     * series は今回（key = current）の 1 つだけ（前回・初回は重ねない）。記録の画面はその回の結果を見る画面で、前の回と比べるのは
     * マイページの愛犬のカード（personality_radar_data）で行うため。指定した結果がこのトレーニーの結果でなければ null。
     * 作り方は結果の personalityRadarDataOnly()（2026-10-20 変更：マイページの記録の一覧のアドバイスのカードと 1 か所にまとめた）
     *
     * @return array{labels: string[], keys: string[], series: array{key: string, date: string, values: (string|null)[]}[]}|null
     */
    public function personalityRadarDataOnly(TraineePersonalityDiagnosisResult $result): ?array
    {
        if ((int) $result->trainee_id !== (int) $this->id) {
            return null;
        }

        return $result->personalityRadarDataOnly();
    }

    /**
     * 新しい順の結果から、レーダーチャートの値を組み立てる（いちばん前が今回。2026-10-18 に personality_radar_data から切り出し、
     * 指定した結果の時点の組み立て〔personalityRadarDataAsOf〕と共通にした）。結果がなければ null。
     * 2026-10-20 変更：結果だけのグラフ（TraineePersonalityDiagnosisResult::personalityRadarDataOnly）からも呼ぶので public にした
     *
     * @param  Collection<int, TraineePersonalityDiagnosisResult>  $results  新しい順（キーは 0 から）
     */
    public static function buildPersonalityRadarData(Collection $results): ?array
    {
        if ($results->isEmpty()) {
            return null;
        }

        $series = array_filter([
            'current' => $results->first(),
            'previous' => $results->get(1),
            'initial' => $results->count() >= 3 ? $results->last() : null,
        ]);

        return [
            'labels' => array_values(PersonalityDiagnosisQuestions::categories()),
            'keys' => array_keys(PersonalityDiagnosisQuestions::categories()),
            'series' => collect($series)->map(fn (TraineePersonalityDiagnosisResult $result, string $key) => [
                'key' => $key,
                'date' => $result->answered_at->format('Y/m/d'),
                'values' => array_values($result->displayScores()),
            ])->values()->all(),
        ];
    }
}
