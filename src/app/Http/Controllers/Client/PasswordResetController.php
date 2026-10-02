<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPasswordResetCompleteRequest;
use App\Http\Requests\ClientPasswordResetLinkRequest;
use App\Mail\ClientPasswordResetMail;
use App\Models\Client;
use App\Models\ClientPasswordResetToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * クライアントパスワード再設定コントローラ（段階 4-4）
 *
 * 認証不要の公開画面（`auth:client` の外）。
 *  - 申し込み画面（S-1407）：メールアドレスを入力して再設定リンクを申し込む
 *  - 再設定画面（S-1408）：届いたリンクから新しいパスワードを設定する
 *
 * 設計書:
 *   - api-design.md `GET / POST /client-portal/password-reset`
 *   - api-design.md `GET / POST /client-portal/password-reset/{token}`
 */
class PasswordResetController extends Controller
{
    /**
     * 申し込み画面を表示（GET /client-portal/password-reset）
     */
    public function showRequestForm(): View
    {
        return view('client.password-reset.request');
    }

    /**
     * 申し込みを受け付ける（POST /client-portal/password-reset）
     *
     * 該当する「利用中」のお客様がいる場合のみ、パスワード再設定リンクを送信する。
     * 該当しない場合（未登録・利用中でない）も**同じ画面・同じ文言を返す**
     * （第三者に登録の有無を露呈させないため。設計書 6-15-12）。
     */
    public function sendResetLink(ClientPasswordResetLinkRequest $request): View
    {
        $email = $request->validated()['email'];

        $client = Client::where('email', $email)->first();

        // 該当し、状態が「利用中」の場合のみ発行・送信する。
        // 状態判定は段階 4-2 で Client モデルに定義したものを使う（判定を書き直さない）。
        if ($client) {
            $client->loadStatusData();
            if ($client->status === Client::STATUS_IN_USE) {
                // 検証は FormRequest で終わっている。トランザクション内の例外のみ catch する。
                // **登録の有無を画面に出さないため、送信の失敗も画面に出さない**（§2-7 の例外）。
                // ログだけ残して、成功時と同じ完了状態を返す（下記）。もし 500 や失敗の文言を
                // 画面に出すと、「500 が出るかどうか」で登録の有無が分かってしまう（決定事項 #2・
                // 6-15-12 の備考。段階 4-3 追補）。
                try {
                    DB::transaction(function () use ($client) {
                        // 同時に有効な再設定リンクは 1 本のみ（決定事項 #4）：
                        // 未使用のトークンを物理削除してから新規発行する。
                        $client->passwordResetTokens()
                            ->where('is_used', false)
                            ->delete();

                        $token = ClientPasswordResetToken::create([
                            'token' => Str::random(32),
                            'client_id' => $client->id,
                            'expires_at' => now()->addDays(
                                (int) config('client_tokens.password_reset_expires_days')
                            ),
                            'is_used' => false,
                        ]);

                        // 登録アドレス宛に再設定リンクを送信。失敗時は全ロールバック
                        Mail::to($client->email)->send(new ClientPasswordResetMail($token));
                    });
                } catch (\Throwable $e) {
                    // 全ロールバック済み。メールアドレスは個人情報のためログに残さず、
                    // client_id で追えるようにする（初回設定の受け止め方に揃える）。
                    Log::error('[ClientPasswordResetMail] 再設定リンクの送信に失敗し、申込みを全ロールバックしました: ' . $e->getMessage(), [
                        'client_id' => $client->id,
                        'exception' => $e,
                    ]);
                }
            }
        }

        // 該当しても・しなくても、送信が成功しても失敗しても、同じ完了状態を返す
        // （登録の有無を露呈させないため）。会員はメールが届かなければ、もう一度申請できる。
        return view('client.password-reset.request', [
            'submitted' => true,
        ]);
    }

    /**
     * 再設定画面を表示（GET /client-portal/password-reset/{token}）
     *
     * トークンを検証し、有効なら新しいパスワード入力フォームを表示する。
     * リンクを開いた時点ではお客様をログインさせない（決定事項 #7 / 6-15-12 の備考）。
     */
    public function showResetForm(string $token): View
    {
        $tokenRecord = ClientPasswordResetToken::where('token', $token)->first();

        if (!$tokenRecord) {
            return $this->invalidTokenView(
                'このURLは無効です',
                'URLが間違っているか、削除された可能性があります。担当のトレーナーにお問い合わせください。'
            );
        }
        if ($tokenRecord->isExpired()) {
            return $this->invalidTokenView(
                'このURLは期限切れです',
                'このURLの有効期限が切れています。ログイン画面から改めてお申し込みください。'
            );
        }
        if ($tokenRecord->is_used) {
            return $this->invalidTokenView(
                'このURLは既に使用されています',
                'このURLでは既にパスワードの再設定が完了しています。ログイン画面からログインしてください。'
            );
        }

        return view('client.password-reset.reset', [
            'token' => $token,
        ]);
    }

    /**
     * 新しいパスワードを保存（POST /client-portal/password-reset/{token}）
     *
     * トークンを再検証し、パスワードを更新して使用済みにする。
     * **ログアウトさせない**（決定事項 #7）。ログイン中だった場合もログイン状態は
     * 維持する。**完了の通知メールは送らない**（決定事項 #6 / 6-15-12 の備考）。
     */
    public function resetPassword(ClientPasswordResetCompleteRequest $request, string $token): View|RedirectResponse
    {
        $tokenRecord = ClientPasswordResetToken::where('token', $token)->first();

        // トークン再検証（レース対策）
        if (!$tokenRecord || $tokenRecord->isExpired() || $tokenRecord->is_used) {
            return $this->invalidTokenView(
                'このURLは無効です',
                'URLが無効か、既に使用されています。ログイン画面からお進みください。'
            );
        }

        DB::transaction(function () use ($tokenRecord, $request) {
            $tokenRecord->client->update([
                'password' => $request->validated()['new_password'],
            ]);
            $tokenRecord->update(['is_used' => true]);
        });

        // 認証状態は変えない。ログイン中だった場合もそのまま維持する。
        // 未ログインの場合はログイン画面へ、ログイン中の場合もログイン画面に遷移するが
        // guest:client により automatically ダッシュボードにリダイレクトされる。
        return redirect()
            ->route('client-portal.login')
            ->with('success', 'パスワードを再設定しました。新しいパスワードでログインしてください。');
    }

    private function invalidTokenView(string $title, string $message): View
    {
        return view('client.setup.invalid-token', [
            'title' => $title,
            'message' => $message,
        ]);
    }
}
