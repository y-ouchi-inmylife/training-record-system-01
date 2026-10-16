<?php

namespace App\Support;

use App\Models\Trainee;

/**
 * 受付アンケート・性格診断の回答完了の画面と使えない案内の画面に、［マイページへ戻る］を出すか（2026-10 追加。
 * 設計書 S-1413・S-1414・S-1415・S-1416）。
 *
 * 出すのは、会員の認証（guard 'client'）でログインしていて、その案内のトレーニーが自分（ログイン中の会員）の
 * トレーニーのときだけ。ログインしていない（アカウントがない方も含む）とき、ほかの会員としてログインしているとき、
 * トレーニーが分からない（存在しない URL・削除済み）ときは出さない。
 * 回答の画面はログインなしで使える作りのまま（ここは表示を決めるだけで、回答の可否には関わらない）
 */
class ClientMypageLink
{
    public static function shouldShow(?int $traineeId): bool
    {
        if ($traineeId === null || ! auth('client')->check()) {
            return false;
        }

        return Trainee::query()->whereKey($traineeId)->where('client_id', auth('client')->id())->exists();
    }
}
