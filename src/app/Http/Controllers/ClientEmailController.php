<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * クライアントのメールアドレス削除コントローラ（段階 4-2）
 *
 * S-0305 クライアント詳細画面の上部の「マイページ登録を削除」ボタンを
 * 実行したときの受け口。
 * **メールアドレスが登録済み**のクライアントに対して実行可能で、「利用中」と
 * 「初回設定待ち（期限内・期限切れの両方）」の両状態を受け付ける。
 * メールアドレス（と、あればパスワード）を NULL に戻し、未使用のメールアドレス登録用
 * URL・ログイン用リンクを物理削除する。クライアント情報とトレーニング記録は保持する。
 *
 * 初回設定待ちを受け付ける狙いは、お客様が間違ったメールアドレスを登録した場合の
 * 行き止まりを解消するため（screen-design.md S-0305「操作ボタンの配置」参照）。
 *
 * 設計書: api-design.md `DELETE /clients/{client}/email`, 要件 6-3-8
 */
class ClientEmailController extends Controller
{
    /**
     * メールアドレス削除処理（DELETE /clients/{client}/email）
     */
    public function destroy(Client $client): RedirectResponse
    {
        // メールアドレスが登録済みでなければ実行しない（利用中／初回設定待ちの両方で真になる）。
        // UI 側でも同じ条件でボタン・モーダルを出しているため通常は到達しないが、
        // 直接呼ばれた場合の防御として 409 を返す。
        if ($client->email === null) {
            abort(409, 'メールアドレスが登録されている会員のみ削除できます');
        }

        DB::transaction(function () use ($client) {
            // password は 'hashed' cast だが、cast は null を素通しするため NULL を書ける。
            // 初回設定待ちの場合 password は既に NULL なので実質的に何も変わらない。
            $client->update([
                'email' => null,
                'password' => null,
            ]);

            // 未使用の登録用 URL・ログイン用リンクを物理削除
            $client->emailRegistrationTokens()
                ->where('is_used', false)
                ->delete();
            $client->loginLinkTokens()
                ->where('is_used', false)
                ->delete();
        });

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'メールアドレスを削除しました。');
    }
}
