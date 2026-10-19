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
        $client = $clientId === null ? null : Client::query()->find($clientId);
        if ($client === null || $client->password !== null || $client->email !== null) {
            return null;
        }
        $registration = ClientEmailRegistrationToken::query()
            ->where('client_id', $client->id)
            ->where('is_used', false)
            ->unexpired()
            ->orderByDesc('id')
            ->first();

        return $registration === null
            ? null
            : route('client-portal.email-registration.show', ['token' => $registration->token]);
    }
}
