<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientEmailChangeRequest;
use App\Http\Requests\ClientPasswordChangeRequest;
use App\Http\Requests\ClientProfileRequest;
use App\Mail\ClientEmailChangeConfirmMail;
use App\Mail\ClientPasswordChangedMail;
use App\Models\ClientEmailChangeToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * クライアント登録情報コントローラ（S-1406 / S-1409 / S-1410 / S-1411）
 *
 * ログイン中のクライアントが、登録内容を確認・変更する画面群を扱う。
 * S-1406 は確認画面（表示のみ）で、そこから 3 つの変更画面へ枝分かれする：
 *   - S-1411 登録情報の変更（連絡先）
 *   - S-1409 メールアドレスの変更
 *   - S-1410 パスワードの変更
 * 各変更画面は 1 画面 1 フォームで、送信後は同画面自身に戻して完了メッセージ
 * を画面上部に表示する（`session('success')` を layouts.client の共通受け皿
 * が拾って描画する）。
 *
 * 設計書: api-design.md `GET /client-portal/profile`,
 *          `GET /client-portal/profile/edit`,
 *          `PUT /client-portal/profile`,
 *          `GET/POST /client-portal/settings/email`,
 *          `GET/PUT /client-portal/settings/password`
 */
class SettingsController extends Controller
{
    /**
     * 登録情報の確認画面を表示（GET /client-portal/profile、S-1406）
     *
     * 登録内容の一覧と、3 つの変更画面（S-1411/S-1410/S-1409）への入口を配置する。
     */
    public function show(): View
    {
        return view('client.settings.show', [
            'client' => Auth::guard('client')->user(),
        ]);
    }

    /**
     * 登録情報の変更フォームを表示（GET /client-portal/profile/edit、S-1411）
     */
    public function edit(): View
    {
        return view('client.settings.edit', [
            'client' => Auth::guard('client')->user(),
        ]);
    }

    /**
     * メールアドレス変更画面を表示（GET /client-portal/settings/email、S-1409）
     *
     * この画面は「新しいメールアドレス」「現在のパスワード」の 2 入力だけを扱い、
     * 現在のメールアドレスは view で参照しないため、$client は渡さない（設計書
     * S-1409 参照：「変更できる項目の現在値」の再掲は S-1406 でお客様が見た直後
     * のためここでは省く）。認可は auth:client ミドルウェアが担う。
     */
    public function editEmail(): View
    {
        return view('client.settings.email');
    }

    /**
     * パスワード変更画面を表示（GET /client-portal/settings/password、S-1410）
     */
    public function editPassword(): View
    {
        return view('client.settings.password', [
            'client' => Auth::guard('client')->user(),
        ]);
    }

    /**
     * 連絡先を更新（PUT /client-portal/profile、S-1411 の送信先）
     *
     * 氏名・メールアドレス・パスワードには一切触れない。
     * 送信後は同画面（S-1411）自身に戻して完了メッセージを表示する。
     * 完了メッセージは目的語を付けた「登録情報を変更しました。」の形。
     * 一方、ボタン文言は目的語を落とした「変更する」で、S-1406 の入口ボタン
     * 「登録情報を変更」との衝突を避けている（設計書 S-1411 備考参照）。
     */
    public function updateProfile(ClientProfileRequest $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        $client->update($request->validated());

        return redirect()
            ->route('client-portal.profile.edit')
            ->with('success', '登録情報を変更しました。');
    }

