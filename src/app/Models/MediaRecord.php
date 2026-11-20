<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * メディア記録モデル
 *
 * 写真・動画ファイルをライブラリ型で管理する。特定クライアントに従属せず、
 * トレーニング記録との紐付けは中間テーブル（D-0600）を介した多対多で行う。
 * ファイル実体はオブジェクトストレージに保存し、本モデルはそのメタデータを保持する。
 */
class MediaRecord extends Model
{
    use HasFactory;

    // テーブル名（規約では MediaRecord → media_records と解決されるため省略可能だが、
    // 既存モデル（AudioRecord）に揃えて明示する）
    protected $table = 'media_records';

    /**
     * メディアファイルの保存先ディスク名。
     * 実際のストレージプロバイダは config/filesystems.php および .env で決まる。
     * アプリ内でメディア用ディスクを参照する場合は必ずこの定数を使うこと。
     */
    const STORAGE_DISK = 'media';

    // メディア種別定数（DBのCHECK制約 type IN ('photo','video') と対応）
    const TYPE_PHOTO = 'photo';
    const TYPE_VIDEO = 'video';

    // 許可するMIMEタイプ（写真: jpeg/png/heic/heif、動画: mp4/mov）
    // image/heif はiOSがHEICファイルでも送出するケースの実機確認に基づき許可
    const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif'];
    const VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime'];

    // サイズ上限（要件 6-14-1）
    const MAX_PHOTO_SIZE = 20 * 1024 * 1024;           // 写真: 20MB
    const MAX_VIDEO_SIZE = 1024 * 1024 * 1024;         // 動画: 1GB

