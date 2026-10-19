<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * マイページ登録の案内（メールアドレス登録用 URL のトークン。DS-0700）の発行（2026-10-19 追加：会員詳細の［マイページ登録の案内を発行］
 * 〔ClientEmailRegistrationTokenController::store〕にあった処理を、愛犬アンケート・愛犬性格診断の案内の発行と一緒に使えるよう、
 * ここに移した。処理の中身は変えていない）。
 */
class ClientEmailRegistrationTokenService
{
    /**
     * 有効な（未使用で期限内の）案内があればそれを返し、なければ作って返す。2 つ目の値は新しく作ったか。
     *
     * 作るときは、未使用の期限切れの案内と未使用のログイン用リンクを物理削除してから、1 件作る（期限は設定値の日数後の日の終わり。
     * 期限切れからの発行し直しと同じ）。2 回の送信がほぼ同時に届いても有効な案内を 2 つ作らないよう、トランザクションの中で
     * 会員の行をロックしてから確かめる。呼び出し側がトランザクションの中にいれば、その中の入れ子のトランザクションとして動く。
     *
     * @return array{0: ClientEmailRegistrationToken, 1: bool}
     */
    public function activeOrIssue(Client $client, ?int $createdBy): array
    {
        return DB::transaction(function () use ($client, $createdBy) {
            Client::query()->whereKey($client->id)->lockForUpdate()->first();

            // 有効な案内がすでにあれば、何も作らず・消さない
            $active = $client->emailRegistrationTokens()
                ->where('is_used', false)
                ->unexpired()
                ->orderByDesc('id')
                ->first();
            if ($active !== null) {
                return [$active, false];
            }

            // 対象クライアントの未使用メールアドレス登録用トークン（期限切れ）を物理削除
            $client->emailRegistrationTokens()
                ->where('is_used', false)
                ->delete();

            // 対象クライアントの未使用ログイン用リンクも物理削除
            // 発行し直したときに送信済みログイン用リンクも無効化するため
            $client->loginLinkTokens()
                ->where('is_used', false)
                ->delete();

            // 新しいトークンを 1 件作成。有効期限は設定値の日数後の日の終わり（ハードコード禁止。2026-10-18 変更：日の終わりにした）
            $token = ClientEmailRegistrationToken::create([
                'token' => Str::random(32),
                'client_id' => $client->id,
                'expires_at' => ClientEmailRegistrationToken::expiresAtForNewToken(),
                'is_used' => false,
                'created_by' => $createdBy,
            ]);

            return [$token, true];
        });
    }
}
