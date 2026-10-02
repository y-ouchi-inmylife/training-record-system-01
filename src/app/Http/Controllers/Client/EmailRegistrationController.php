<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Mail\ClientLoginLinkMail;
use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use App\Models\ClientLoginLinkToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * クライアントメールアドレス登録コントローラ（段階 4-1）
 *
 * トレーナーから受け取ったメールアドレス登録用 URL からアクセスする、認証不要の公開画面。
 * 入力されたメールアドレスにログイン用リンクを送信する。
 *
 * 設計書: api-design.md `GET / POST /client-portal/email-registration/{token}`
 */
class EmailRegistrationController extends Controller
{
    /**
     * トークンを検証し、メールアドレス登録画面を表示（GET）
     */
    public function showByToken(string $token): View
    {
        $tokenRecord = ClientEmailRegistrationToken::where('token', $token)->first();

        // 存在する／期限内／未使用 の順にチェック
        if (!$tokenRecord) {
            return $this->invalidTokenView(
                'このURLは無効です',
                'URLが間違っているか、削除された可能性があります。担当のトレーナーにお問い合わせください。'
            );
        }
        if ($tokenRecord->isExpired()) {
            return $this->invalidTokenView(
                'このURLは期限切れです',
                'このURLの有効期限が切れています。担当のトレーナーにお問い合わせください。改めて発行してもらいます。'
            );
        }
        if ($tokenRecord->is_used) {
            return $this->invalidTokenView(
                'このURLは既に使用されています',
                'このURLでは既に初回設定が完了しています。ログイン画面からログインしてください。'
            );
        }

        return view('client.email-registration.index', [
            'token' => $token,
            'submittedEmail' => null,
        ]);
    }

    /**
     * メールアドレスを登録し、ログイン用リンクを送信する（POST）
     */
    public function storeByToken(Request $request, string $token): View|RedirectResponse
    {
        // トークン再検証（レース対策）
        $tokenRecord = ClientEmailRegistrationToken::where('token', $token)->first();
        if (!$tokenRecord || $tokenRecord->isExpired() || $tokenRecord->is_used) {
            return $this->invalidTokenView(
                'このURLは無効です',
                'URLが無効か、既に使用されています。担当のトレーナーにお問い合わせください。'
            );
        }

        // バリデーション：他クライアントで使用中のアドレスは重複エラー
        // 自クライアントは対象外（同じアドレスの再入力も許容する）
        // 文言は lang/ja/validation.php に集約（設計書 §2-8。重複は
        // custom.email.unique「このメールアドレスは登録できません。」で、
        // 他人の登録の有無を分からせない例外）。
        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                Rule::unique('clients', 'email')->ignore($tokenRecord->client_id),
            ],
        ]);

        try {
            DB::transaction(function () use ($tokenRecord, $validated) {
                // clients.email を更新
                $tokenRecord->client->update([
                    'email' => $validated['email'],
                ]);

                // 未使用のログイン用リンク（DS-0600）を物理削除
                // 入力し直しの場合、前のリンクを無効化するため
                ClientLoginLinkToken::where('client_id', $tokenRecord->client_id)
                    ->where('is_used', false)
                    ->delete();

                // 新しいログイン用リンクを作成。
                // expires_at はメールアドレス登録用トークンをそのまま引き継ぐ（設計書）。
                $loginLink = ClientLoginLinkToken::create([
                    'token' => Str::random(32),
                    'client_id' => $tokenRecord->client_id,
                    'expires_at' => $tokenRecord->expires_at,
                    'is_used' => false,
                    'created_by' => $tokenRecord->created_by,
                ]);

                // メール送信は同トランザクション内で行い、失敗時は全ロールバック
                Mail::to($validated['email'])->send(new ClientLoginLinkMail($loginLink));
            });
        } catch (\Throwable $e) {
            // 全ロールバック済み。エラーメッセージを添えて入力状態に戻す
            return back()
                ->withInput()
                ->withErrors(['email' => 'メールの送信に失敗しました。時間を置いて再度お試しください。']);
        }

        // 完了状態：同画面に送信済みメールアドレスを表示
        return view('client.email-registration.index', [
            'token' => $token,
            'submittedEmail' => $validated['email'],
        ]);
    }

    private function invalidTokenView(string $title, string $message): View
    {
        // 段階 4-1 コミット 4 で `client/setup/invalid-token.blade.php` に移設済み。
        return view('client.setup.invalid-token', [
            'title' => $title,
            'message' => $message,
        ]);
    }
}