    // MIMEタイプ → 拡張子マッピング（storage_key 採番時の拡張子決定に使用）
    const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
    ];

    // ブラウザがそのまま表示・再生できるMIMEタイプ
    // heic/heif/quicktime は許可形式だが、変換しないとブラウザで開けない（変換対応は今後フェーズ）
    const BROWSER_DISPLAYABLE_MIME_TYPES = ['image/jpeg', 'image/png', 'video/mp4'];

    // 表示用変換の状態（DBのCHECK制約 conversion_status IN (...) と対応・5-18参照）
    // not_required: jpeg/png/mp4 など原本がそのまま表示可能で変換が不要
    // pending: heic/heif/mov など変換が必要だが未実行（store 直後の初期状態）
    // processing: 変換ジョブが処理中（controller で dispatch 前にセット）
    // done: 変換完了、display_path に変換後ファイルのキーがセット済み
    // error: 変換中にエラーが発生
    const CONVERSION_NOT_REQUIRED = 'not_required';
    const CONVERSION_PENDING = 'pending';
    const CONVERSION_PROCESSING = 'processing';
    const CONVERSION_DONE = 'done';
    const CONVERSION_ERROR = 'error';

    // サムネイル生成の状態（DBのCHECK制約 thumbnail_status IN (...) と対応・5-19参照）
    // pending: 生成待ち。全メディアの store 直後の初期状態（サムネイルは全メディアが対象）
    // processing: サムネイル生成ジョブが処理中
    // done: 生成完了、thumbnail_path にサムネイルファイルのキーがセット済み
    // error: 生成中にエラーが発生
    // 変換と異なり not_required は無い（jpeg/png/mp4 でも一覧用サムネイルは生成する）
    const THUMBNAIL_PENDING = 'pending';
    const THUMBNAIL_PROCESSING = 'processing';
    const THUMBNAIL_DONE = 'done';
    const THUMBNAIL_ERROR = 'error';

    // クライアント側の事前バリデーション用ファイル拡張子リスト
    // heic ファイルなどでブラウザが file.type を返さないケースに備え、拡張子でも判定するために使用。
    // MIME_TO_EXTENSION は採番用の片方向マップで .jpeg を含まないため、用途が異なる定数として別に持つ。
    const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'heic', 'heif'];
    const VIDEO_EXTENSIONS = ['mp4', 'mov'];

    // 拡張子 → MIMEタイプ マッピング（サーバ側の mime_type 決定に使用）
    // 採番用の MIME_TO_EXTENSION は mime→拡張子の片方向で .jpeg を扱えないため、別の独立した定数として持つ。
    // ブラウザの file.type は heic などで空文字や image/heif になるばらつきがあり信頼できないため、
    // 拡張子を主とし、ここから正規の mime_type を決定する。
    const EXTENSION_TO_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
    ];

    /**
     * MIMEタイプがブラウザで直接表示・再生可能か
     */
    public static function isBrowserDisplayable(string $mime): bool
    {
        return in_array($mime, self::BROWSER_DISPLAYABLE_MIME_TYPES, true);
    }

    /**
     * MIMEタイプから種別（photo/video）を判定する。許可リスト外は null を返す
     */
    public static function resolveTypeFromMime(string $mime): ?string
    {
        if (in_array($mime, self::PHOTO_MIME_TYPES, true)) {
            return self::TYPE_PHOTO;
        }
        if (in_array($mime, self::VIDEO_MIME_TYPES, true)) {
            return self::TYPE_VIDEO;
        }
        return null;
    }

    /**
     * MIMEタイプから拡張子を取得する。未対応は null を返す
     */
    public static function extensionForMime(string $mime): ?string
    {
        return self::MIME_TO_EXTENSION[$mime] ?? null;
    }

    /**
     * ファイル名（元ファイル名）の拡張子から正規のMIMEタイプを決定する。未対応は null を返す。
     *
     * ブラウザの file.type は heic 等で空文字や image/heif になるばらつきがあるため、
     * uploadUrl / store ではこのヘルパーで mime_type を決定する（クライアントの mime_type は不採用）。
     */
    public static function resolveMimeFromFilename(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }
        return self::EXTENSION_TO_MIME[$ext] ?? null;
    }

    /**
     * 種別ごとのサイズ上限を取得する
     */
    public static function maxSizeForType(string $type): ?int
    {
        return match ($type) {
            self::TYPE_PHOTO => self::MAX_PHOTO_SIZE,
            self::TYPE_VIDEO => self::MAX_VIDEO_SIZE,
            default => null,
        };
    }

    protected $fillable = [
        'trainer_id',
        'type',
        'title',
        'original_filename',
        'original_path',
        'display_path',
        'thumbnail_path',
        'mime_type',
        'file_size',
        'conversion_status',
        'thumbnail_status',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    // --- リレーション ---

    /**
     * アップロードしたトレーナー（登録者）
     *
     * トレーナー削除時は SET NULL になるため、null を返しうる。
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'trainer_id');
    }

    /**
     * このメディアが紐づくトレーニング記録（多対多・逆方向）
     *
     * 中間テーブル: media_record_training_record（D-0600）
     * 逆方向では sort_order に意味がない（記録ごとの順序のため）ので
     * withPivot / 並び替えは行わない。
     */
    public function trainingRecords(): BelongsToMany
    {
        return $this->belongsToMany(TrainingRecord::class);
    }

    /**
     * サムネイルの presigned 表示 URL を返す。
     *
     * thumbnail_status=done かつ thumbnail_path がセット済みのときのみ生成。
     * それ以外（pending/processing/error / path NULL）は null。
     * 期限は呼び出し側が決める（map ループで同一インスタンスを再利用する既存パターンに整合）。
     */
    public function temporaryThumbnailUrl(\DateTimeInterface $expiresAt): ?string
    {
        if ($this->thumbnail_status !== self::THUMBNAIL_DONE || !$this->thumbnail_path) {
            return null;
        }
        return Storage::disk(self::STORAGE_DISK)
            ->temporaryUrl($this->thumbnail_path, $expiresAt);
    }

    // --- ひも付く会員・トレーニーの表示（2026-11 追加。設計書 screen-design.md S-1302 / S-1302-M01） ---
    //
    // メディアとトレーニーは直接ひも付けない（ライブラリ型）。ひも付くトレーニング記録 → 会員 → 会員のトレーニー
    // とたどって表示する。N+1 を避けるため、呼び出し側で trainingRecords.client.trainees を eager load しておくこと
    // （MediaRecordController::index()）。文字列の組み立ては Client::traineesWithNameLabel() に 1 か所でまとめる。

    /**
     * ひも付くトレーニング記録を、トレーニング日の新しい順（同じ日なら記録 ID の大きい順）に並べて返す。
     * 会員が取れない記録（通常はない）は除く
     */
    public function linkedTrainingRecordsLatestFirst(): Collection
    {
        return $this->trainingRecords
            ->filter(fn ($record) => $record->client !== null)
            ->sort(function ($a, $b) {
                return [$b->training_date?->format('Y-m-d'), $b->id] <=> [$a->training_date?->format('Y-m-d'), $a->id];
            })
            ->values();
    }

    /**
     * 一覧のカード用：会員ごとに重複をまとめた「会員名：トレーニー」の文字列の一覧。
     * 並び順は、その会員のひも付く記録のうちトレーニング日がいちばん新しいものの新しい順。
     * 何行出すか（最大 3 行・「ほか N名」）は表示側で決める
     *
     * @return Collection<int, string>
     */
    public function linkedClientLabels(): Collection
    {
        return $this->linkedTrainingRecordsLatestFirst()
            ->unique('client_id')
            ->map(fn ($record) => $record->client->traineesWithNameLabel())
            ->values();
    }

    /**
     * 詳細モーダル用：ひも付く記録ごとの日付（Y/m/d）と「会員名：トレーニー」の文字列。
     * 同じ会員の記録が複数あれば記録ごとに 1 件。並び順は linkedTrainingRecordsLatestFirst() のとおり
     *
     * @return array<int, array{date: string, label: string}>
     */
    public function linkedTrainingRecordLines(): array
    {
        return $this->linkedTrainingRecordsLatestFirst()
            ->map(fn ($record) => [
                'date' => $record->training_date?->format('Y/m/d') ?? '',
                'label' => $record->client->traineesWithNameLabel(),
            ])
            ->all();
    }

    // --- アクセサ ---

    /**
     * 表示名（title 未入力時は元ファイル名にフォールバック）
     *
     * title が NULL もしくは空文字のときは original_filename を返す。
     */
    public function getDisplayTitleAttribute(): string
    {
        return $this->title ?: $this->original_filename;
    }
}
