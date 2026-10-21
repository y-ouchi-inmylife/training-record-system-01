<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * エラーページ（resources/views/errors/）を、会員様側とトレーナー側のどちらの見た目で出すかを決める（2026-10-21 追加。
 * 設計書 screen-design.md「エラーページの共通の作り」）。
 *
 * 1. 会員様側の URL（client-portal/…）か、会員様側のホスト（本番）なら会員様側（セッションの分け方 SessionRoleResolver::isClient と同じ）
 * 2. トレーナー側のホストが会員様側と分かれていて（本番）、そのホストならトレーナー側。ほかのホストなら会員様側
 * 3. ホストが分かれていない（開発）ときは、トレーナー側の画面のルートに当たっていればトレーナー側
 *    （会員様側の URL は 1. で決まっているので、当たったルートはトレーナー側のもの）
 * 4. どちらとも決められないとき（どのルートにも当たらない 404、ルートを決める前のメンテナンス中の 503 など）は会員様側
 *
 * ログインの状態・データベースは読まない（500・503 のときに、ここでまたエラーにならないように）
 */
class ErrorPageSide
{
    /** 会員様側の見た目 */
    public const CLIENT = 'client';

    /** トレーナー側の見た目 */
    public const TRAINER = 'trainer';

    public static function resolve(Request $request): string
    {
        if (SessionRoleResolver::isClient($request)) {
            return self::CLIENT;
        }

        $clientHost = (string) config('subdomain.client_host');
        $trainerHost = (string) config('subdomain.trainer_host');
        if ($trainerHost !== '' && $trainerHost !== $clientHost) {
            return $request->getHost() === $trainerHost ? self::TRAINER : self::CLIENT;
        }

        return $request->route() !== null ? self::TRAINER : self::CLIENT;
    }
}
