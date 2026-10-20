<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use App\Models\Trainee;

/**
 * 回答を終えた完了の画面（S-1414・S-1416）に出す、マイページ登録へのお誘い（2026-10-19 追加。要件定義書 6-16-18）。
 *
 * 答えた会員に有効なマイページ登録の案内（未使用で期限内）があれば、そのリンク（メールアドレスを入れる画面 S-1406）を返す。
 * 案内の発行のチェックボックスの結果に関係なく、会員詳細の［マイページ登録の案内を発行］で別に発行した場合も出す
 * （2026-10-19 変更：その前は、答えた案内に結び付けたマイページ登録の案内がまだ使えるときだけ）。
 * 完了の画面はトークンを持たないので、回答の送信のときにここで判定して、フラッシュで渡す。
 *
 * 2026-10-20 変更：愛犬アンケート・愛犬性格診断のご案内の文面（S-0311・S-0312・S-0314。InvitationMessage）にも、同じ判定で
 * マイページ登録のリンクを足すことにしたので、会員に有効な案内を返す activeToken() を切り出し、お誘いと文面の両方で使う（判定は 1 つ）
 */
class MypageRegistrationInvite
{
    /**
     * お誘いのリンク。出さないときは null：
     * - 会員に有効なマイページ登録の案内がない（未発行・期限切れ・使用済み・取消済み）
     * - 会員がもうマイページを使っている（利用中）・メールアドレスを入れて登録の途中（登録待ち（初回設定））
     * - 会員がログインして答えた（マイページから開いた。今の［マイページへ戻る］のまま）
     */
    public static function url(?int $traineeId): ?string
    {
        if ($traineeId === null || auth('client')->check()) {
            return null;
        }
        $clientId = Trainee::query()->whereKey($traineeId)->value('client_id');
        $registration = self::activeToken($clientId === null ? null : Client::query()->find($clientId));

        return $registration === null ? null : self::registrationUrl($registration);
    }

    /**
     * 会員に有効なマイページ登録の案内（未使用で期限内のうち、いちばん新しいもの）。ないときは null：
     * - 有効なマイページ登録の案内がない（未発行・期限切れ・使用済み・取消済み）
     * - 会員がもうマイページを使っている（利用中）・メールアドレスを入れて登録の途中（登録待ち（初回設定））
     * 完了の画面のお誘い（url）と、ご案内の文面のマイページ登録の段（InvitationMessage）で使う（2026-10-20 に url から切り出した）
     */
    public static function activeToken(?Client $client): ?ClientEmailRegistrationToken
    {
        if ($client === null || $client->password !== null || $client->email !== null) {
            return null;
        }

        return ClientEmailRegistrationToken::query()
            ->where('client_id', $client->id)
            ->where('is_used', false)
            ->unexpired()
            ->orderByDesc('id')
            ->first();
    }

    /** マイページ登録の案内のリンク（メールアドレスを入れる画面 S-1406。マイページ登録のご案内 S-0307 の文面のリンクと同じ） */
    public static function registrationUrl(ClientEmailRegistrationToken $registration): string
    {
        return route('client-portal.email-registration.show', ['token' => $registration->token]);
    }
}
