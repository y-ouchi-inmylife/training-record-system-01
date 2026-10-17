<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
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
     * 発行のあとは、同じタブで案内シート（S-0307）にリダイレクトする（2026-10 変更）。
     * 発行のあとの次の作業は案内を見せる・印刷する・文面をコピーすることなので、
     * 「マイページ登録の案内を表示」を押し直す手間をなくす。完了メッセージは載せない
     * （S-0307 は印刷用の画面のため。S-0307 が開くこと自体が発行できたことの知らせになる）。
     * S-0307 の［戻る］は会員詳細へのリンクなので、戻ると会員詳細が新しい状態で開く（2026-10-17 変更：
     * 別のタブで開いて［閉じる］で閉じる形をやめ、同じタブに戻した）。
     *
     * 有効な（未使用で期限内の）トークンがすでにあるとき（二重送信・ほかのタブで発行済みなど）は、
     * 新しく発行せず、今ある案内の S-0307 を開く（お客様に渡すものは同じため）。
     * 期限切れの未使用トークンしかないときは、それを消して作り直す（期限切れからの発行し直し）。
     *
     * 2 回の送信がほぼ同時に届いても有効な案内を作り直さないよう、トランザクションの中で
     * 会員の行をロックしてから確かめる（受付アンケート案内の発行と同じ作り）。
     *
     * 設計書: requirements.md 6-3-6 備考「発行のあとに案内の画面を開く理由」
     */
    public function store(Client $client): RedirectResponse
    {
        DB::transaction(function () use ($client) {
            Client::query()->whereKey($client->id)->lockForUpdate()->first();

            // 有効な案内がすでにあれば、何も作らず・消さずにその案内を開く
            $hasActive = $client->emailRegistrationTokens()
                ->where('is_used', false)
                ->where('expires_at', '>', now())
                ->exists();
            if ($hasActive) {
                return;
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

            // 新しいトークンを 1 件作成。有効期限は設定値から算出（ハードコード禁止）
            ClientEmailRegistrationToken::create([
                'token' => Str::random(32),
                'client_id' => $client->id,
                'expires_at' => now()->addDays(
                    (int) config('client_tokens.email_registration_expires_days')
                ),
                'is_used' => false,
                'created_by' => Auth::id(),
            ]);
        });

        return redirect()->route('client-email-registration-tokens.print', $client);
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
            ->where('expires_at', '>', now())
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
            'copyText' => $this->buildCopyText($url, $token->expires_at),
        ]);
    }

    /**
     * メール／LINE 送付用の案内文面を組み立てる。
     *
     * 文面と決め事は screen-design.md S-0307「案内文面のコピー」を参照。
     * 主な決め事：
     * - QR コードに一切触れない（URL を開く前提の手順にする）
     * - 挨拶を入れない（トレーナーが前後に自由に添えるため）
     * - 件名を入れない（メールと LINE で共用するため）
     * - 【】で見出しを付ける（LINE では改行だけだと段落の区切りが分かりにくい）
     * - 事業者名は config('app.client_portal_company') から取り、
     *   未設定なら行ごと出さない（余計な空行を残さない）
     * - 有効期限の表記は印刷ページの表示と同じ形式
     *
     * 文面を直すときは、上の設計書と本メソッドを同時に更新すること。
     */
    private function buildCopyText(string $url, Carbon $expiresOn): string
    {
        $company = config('app.client_portal_company');
        // 有効期限は印刷ページの表示と揃える（例：「2026年9月17日まで」）
        $expiryLine = $expiresOn->format('Y年n月j日').'まで';

        $lines = [
            'マイページのご登録は、下の URL からお願いします。',
            '',
            $url,
            '',
            '【ご登録の手順】',
            '1. 上の URL を開きます',
            '2. 開いたページでメールアドレスを入力します',
            '3. 届いたメールのリンクを開いて、パスワードとご住所・お電話番号などを設定します',
            '',
            '【有効期限】',
            $expiryLine,
        ];

        // 事業者名が空のときは、直前の空行を含めて丸ごと出さない
        if (! empty($company)) {
            $lines[] = '';
            $lines[] = $company;
        }

        return implode("\n", $lines);
    }
}
