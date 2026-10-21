<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 画面をブラウザに残さない（Cache-Control: no-store, private。2026-10-21 追加。設計書 screen-design.md「ブラウザに画面を残さない（no-store）」・
 * architecture.md 2-7）。ログアウトのあとにブラウザの［戻る］を押すと、ログイン中の古い画面が入力ごとそのまま出たため
 * （共用の端末や家族のスマホで、会員様・愛犬の情報が見えないようにする。古い画面から送って 419 になることも減らす）。
 *
 * web のグループに足す（bootstrap/app.php）。ログインの要る画面・ログイン画面・会員様側の公開の画面・回答の画面・エラーページ・
 * JSON の応答のすべてに付く（どれも個人情報か、URL のトークンか、入力中の内容を含むため、分けずにまとめて付ける）。
 * 付けないもの：
 * - ファイルを返すレスポンス（BinaryFileResponse・StreamedResponse）：音声の再生（AudioRecordController::play）は、ブラウザが
 *   Range のリクエストで少しずつ取り直す。no-store だと取り直すたびに最初から読み直すことがあるため、今のまま（Symfony の既定）にする
 * - Cache-Control を意図して付けたレスポンス：既定（Symfony が付ける "no-cache, private"）と違う値なら上書きしない（2026-10-21 の時点ではない）
 * 届かないもの（個人情報を出さないので対象外）：どのルートにも当たらない 404（web のグループが動かない）。
 * auth のミドルウェアより外側で動かす（bootstrap/app.php の prependToPriorityList）ので、ログインしていないときのログイン画面への
 * リダイレクトや JSON の 401 にも付く
 */
class PreventPageCache
{
    /** Symfony が Cache-Control を付けていないレスポンスに付ける既定の値 */
    private const DEFAULT_CACHE_CONTROL = 'no-cache, private';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }
        if ($response->headers->get('Cache-Control') !== self::DEFAULT_CACHE_CONTROL) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
