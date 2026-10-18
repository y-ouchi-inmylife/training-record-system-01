<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use App\Support\InvitationMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * メールアドレス登録用 URL の発行コントローラ（トレーナー側の操作）
 *
 * S-0305 クライアント詳細画面から「マイページ登録の案内を発行」を
 * 実行したときの受け口。未発行時の発行と期限切れからの発行し直しを同じエンドポイントで扱う。
 * 発行するトークン自体（`client_email_registration_tokens.token` に基づく URL）
 * は設計書上「メールアドレス登録用 URL」と呼び、この呼称は内部の識別として維持する。
 *
 * 名前空間はルート直下（App\Http\Controllers）。Client\ サブディレクトリは
 * クライアント側（auth:client 保護下）用のため、ここには置かない。
 *
 * 設計書: api-design.md `POST /clients/{client}/email-registration-tokens`
 */
class ClientEmailRegistrationTokenController extends Controller
{
    /**
     * メールアドレス登録用 URL の発行処理
     *
     * 発行のあとは会員詳細（S-0305）に戻り、完了メッセージ（success）を出す（2026-10-17 変更）。
     * 案内シート（S-0307）は開かない。案内は必要なときに［マイページ登録の案内を表示］から開く
     * （ユーザーレビューで、発行のあとに自動で案内の画面を開く機能は要らないとわかったため。
     * その前は発行のあとに S-0307 にリダイレクトし、完了メッセージは載せなかった）。
     *
     * 有効な（未使用で期限内の）トークンがすでにあるとき（二重送信・古い画面から押し直したなど）は、
     * 新しく発行せず、完了メッセージも載せずに会員詳細に戻る（発行していないため）。
     * 期限切れの未使用トークンしかないときは、それを消して作り直す（期限切れからの発行し直し）。
     *
     * 2 回の送信がほぼ同時に届いても有効な案内を作り直さないよう、トランザクションの中で
     * 会員の行をロックしてから確かめる（愛犬アンケート案内の発行と同じ作り）。
     *
     * 設計書: requirements.md 6-3-6 備考「発行のあとの戻り先」
     */
    public function store(Client $client): RedirectResponse
    {
        $issued = DB::transaction(function () use ($client) {
            Client::query()->whereKey($client->id)->lockForUpdate()->first();

            // 有効な案内がすでにあれば、何も作らず・消さない
            $hasActive = $client->emailRegistrationTokens()
                ->where('is_used', false)
                ->unexpired()
                ->exists();
            if ($hasActive) {
                return false;
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
            ClientEmailRegistrationToken::create([
                'token' => Str::random(32),
                'client_id' => $client->id,
                'expires_at' => ClientEmailRegistrationToken::expiresAtForNewToken(),
                'is_used' => false,
                'created_by' => Auth::id(),
            ]);

            return true;
        });

        $redirect = redirect()->route('clients.show', $client);

        return $issued ? $redirect->with('success', 'マイページ登録の案内を発行しました。') : $redirect;
    }

    /**
     * 発行の URL を GET で開かれたとき（GET /clients/{client}/email-registration-tokens。2026-10-17 追加）。
     *
     * 古いタブ・ブラウザの読み込み直し・履歴などで発行の URL が GET で開かれても、405 のエラー画面を出さず、
     * 発行もせずに会員詳細（S-0305）に移す。権限はふつうの画面と同じ（auth・practitioners）。
     * 会員がなければルートモデルバインディングで 404。
     *
     * 設計書: api-design.md `GET /clients/{client}/email-registration-tokens`
     */
    public function redirectToClient(Client $client): RedirectResponse
    {
        return redirect()->route('clients.show', $client);
    }

    /**
     * マイページ登録案内の取消処理（6-3-7）
     *
     * 状態が「メールアドレス登録待ち（期限内）」のときだけ実行できる。未使用の
     * メールアドレス登録用トークンを物理削除し、発行前の状態（メールアドレスなし）
     * に戻す。クライアント情報とトレーニング記録には触らない。
     *
     * ログイン用リンク（`client_login_link_tokens`）はこの状態では通常存在しない
     * が、整合性のため未使用のものがあれば一緒に物理削除する（発行 6-3-6・削除
     * 6-3-8 と同じ扱い）。
     *
     * 設計書: api-design.md `DELETE /clients/{client}/email-registration-tokens`
     */
    public function destroy(Client $client): RedirectResponse
    {
        // 状態が「登録待ち（期限内）」であることを確認。
        // 派生値（latest_email_reg_expires_at / has_active_email_reg_token）を
        // 使う `getStatusAttribute` と `getShowExpiredNoteAttribute` を利用するため
        // loadStatusData() で先読みする。
        $client->loadStatusData();

        if ($client->status !== Client::STATUS_AWAITING_EMAIL || $client->show_expired_note) {
            // UI 側で取消ボタンをこの状態のときだけ表示しているため通常は到達しない。
            // 直接エンドポイントを叩かれた場合の防御として 409 を返す。
            abort(409, '登録待ち（期限内）のときだけ取り消せます');
        }

        DB::transaction(function () use ($client) {
            // 未使用のメールアドレス登録用トークンを物理削除
            $client->emailRegistrationTokens()
                ->where('is_used', false)
                ->delete();

            // ログイン用リンクの未使用トークンも整合性のため物理削除
            // （この状態では通常存在しないが、発行・削除処理と同じ扱いにする）
            $client->loginLinkTokens()
                ->where('is_used', false)
                ->delete();
        });

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'マイページ登録案内を取り消しました。');
    }

    /**
     * メールアドレス登録用 URL の印刷用ページ（S-0307）を表示する。
     *
     * 設計書 api-design.md `GET /clients/{client}/email-registration-tokens/print`。
     * お客様の氏名はビューに渡さない（決定事項 #3）。
     *
     * メール／LINE 送付用の案内文面もここで組み立てて view に渡す
     * （screen-design.md S-0307「案内文面のコピー」参照）。JavaScript
     * で画面上の文字を拾って組み立てる方式は取らない — 画面の見た目と
     * 文面を独立させ、印刷ページの文言変更が文面のフォーマットを壊さない
     * ようにするため。
     */
    public function print(Client $client): View
    {
        $token = $client->emailRegistrationTokens()
            ->where('is_used', false)
            ->unexpired()
            ->orderByDesc('id')
            ->first();

        // 有効なトークンがなければ案内画面を返す（同じビューを状態で切り替え）
        if (! $token) {
            return view('clients.email-registration-token-print', [
                'client' => $client,
                'url' => null,
                'expiresOn' => null,
                'copyText' => null,
            ]);
        }

        $url = route('client-portal.email-registration.show', ['token' => $token->token]);

        return view('clients.email-registration-token-print', [
            'client' => $client,
            'url' => $url,
            'expiresOn' => $token->expires_at,
            // LINE・メールで送る文面（画面に出す文とコピーする文は同じ。2026-10-18 変更：InvitationMessage に移した）
            'copyText' => InvitationMessage::mypage($client, $url, $token->expires_at),
        ]);
    }
}
