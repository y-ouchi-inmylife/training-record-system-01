<?php

namespace App\Mail;

use App\Models\ClientLoginLinkToken;
use App\Support\InvitationMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 会員ログイン用リンクメール（段階 4-1）
 *
 * 会員が S-1405 メールアドレス登録画面でメールアドレスを送信したときに、
 * 入力されたアドレスに対して送るメール。本文にはログイン用リンク（＝初回設定画面へ
 * 遷移するリンク。開くだけで client guard でログイン状態になる）と有効期限を含める。
 *
 * リンクの有効期限は、対応するメールアドレス登録用トークンの `expires_at` を
 * そのまま引き継ぐ（お客様がメールアドレスを登録した時点から数え直さない）。
 * 本文の有効期限は日付だけ（曜日つき。ご案内の文面と同じ InvitationMessage::expiresLabel。2026-10-18 変更：期限を
 * 日の終わりにしたため。その前は「2026年10月25日 10:00まで」と時刻まで出していた）。
 */
class ClientLoginLinkMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly ClientLoginLinkToken $token,
    ) {}

    public function envelope(): Envelope
    {
        return ClientMailEnvelope::build('マイページのご登録手続き');
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.client-login-link',
            with: [
                // ログイン用リンク（初回設定画面のトークン付き URL）。
                'loginUrl' => route('client-portal.setup.show', ['token' => $this->token->token]),
                'expiresLabel' => InvitationMessage::expiresLabel($this->token->expires_at),
            ],
        );
    }
}