    /**
     * パスワードを変更（PUT /client-portal/settings/password）
     *
     * ログアウトさせない（決定事項 #4）。登録アドレスに通知メールを送る。
     * メール送信失敗時は全ロールバックし、パスワードの更新も無効化する。
     */
    public function updatePassword(ClientPasswordChangeRequest $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        // 変更が行われた時刻。通知メールに載せて、身に覚えがあるかをお客様が
        // 判断できるようにする（設計書 client-portal-design-plan.md §6-2）
        $changedAt = now();

        // 検証はこの時点で終わっている（ClientPasswordChangeRequest）。トランザクション内の
        // 例外のみ catch する（検証の失敗は FormRequest 側で先に落ちる位置関係）。
        try {
            DB::transaction(function () use ($client, $request, $changedAt) {
                $client->update([
                    'password' => $request->validated()['new_password'],
                ]);
                // 登録アドレスに通知メール。失敗時は全ロールバック（**パスワードも巻き戻る**）
                Mail::to($client->email)->send(new ClientPasswordChangedMail($changedAt));
            });
        } catch (\Throwable $e) {
            // 全ロールバック済み（パスワードは変わっていない）。送信の失敗が会員側からは
            // 「変更できたのか分からない」状態になるため、原因追跡のためのログを残す。
            // パスワードそのものはログに残さず、client_id で追えるようにする（初回設定の
            // 受け止め方に揃える）。
            Log::error('[ClientPasswordChangedMail] 通知メールの送信に失敗し、パスワード変更を全ロールバックしました: ' . $e->getMessage(), [
                'client_id' => $client->id,
                'exception' => $e,
            ]);

            // 入力エラー以外の失敗はフォームの上に出す（設計書 §2-7）。文言は「変わっていない」
            // ことが伝わる形にする（会員が「パスワードが変わったのか変わっていないのか」で迷わないため）。
            return back()
                ->withInput()
                ->withErrors(['form' => 'パスワードを変更できませんでした。時間を置いて再度お試しください。']);
        }

        return redirect()
            ->route('client-portal.settings.password.edit')
            ->with('success', 'パスワードを変更しました。');
    }

    /**
     * メールアドレス変更の申し込み（POST /client-portal/settings/email）
     *
     * 新しいアドレス宛にメールアドレス確認リンクを送信する。
     * この時点では clients.email を書き換えない（決定事項 6-15-10）。
     * 同時に有効な確認リンクは 1 本のみ（決定事項 #6）：未使用の既存トークンを物理削除する。
     */
    public function requestEmailChange(ClientEmailChangeRequest $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        $newEmail = $request->validated()['new_email'];

        // 検証はこの時点で終わっている（ClientEmailChangeRequest）。トランザクション内の
        // 例外のみ catch する（検証の失敗は FormRequest 側で先に落ちる位置関係）。
        try {
            DB::transaction(function () use ($client, $newEmail) {
                // それまで送信済みだった未使用の確認リンクを無効化（物理削除）
                $client->emailChangeTokens()
                    ->where('is_used', false)
                    ->delete();

                // 新しい確認リンクを 1 件作成。期限は独立（他トークンとは無関係）
                $token = ClientEmailChangeToken::create([
                    'token' => Str::random(32),
                    'client_id' => $client->id,
                    'new_email' => $newEmail,
                    'expires_at' => now()->addDays(
                        (int) config('client_tokens.email_change_confirm_expires_days')
                    ),
                    'is_used' => false,
                ]);

                // 新しいメールアドレス宛に確認リンクを送る。失敗時は全ロールバック
                Mail::to($newEmail)->send(new ClientEmailChangeConfirmMail($token));
            });
        } catch (\Throwable $e) {
            // 全ロールバック済み。送信の失敗が会員側からは「変更の申込みが失敗した」
            // としか見えないため、原因追跡のためのログを残す。メールアドレスは個人情報の
            // ためログに残さず、client_id で追えるようにする（初回設定の受け止め方に揃える）。
            Log::error('[ClientEmailChangeConfirmMail] 確認メールの送信に失敗し、変更の申込みを全ロールバックしました: ' . $e->getMessage(), [
                'client_id' => $client->id,
                'exception' => $e,
            ]);

            // 入力エラー以外の失敗はフォームの上に出す（設計書 §2-7）。
            return back()
                ->withInput()
                ->withErrors(['form' => '確認メールを送信できませんでした。時間を置いて再度お試しください。']);
        }

        return redirect()
            ->route('client-portal.settings.email.edit')
            ->with('success', '新しいメールアドレス宛に確認メールを送信しました。メールのリンクを開くとメールアドレスが切り替わります。');
    }
}
