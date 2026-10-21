<?php

namespace App\Http\Middleware;

use App\Support\SessionExpiredResponder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * トレーナーが、ログインしてから一定の時間（session_roles.trainer.absolute_lifetime。既定 720 分＝12 時間）を過ぎたらログアウトさせる
 * （2026-10-21 追加。設計書 requirements.md 11-2-3・architecture.md 2-7）。1 日 1 回はログインし直すため（残業を考えて 12 時間）。
 * セッションの長さ（session_roles.trainer.lifetime）は「最後に使ってから」数えるので、使い続けていると切れない。そのため、ログインした時刻から
 * 数えて切る仕組みを別に持つ。
 *
 * - ログインした時刻は、ログインしたとき（Auth\LoginController）にセッションの SESSION_KEY に入れる。セッションごとなので、端末ごとに
 *   その端末でログインした時刻から数える（ほかの端末でログインしても変わらない）
 * - 時刻がないセッション（この仕組みを入れる前からログインしている人・テストの actingAs）は、その時点の時刻を入れて数え始める
 *   （いきなりログアウトさせない）
 * - 過ぎていたら、ログアウトしてセッションを作り直し、ログイン画面のフォームの上（キー login）に案内を出す。ログインのあとは元の画面へ戻る
 *   （url.intended。GET はその URL、フォームを送ったときは送る前の画面〔SessionExpiredResponder::backUrl〕。入力は残さない）
 * - JSON を求める呼び出しは 401（{"message":"Unauthenticated."}。auth のミドルウェアと同じ）。録音の送信は、401 を受けて一時保存とログインの案内に進む
 *
 * トレーナー側のログインの要るルート（routes/web.php の auth のグループ・routes/api.php）に、auth のあとで付ける（別名 session.absolute）
 */
class EnforceAbsoluteSessionLifetime
{
    /** ログインした時刻（UNIX 時刻）を入れるセッションのキー */
    public const SESSION_KEY = 'trainer_logged_in_at';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = auth('web');
        if (! $guard->check()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();
        $loggedInAt = $session->get(self::SESSION_KEY);
        if (! is_int($loggedInAt)) {
            $session->put(self::SESSION_KEY, $now);

            return $next($request);
        }

        $limitSeconds = (int) config('session_roles.trainer.absolute_lifetime') * 60;
        if ($limitSeconds <= 0 || $now - $loggedInAt <= $limitSeconds) {
            return $next($request);
        }

        // 過ぎていた：戻り先を決めてから、ログアウトしてセッションを作り直す
        $intended = $request->isMethod('GET') ? $request->fullUrl() : SessionExpiredResponder::backUrl($request);
        $guard->logout();
        $session->invalidate();
        $session->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($intended !== null) {
            $session->put('url.intended', $intended);
        }

        return redirect()->route('login')->withErrors(['login' => __('messages.session_expired.absolute')]);
    }
}
