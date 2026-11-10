<?php

namespace App\Http\Controllers;

use App\Mail\ClientLoginLinkMail;
use App\Models\Client;
use Illuminate\Contracts\View\View;

/**
 * 初回設定メールの表示（S-0316。要件定義書 6-3-9。2026-11-10 追加）
 *
 * お客様に初回設定メール（S-1405 で入力したアドレスに送る、ログイン用リンク入りのメール。ClientLoginLinkMail）が
 * 届かないときに、トレーナーが自分のメールから同じ内容を送れるよう、送ったメールの宛先・件名・本文を出す。
 * システムからは送らない。
 *
 * - 読むだけ：未使用のログイン用リンク（client_login_link_tokens）のうち、いちばん新しい 1 件を読む。トークンの作成・更新・
 *   削除は一切しない（作り直すと、送信済みのメールのリンクが無効になるため）
 * - 件名・本文は、実際に送る Mailable（ClientLoginLinkMail）そのものから取る（件名は envelope() の件名、本文は render()）。
 *   件名の書式（ClientMailEnvelope）・テンプレート（mail.client-login-link）・差し込む値をこの画面のために別に持たない
 * - 表示の条件は Client::isAwaitingSetupWithinExpiry()（会員詳細の［初回設定メールを表示］の出し分けと同じ判定）。
 *   条件に合わない・トークンがないときは、S-0307 と同じく「ない」表示にする（404 にしない）
 * - アクセスログは記録しない（LogAccess の ACTION_MAP に足さない。マイページ登録の案内の発行・表示を記録していない方針にそろえる）
 *
 * 新しいコントローラにした理由：ルートの対象（ログイン用リンクのメール）が、ClientEmailRegistrationTokenController
 * （メールアドレス登録用 URL のトークン）・ClientEmailController（メールアドレス）とは別のものだから（1 つの対象に 1 つのコントローラ）。
 *
 * 設計書: api-design.md `GET /clients/{client}/login-link-mail`
 */
class ClientLoginLinkMailController extends Controller
{
    public function show(Client $client): View
    {
        // 状態の判定に要る派生値（登録用トークンの有無・期限）を埋める（会員詳細と同じ）
        $client->loadStatusData();

        $token = $client->isAwaitingSetupWithinExpiry()
            ? $client->loginLinkTokens()
                ->where('is_used', false)
                ->orderByDesc('id')
                ->first()
            : null;

        if (! $token) {
            return view('clients.login-link-mail-print', [
                'client' => $client,
                'to' => null,
                'subject' => null,
                'body' => null,
            ]);
        }

        // 送ったメールと同じ Mailable から件名・本文を取り出す（送信はしない）
        $mail = new ClientLoginLinkMail($token);

        return view('clients.login-link-mail-print', [
            'client' => $client,
            'to' => $client->email,
            'subject' => $mail->envelope()->subject,
            'body' => $mail->render(),
        ]);
    }
}
