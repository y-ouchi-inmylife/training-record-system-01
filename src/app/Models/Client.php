<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * クライアントモデル
 *
 * 柱2（クライアント閲覧機能）向けに Authenticatable を継承。
 * トレーナー用の web guard とは別 guard で認証する前提のため、
 * config/auth.php での guard/provider 定義は塊C で行う。
 */
class Client extends Authenticatable
{
    /*
    |--------------------------------------------------------------------------
    | クライアント状態（段階 4-2）
    |--------------------------------------------------------------------------
    | 4 状態はカラムを持たず、email・password・メールアドレス登録用トークンの
    | 有無から導出する。判定順序と期限判定元は screen-design.md の
    | S-0305 状態一覧を唯一の正とする。
    */

    public const STATUS_IN_USE = 'in_use';                    // 利用中
    public const STATUS_AWAITING_SETUP = 'awaiting_setup';    // 初回設定待ち
    public const STATUS_AWAITING_EMAIL = 'awaiting_email';    // メールアドレス登録待ち
    public const STATUS_NO_EMAIL = 'no_email';                // メールアドレスなし

    use HasFactory;

    protected $fillable = [
        // 内部ID
        'internal_id',
        // カテゴリー1: 基本情報
        'initial_consultation_date', 'last_name', 'first_name',
        'last_name_kana', 'first_name_kana',
        'primary_trainer_id',
        // カテゴリー2: 連絡先
        'phone1', 'phone2', 'email',
        'postal_code', 'address1', 'address2', 'address3', 'address4',
        // クライアント閲覧機能（柱2）
        'password',
        // 最終更新者
        'updated_by',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'initial_consultation_date' => 'date',
            'password' => 'hashed',
        ];
    }

    /**
     * email の空文字を NULL に正規化する。
     *
     * clients.email には UNIQUE 制約があり、MySQL は NULL を重複扱いしないが
     * '' は普通の値として重複扱いする。フォーム未入力を '' で保存すると2件目で
     * UNIQUE 違反になるため、モデル層で '' → NULL に統一する。
     */
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = ($value === '' || $value === null) ? null : $value;
    }

    /**
     * 郵便番号を保存時に整形する。
     * 入力形式にかかわらず、数字7桁が抽出できれば「3桁-4桁」のハイフン区切りに統一する。
     * 空・null はそのまま null にする。7桁が抽出できない場合は入力値をそのまま保持
     * （バリデーションで7桁のみ許可しているため、通常ここには7桁の値のみ到達する）。
     */
    public function setPostalCodeAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['postal_code'] = null;
            return;
        }
        // 数字だけを抽出
        $digits = preg_replace('/[^0-9]/', '', $value);
        if (strlen($digits) === 7) {
            $this->attributes['postal_code'] = substr($digits, 0, 3) . '-' . substr($digits, 3);
        } else {
            // 7桁でない場合は入力値をそのまま保持（バリデーション通過後は通常到達しない）
            $this->attributes['postal_code'] = $value;
        }
    }

    /**
     * 氏名（フルネーム）
     */
    public function getFullNameAttribute(): string
    {
        return $this->last_name . ' ' . $this->first_name;
    }

    /**
     * 氏名かな（フルネーム）
     */
    public function getFullNameKanaAttribute(): string
    {
        $kana = trim(($this->last_name_kana ?? '') . ' ' . ($this->first_name_kana ?? ''));
        return $kana ?: '';
    }

    /**
     * 一覧表示用の氏名
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->full_name;
    }

    /**
     * 一覧表示用のかな
     */
    public function getDisplayNameKanaAttribute(): string
    {
        return $this->full_name_kana;
    }

    /**
     * 会員一覧（S-0304）のトレーニー列の表示文字列を組み立てる。
     *
     * 形式：「名前（犬種、性別）」。犬種・性別が両方あれば「、」で区切る。
     * 片方のみなら単独で括弧内に置く。両方未登録なら括弧ごと省略して名前だけ。
     * 複数頭は「／」で区切る（括弧内の「、」との入れ子を避けるため別記号を採る）。
     * 例：「アイ（トイプードル、女の子）／エマ（プードル、女の子）」／「モモ（ミックス）」／「ハナ」／（0 頭は空文字）
     *
     * N+1 を避けるため呼び出し側で `trainees` を eager load しておくこと
     * （`ClientController::index()` で `with(['primaryTrainer', 'trainees'])`）。
     * 性別の日本語化は `Trainee::sex_label` アクセサに委譲する。
     *
     * 詳細は screen-design.md S-0304 設計方針「『トレーニー』列を追加」参照。
     */
    public function getTraineesLabelAttribute(): string
    {
        return $this->trainees->map(function ($trainee) {
            // 犬種と性別（日本語ラベル）を空でないものだけに絞る。
            // 「アイ（—）」のような空の括弧を作らないため、未登録の要素は落とす。
            $parts = array_filter([$trainee->breed, $trainee->sex_label]);
            if (empty($parts)) {
                // 犬種も性別もなし → 括弧ごと省略して名前だけ
                return $trainee->name;
            }
            return $trainee->name . '（' . implode('、', $parts) . '）';
        })->implode('／');
    }

    /**
     * 主担当トレーナー
     */
    public function primaryTrainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'primary_trainer_id');
    }

    /**
     * トレーニング記録
     */
    public function trainingRecords(): HasMany
    {
        return $this->hasMany(TrainingRecord::class);
    }

    /**
     * トレーニー（D-0700）
     *
     * 並び順は登録順（`id` 昇順）。トレーニーは複数頭が稀で `display_order` を
     * 持たないため、登録順で足りる（設計書 requirements.md 6-16、db-schema.md D-0700 注記）。
     */
    public function trainees(): HasMany
    {
        return $this->hasMany(Trainee::class)->orderBy('id');
    }

    /**
     * 音声記録（録音・アップロード・テキスト貼り付けで作られた音声記録）。
     * 削除条件（requirements.md 6-3-5）の存在判定に使う。audio_records.client_id の
     * 外部キーは ON DELETE RESTRICT のため、音声記録が残っている会員は削除できない。
     */
    public function audioRecords(): HasMany
    {
        return $this->hasMany(AudioRecord::class);
    }

    /**
     * メールアドレス登録用トークン（DS-0700）
     */
    public function emailRegistrationTokens(): HasMany
    {
        return $this->hasMany(ClientEmailRegistrationToken::class);
    }

    /**
     * ログイン用リンクトークン（DS-0600）
     *
     * お客様がメールアドレスを登録すると当該アドレスに送信される、開くと
     * 自動ログインして初回設定画面へ遷移するリンクのトークン。
     */
    public function loginLinkTokens(): HasMany
    {
        return $this->hasMany(ClientLoginLinkToken::class);
    }

    /**
     * メールアドレス変更トークン（DS-0800）＝メールアドレス確認リンク
     *
     * 段階 4-3 で追加。ログイン中のクライアントがメールアドレスを変更する際、
     * 新しいアドレス宛に送る確認リンクのトークン。
     */
    public function emailChangeTokens(): HasMany
    {
        return $this->hasMany(ClientEmailChangeToken::class);
    }

    /**
     * パスワード再設定トークン（DS-0900）＝パスワード再設定リンク
     *
     * 段階 4-4 で追加。パスワードを忘れたお客様がログイン画面から申し込む
     * 再設定リンクのトークン。ログインさせる働きは持たない・メールアドレスも変えない。
     */
    public function passwordResetTokens(): HasMany
    {
        return $this->hasMany(ClientPasswordResetToken::class);
    }

    /**
     * 最終更新者（トレーナー）
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'updated_by');
    }

    /**
     * 状態判定のための派生値を、サブクエリでまとめて先読みするスコープ。
     *
     * - has_active_email_reg_token：未使用・期限内の登録用トークンが 1 件でもあれば 1、なければ NULL
     * - latest_email_reg_expires_at：最新の未使用トークンの expires_at（なければ NULL）
     *
     * これで一覧描画時に行ごとの追加クエリが発生しない（N+1 を避ける）。
     * 単体取得（詳細画面など）は本スコープの代わりに loadStatusData() でも同じ 2 値を埋められる。
     */
    public function scopeWithStatusData(Builder $query): Builder
    {
        return $query->addSelect([
            'has_active_email_reg_token' => ClientEmailRegistrationToken::selectRaw('1')
                ->whereColumn('client_id', 'clients.id')
                ->where('is_used', false)
                ->where('expires_at', '>', now())
                ->limit(1),
            'latest_email_reg_expires_at' => ClientEmailRegistrationToken::select('expires_at')
                ->whereColumn('client_id', 'clients.id')
                ->where('is_used', false)
                ->orderByDesc('id')
                ->limit(1),
        ]);
    }

    /**
     * 単体取得のインスタンスに、状態判定に必要な 2 値を埋め込む。
     * 一覧は withStatusData スコープを使うため、こちらは詳細画面等のワンショット用。
     * 再発行時に未使用トークンが物理削除される設計上、同時に存在する未使用トークンは
     * 常に高々 1 件のため、1 回のクエリで判定に必要な情報を取り切れる。
     */
    public function loadStatusData(): self
    {
        $token = $this->emailRegistrationTokens()
            ->where('is_used', false)
            ->orderByDesc('id')
            ->first();

        $this->setAttribute('latest_email_reg_expires_at', $token?->expires_at);
        $this->setAttribute(
            'has_active_email_reg_token',
            ($token && $token->expires_at > now()) ? 1 : null
        );

        // これらは DB カラムではない導出値。dirty 扱いにすると
        // 後続の update() で存在しないカラムへ書き込もうとしてエラーになるため、
        // original にも同期して「変更なし」の状態にする（scope 経由の hydrate と同じ扱い）。
        $this->syncOriginalAttribute('latest_email_reg_expires_at');
        $this->syncOriginalAttribute('has_active_email_reg_token');

        return $this;
    }

    /**
     * 4 状態のうち、どれに該当するかを返す。
     * 判定順序は screen-design.md §S-0305 状態一覧の定義に一致させる。
     */
    public function getStatusAttribute(): string
    {
        if ($this->password !== null) {
            return self::STATUS_IN_USE;
        }
        if ($this->email !== null) {
            return self::STATUS_AWAITING_SETUP;
        }
        // メールアドレス登録待ち: `is_used=false` の登録用トークンが 1 件以上ある
        // （期限内かどうかは問わない。期限切れの添え書きは isEmailRegTokenExpired 側で判定）
        if ($this->getAttribute('latest_email_reg_expires_at') !== null) {
            return self::STATUS_AWAITING_EMAIL;
        }
        return self::STATUS_NO_EMAIL;
    }

    /**
     * 「期限切れ」の添え書きを付けるべきか。
     * 対応する登録用トークンが 1 件も存在しないケースも、設計書に従い期限切れ扱い。
     */
    public function getShowExpiredNoteAttribute(): bool
    {
        if (! in_array($this->status, [self::STATUS_AWAITING_SETUP, self::STATUS_AWAITING_EMAIL], true)) {
            return false;
        }
        // 有効な（未使用・期限内の）トークンが 1 件でもあれば期限切れではない
        return ! (bool) ($this->getAttribute('has_active_email_reg_token') ?? false);
    }

    /**
     * 状態バッジの表示用情報（文言と Bootstrap クラス）。
     *
     * 配色は screen-design.md §2-5 の「クライアント状態バッジの 4 状態対応」に従う。
     * 一覧・詳細で同じ表示にするため、モデル側にひとつだけ置いてビューから参照する。
     */
    public function statusBadge(): array
    {
        $baseLabel = match ($this->status) {
            self::STATUS_IN_USE => '利用中',
            self::STATUS_AWAITING_SETUP => '初回設定待ち',
            self::STATUS_AWAITING_EMAIL => '登録待ち',
            self::STATUS_NO_EMAIL => '未登録',
        };

        $isExpired = $this->show_expired_note;
        $label = $isExpired ? "{$baseLabel}（期限切れ）" : $baseLabel;

        $class = match (true) {
            $this->status === self::STATUS_IN_USE => 'bg-success',
            $this->status === self::STATUS_NO_EMAIL => 'bg-secondary',
            $isExpired => 'bg-danger',
            default => 'bg-warning text-dark',
        };

        return ['label' => $label, 'class' => $class];
    }
}
