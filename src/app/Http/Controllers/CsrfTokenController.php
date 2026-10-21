<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CSRF トークンの取り直しの口（GET /csrf-token。2026-10-21 追加。設計書 architecture.md 2-7「トークンの取り直し」）。
 *
 * 画面の JS が送信でページの有効期限切れ（419）を受けたとき、ここから今のセッションのトークンを取り直して送り直す
 * （resources/js/csrf-token.js）。今使っているのは録音の送信（録音実行 S-0502）。録音はブラウザのメモリにしかなく、
 * 画面を読み込み直してトークンを替えると録音が消えるため。
 *
 * - トレーナー側のログインの要るグループに置く。ログインが切れていれば auth のミドルウェアが JSON の 401 を返す（画面の JS はログインの案内を出す）
 * - user_id はログイン中のトレーナーの ID。別のタブで別の人がログインし直していたら、録音したトレーナーと違うので送らない（画面の JS で比べる）
 * - Cache-Control: no-store は PreventPageCache（web のグループ）で付く
 */
class CsrfTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'token' => $request->session()->token(),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);
    }
}
