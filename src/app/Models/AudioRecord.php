<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 音声記録モデル
 *
 * 録音・アップロード・テキスト貼り付けで作成された音声記録
 * （実音声ファイル＋文字起こしテキスト＋要約テキスト＋メタデータの統合体）を管理する。
 *
 */
class AudioRecord extends Model
{
    use HasFactory;

    // テーブル名（規約では AudioRecord → audio_records と解決されるため省略可能だが、
    // 段階的リネーム履歴の明示と将来の混乱回避のため宣言を残す）
    protected $table = 'audio_records';

    // ステータス定数
    const STATUS_UNPROCESSED = 'unprocessed';
    const STATUS_TRANSCRIBING = 'transcribing';
    const STATUS_TRANSCRIBED = 'transcribed';
    const STATUS_SUMMARIZING = 'summarizing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_ERROR = 'error';

    // データソース種別定数
    const SOURCE_RECORDING = 'recording';
    const SOURCE_UPLOAD = 'upload';
    const SOURCE_TEXT_PASTE = 'text_paste';

    // 許可する音声ファイル拡張子
    const ALLOWED_EXTENSIONS = ['mp3', 'm4a', 'wav', 'mp4', 'webm'];

    // 最大ファイルサイズ（100MB）
    const MAX_FILE_SIZE = 100 * 1024 * 1024;

    protected $fillable = [
        'trainer_id',
        'client_id',
        'title',
        'source',
        'file_name',
        'file_path',
        'status',
        'transcription_text',
        'summary_text',
        'duration_seconds',
        'file_size',
        'summarized_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'file_size' => 'integer',
            'summarized_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    // --- リレーション ---

    /**
     * アップロード・録音したトレーナー
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'trainer_id');
    }

    /**
     * 紐付くクライアント
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // --- ステータス判定 ---

    /**
     * 未処理かどうか
     */
    public function isUnprocessed(): bool
    {
        return $this->status === self::STATUS_UNPROCESSED;
    }

    /**
     * 処理中（文字起こし中 or 要約中）かどうか
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, [
            self::STATUS_TRANSCRIBING,
            self::STATUS_SUMMARIZING,
        ]);
    }

    /**
     * 文字起こし済みかどうか
     */
    public function isTranscribed(): bool
    {
        return $this->status === self::STATUS_TRANSCRIBED;
    }

    /**
     * 完了かどうか
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * エラーかどうか
     */
    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * 文字起こしを実行可能かどうか
     * 音声ファイルが存在していれば実行可能（再実行も含む）
     * ただし文字起こし処理中は重複実行を防ぐ
     */
    public function canTranscribe(): bool
    {
        return !empty($this->file_path)
            && $this->status !== self::STATUS_TRANSCRIBING;
    }

    /**
     * 要約を実行可能かどうか
     * 文字起こしテキストが存在していれば実行可能（再実行も含む）
     * ただし要約処理中は重複実行を防ぐ
     */
    public function canSummarize(): bool
    {
        return !empty($this->transcription_text)
            && $this->status !== self::STATUS_SUMMARIZING;
    }

    /**
     * 削除可能かどうか（処理中は削除不可）
     */
    public function canDelete(): bool
    {
        return !$this->isProcessing();
    }

    /**
     * 音声ファイルが削除済みか（文字起こし・要約は残っている状態）
     * テキスト貼り付け（source='text_paste'）のレコードは常にfalse
     */
    public function isAudioDeleted(): bool
    {
        if ($this->source === self::SOURCE_TEXT_PASTE) {
            return false;
        }
        return empty($this->file_path) && in_array($this->status, [
            self::STATUS_TRANSCRIBED,
            self::STATUS_COMPLETED,
        ]);
    }

    // --- アクセサ ---

    /**
     * ステータス → 日本語ラベルのマッピング
     *
     * アクセサ（getStatusLabelAttribute）と Blade のバッジ表示の両方から
     * この定義を参照することで、ラベル定義を1箇所に集約する。
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_UNPROCESSED  => '文字起こし待ち',
            self::STATUS_TRANSCRIBING => '文字起こし中',
            self::STATUS_TRANSCRIBED  => '要約待ち',
            self::STATUS_SUMMARIZING  => '要約中',
            self::STATUS_COMPLETED    => '要約完了',
            self::STATUS_ERROR        => 'エラー',
        ];
    }

    /**
     * ステータス → Bootstrap バッジ class のマッピング
     */
    public static function statusBadgeClasses(): array
    {
        return [
            self::STATUS_UNPROCESSED  => 'bg-secondary',
            self::STATUS_TRANSCRIBING => 'bg-primary',
            self::STATUS_TRANSCRIBED  => 'bg-secondary',
            self::STATUS_SUMMARIZING  => 'bg-primary',
            self::STATUS_COMPLETED    => 'bg-success',
            self::STATUS_ERROR        => 'bg-danger',
        ];
    }

    /**
     * ステータスの日本語表示
     */
    public function getStatusLabelAttribute(): string
    {
        return self::statusLabels()[$this->status] ?? '不明';
    }

    /**
     * ステータスに対応するバッジ class
     */
    public function getStatusBadgeClassAttribute(): string
    {
        return self::statusBadgeClasses()[$this->status] ?? 'bg-secondary';
    }

    /**
     * 音声時間のフォーマット表示（MM:SS または HH:MM:SS）
     */
    public function getFormattedDurationAttribute(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }

        $hours = intdiv($this->duration_seconds, 3600);
        $minutes = intdiv($this->duration_seconds % 3600, 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * ファイルサイズのフォーマット表示
     */
    public function getFormattedFileSizeAttribute(): ?string
    {
        if ($this->file_size === null) {
            return null;
        }

        if ($this->file_size >= 1024 * 1024) {
            return round($this->file_size / (1024 * 1024), 1) . ' MB';
        }

        return round($this->file_size / 1024, 1) . ' KB';
    }

    // --- スコープ ---

    /**
     * 要約済みファイルのみに絞り込む（トレーニング記録への取り込み用）
     */
    public function scopeWithSummary($query)
    {
        return $query->whereIn('status', [
            self::STATUS_COMPLETED,
        ])->whereNotNull('summary_text');
    }
}
