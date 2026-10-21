<?php

namespace App\Support;

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Client\LogoutController as ClientLogoutController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ページの有効期限切れ（419。CSRF トークンの不一致）のとき、エラーページを出さずに元の画面へ戻す（2026-10-21 追加。
 * 設計書 screen-design.md「419（ページの有効期限切れ）のときの戻し方」・architecture.md 2-7）。
 * 2026-10-21 に本番で会員様が 419 のエラーページで迷ったため。タブを 2 つ開いていた・ログイン画面を長く開いたまま・ログアウト後の［戻る］などで起きる。
 *
 * bootstrap/app.php の withExceptions から、愛犬アンケート・愛犬性格診断の送信の専用の扱いのあとに呼ぶ（専用の扱いが先に効く）。
 * - ログアウト：ふだんのログアウトと同じ処理をして、それぞれのログイン画面へ
 * - ログイン：同じログイン画面へ。メールアドレス（トレーナーはログインID）は入れたまま、パスワードは消し、フォームの上（キー login）に案内
 * - そのほかのフォーム：元の画面へ戻し、入力は残す（パスワードの欄・_token は残さない）。案内は画面の上のフラッシュ（session('error')）。
 *   戻る画面がログインの要る画面なのにその側でログインしていなければ、ログインのあと戻れるようにして（url.intended）ログイン画面へ
 * - 扱わないとき（CSRF の不一致ではない 419・JSON を求める呼び出し）は null（Laravel の既定：JSON の 419・エラーページ S-0103）
 */
class SessionExpiredResponder
{
    public static function respond(HttpException $e, Request $request): ?RedirectResponse
    {
        if ($e->getStatusCode() !== 419 || ! $e->getPrevious() instanceof TokenMismatchException || $request->expectsJson()) {
            return null;
        }

        // ログアウト：ふだんのログアウトと同じ処理（案内も同じ：なし）
        if ($request->routeIs('logout')) {
            return app(LogoutController::class)->logout($request);
        }
        if ($request->routeIs('client-portal.logout')) {
            return app(ClientLogoutController::class)->logout($request);
        }

        // ログイン：同じログイン画面へ（トレーナーの POST /login のルートには名前がないので URL で見る）
        if ($request->isMethod('POST') && $request->is('client-portal/login')) {
            return redirect()->route('client-portal.login')
                ->withErrors(['login' => __('messages.session_expired.login')])
                ->withInput($request->only('email'));
        }
        if ($request->isMethod('POST') && $request->is('login')) {
            return redirect()->route('login')
                ->withErrors(['login' => __('messages.session_expired.login')])
                ->withInput($request->only('login_id'));
        }

        // そのほかのフォーム：元の画面へ
        [$url, $route] = self::backTarget($request);
        if ($url === null) {
            return self::toLogin($request, null);
        }
        $guard = self::requiredGuard($route);
        if ($guard !== null && ! auth($guard)->check()) {
            return self::toLogin($request, $url, $guard);
        }

        return redirect()->to($url)
            ->withInput(collect($request->except(['_token', '_method']))
                ->reject(fn ($value, $key) => Str::contains((string) $key, 'password'))
                ->all())
            ->with('error', __('messages.session_expired.form'));
    }

    /**
     * 戻り先の URL と、その URL に当たる GET のルート。送る前の画面（Referer）が同じサイトで GET で開ければそこ、
     * なければ送った URL（GET で開けるとき。初回設定・メールアドレス登録など、送る URL と画面の URL が同じもの）。どちらもなければ [null, null]
     *
     * @return array{0: string|null, 1: Route|null}
     */
    private static function backTarget(Request $request): array
    {
        foreach ([$request->headers->get('referer'), $request->fullUrl()] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }
            $parts = parse_url($candidate);
            if (! is_array($parts) || ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '')
                !== $request->getSchemeAndHttpHost()) {
                continue;
            }
            $route = self::getRoute($candidate);
            if ($route !== null) {
                return [$candidate, $route];
            }
        }

        return [null, null];
    }

    /** その URL を GET で開いたときに当たるルート（当たらなければ null） */
    private static function getRoute(string $url): ?Route
    {
        try {
            return app('router')->getRoutes()->match(Request::create($url, 'GET'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** ルートに付いたログインの決まり（auth → web、auth:client → client。なければ null） */
    private static function requiredGuard(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware === 'auth') {
                return 'web';
            }
            if (is_string($middleware) && str_starts_with($middleware, 'auth:')) {
                return explode(',', substr($middleware, 5))[0];
            }
        }

        return null;
    }

    /**
     * ログイン画面へ（戻り先がないとき・ログインが切れていたとき）。$intended があれば、ログインのあとそこへ戻る。
     * どちらのログイン画面かは、ログインの決まり（$guard）か、なければ ErrorPageSide で決める
     */
    private static function toLogin(Request $request, ?string $intended, ?string $guard = null): RedirectResponse
    {
        $isClient = $guard !== null ? $guard === 'client' : ErrorPageSide::resolve($request) === ErrorPageSide::CLIENT;
        if ($intended !== null) {
            $request->session()->put('url.intended', $intended);
        }

        return redirect()->route($isClient ? 'client-portal.login' : 'login')
            ->withErrors(['login' => __('messages.session_expired.login_required')]);
    }
}
