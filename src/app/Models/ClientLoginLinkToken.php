<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * クライアントログイン用リンクトークンモデル（DS-0600）
 *
 * お客様がメールアドレスを登録した際、当該アドレスに送信するログイン用リンクの
 * トークン。リンクを開くとクライアントは自動ログインされ、初回設定画面（S-1403）に
 * 遷移する。
 *
 * 有効期限は対応するメールアドレス登録用トークン（DS-0700 client_email_registration_tokens）
 * の expires_at をそのまま引き継ぐ。全体の期限はメールアドレス登録用 URL の発行時に決まる
 * （設定値は architecture.md §3-1 参照）。
 */
class ClientLoginLinkToken extends Model
{
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

    public function isExpired(): bool
    {
        // 期限はメールアドレス登録用トークンから引き継ぐので、判定も同じものを使う（2026-10-18 変更）
        return ClientEmailRegistrationToken::isPastExpiry($this->expires_at);
    }

    public function isValid(): bool
    {
        return !$this->is_used && !$this->isExpired();
    }
}
