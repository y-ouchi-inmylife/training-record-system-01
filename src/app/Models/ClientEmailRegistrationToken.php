<?php

namespace App\Models;

use App\Models\Concerns\ExpiresAtEndOfDay;
use Illuminate\Database\Eloquent\Model;

/**
 * クライアントメールアドレス登録トークンモデル（DS-0700）
 *
 * トレーナーが発行する「メールアドレス登録用 URL」のトークン。
 * クライアントはこの URL からメールアドレスを登録し、当該アドレスに
 * ログイン用リンク（DS-0600）が送信される。
 *
 * 有効期限は発行した日から config('client_tokens.email_registration_expires_days') 日後の日の終わり
 * （23:59:59。アプリの時間帯＝日本時間）。2026-10-18 変更：ご案内の文面には日付だけ（「2026年10月25日（日）まで」）を
 * 出すので、その日のうちは使えるようにそろえた（その前は発行した時刻の N 日後の同じ時刻で切れていた）。
 * この期限が「初回設定完了までの全体の期限」となり、ログイン用リンク（DS-0600）は
 * この期限をそのまま引き継ぐ（お客様の操作で期限は延びない設計）。
 *
 * is_used は「初回設定が完了した時点」で true に更新する。
 * メールアドレス登録単独では使用済みにしない（何度でも入力し直せるようにするため）。
 */
class ClientEmailRegistrationToken extends Model
{
    use ExpiresAtEndOfDay;

    protected $fillable = [
        'token',
        'client_id',
        'expires_at',
        'is_used',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function creator()
    {
        return $this->belongsTo(Trainer::class, 'created_by');
    }

    /** 有効日数のキー（期限の決め方・判定・「期限内だけ」の絞り込みは trait ExpiresAtEndOfDay。2026-10-19 に共通にした） */
    protected static function expiresDaysConfigKey(): string
    {
        return 'client_tokens.email_registration_expires_days';
    }

    public function isValid(): bool
    {
        return !$this->is_used && !$this->isExpired();
    }
}
