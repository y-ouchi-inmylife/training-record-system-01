<?php

namespace App\Http\Middleware;

use App\Support\SessionRoleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * リクエストの役割（トレーナー/クライアント）を判定し、
 * session.lifetime / session.cookie / session.domain を役割別に動的上書きする。
 *
 * StartSession より前に走る必要があるため、web(prepend) で登録する。
 * domain は role 側 config が null なら既存の session.domain（SESSION_DOMAIN）を維持。
 */
class ConfigureSessionByRole
{
    /**
     * config/session.php の掃除の確率（最初のリクエストで控える。config() で書き換えたあとの値は、同じプロセスの次のリクエストにも残るため）
     *
     * @var array{0: int, 1: int}|null
     */
    private static ?array $defaultLottery = null;

    public function handle(Request $request, Closure $next): Response
    {
        self::$defaultLottery ??= config('session.lottery');

        $role = SessionRoleResolver::isClient($request) ? 'client' : 'trainer';

        config([
            'session.lifetime' => (int) config("session_roles.{$role}.lifetime"),
            'session.cookie'   => config("session_roles.{$role}.cookie"),
            'session.domain'   => config("session_roles.{$role}.domain") ?? config('session.domain'),
            // 期限切れのセッションの掃除は、長いほうの役割のリクエストでだけ行う（2026-10-21 追加。設計書 architecture.md 2-7）。
            // 両方の役割で同じ sessions の表を使い、掃除はそのリクエストの側の長さで古い行を消すため、短いほう（トレーナー 12 時間）の
            // リクエストで掃除すると、12 時間使っていない会員様（30 日）のセッションまで消えてしまう
            'session.lottery' => self::isShortestRole($role) ? [0, 100] : self::$defaultLottery,
        ]);

        return $next($request);
    }

    /** ほかの役割より最後に使ってからの長さが短い役割か（短い役割のリクエストでは掃除しない） */
    private static function isShortestRole(string $role): bool
    {
        $lifetime = (int) config("session_roles.{$role}.lifetime");
        foreach (['trainer', 'client'] as $other) {
            if ($other !== $role && (int) config("session_roles.{$other}.lifetime") > $lifetime) {
                return true;
            }
        }

        return false;
    }
}
