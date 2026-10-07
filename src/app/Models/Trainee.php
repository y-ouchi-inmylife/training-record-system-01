<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'birth_date',
        'note',
        'photo_path',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
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
     * 最終更新者（トレーナー）
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'updated_by');
    }

    /**
     * 性別の日本語表示
     */
    public function getSexLabelAttribute(): ?string
    {
        return $this->sex ? (self::sexLabels()[$this->sex] ?? $this->sex) : null;
    }

    /**
     * 誕生日から算出した年齢（年単位・切り捨て）。
     * 誕生日が未登録なら null を返す。
     */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->age;
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
     *   'name'     => (string) トレーニー名,
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
                'name' => $this->name,
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
            'name' => $this->name,
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
}
