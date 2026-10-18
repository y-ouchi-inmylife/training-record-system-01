<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientInitialSetupRequest;
use App\Mail\ClientSetupCompletedMail;
use App\Models\ClientLoginLinkToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * クライアント初回設定コントローラ（S-1403 マイページ登録）
 *
 * 2026-10-18 変更：ユーザーレビューで、お客様に見せる画面の名前を「初回設定」から「マイページ登録」にした。
 * お客様に見える文言（完了・失敗・使用済みのメッセージ）も「マイページの登録」にそろえた。
 * URL・ルート名・クラス名（InitialSetup）は変えない（このファイルのコメントの「初回設定」も同じものを指す）。
 *
 * トークン付き公開 URL（ログイン用リンク）からアクセスする。
 * GET でトークン検証と同時に client guard で自動ログインさせ、
 * POST でパスワード＋基本情報を保存する（愛犬〔トレーニー〕の情報は扱わない。2026-10 変更）。
 *
 * ログイン用リンクのトークンは `client_login_link_tokens` テーブル（DS-0600）を使う。
 *
 * 設計書: api-design.md `GET / POST /client-portal/setup/{token}`
 */
class InitialSetupController extends Controller
{
    /**
     * トークンを検証し、初回設定画面を表示（GET /client-portal/setup/{token}）
     * 有効な場合は同時にクライアントを client guard でログインさせる。
     */
    public function showByToken(string $token): View
    {
        $tokenRecord = ClientLoginLinkToken::where('token', $token)->first();

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
                'このURLでは既にマイページの登録が完了しています。ログイン画面からログインしてください。'
            );
        }

        // 自動ログイン。「リンクを開いただけでは使用済みにしない」ため is_used は更新しない。
        Auth::guard('client')->login($tokenRecord->client);
        request()->session()->regenerate();

        // 愛犬（トレーニー）の情報は初回設定では扱わない（2026-10 変更。受付アンケートに
        // 分離した。requirements.md 6-15-5）。トレーニーは読み込まない。
        return view('client.setup.index', [
            'token' => $token,
            'client' => $tokenRecord->client,
        ]);
    }

    /**
     * 初回設定を保存する（POST /client-portal/setup/{token}）
     */
    public function storeByToken(ClientInitialSetupRequest $request, string $token): View|RedirectResponse
    {
        // トークン再検証（レース対策）。二重開封で後発は「使用済み」エラーになる。
        $tokenRecord = ClientLoginLinkToken::where('token', $token)->first();
        if (!$tokenRecord || $tokenRecord->isExpired() || $tokenRecord->is_used) {
            return $this->invalidTokenView(
                'このURLは既に使用されています',
                'このURLは既に使用されているか、無効になっています。ログイン画面からログインしてください。'
            );
        }

        $validated = $request->validated();

        // 案内するログインURL。現行リクエストのホストに追従させるため `url('/')` を使う
        // （本番の CLIENT_HOST 制約下では `https://miraidogwellness.inmylife1965.com`、開発環境では
        // localhost 等が入る）。`route('client-portal.login')` を使わない理由は、お客様に
        // 案内する URL としてルート直下の方が短く、ログイン画面へは / から自動で誘導される
        // ため。設計書：client-portal-design-plan.md §6-2 / 本コミット同梱の指示書 2-1。
        //
        // `url('/')` は末尾スラッシュを付けずに返る（Laravel 12 での実測。例：
        // `http://localhost` / `https://miraidogwellness.inmylife1965.com`）。本文には
        // 「ホスト + '/'」の形で見せたいため（トップ = ログイン画面に落ちることをお客様が
        // 一目で読み取れるようにするため）、`rtrim` してから明示的に '/' を付ける。
        $loginUrl = rtrim(url('/'), '/') . '/';

        // どの処理で失敗したかをログで見分けるための印。メールの送信の直前に true にする。
        $sendingMail = false;

        try {
            DB::transaction(function () use ($validated, $tokenRecord, $loginUrl, &$sendingMail) {
                // パスワード・氏名・連絡先を更新（email は S-1405 で登録済みのため触らない）
                $tokenRecord->client->update([
                    'password' => $validated['password'],
                    'last_name' => $validated['last_name'],
                    'first_name' => $validated['first_name'] ?? null,
                    'last_name_kana' => $validated['last_name_kana'] ?? null,
                    'first_name_kana' => $validated['first_name_kana'] ?? null,
                    'phone1' => $validated['phone1'],
                    'phone2' => $validated['phone2'] ?? null,
                    'emergency_contact_relationship' => $validated['emergency_contact_relationship'] ?? null,
                    'postal_code' => $validated['postal_code'],
                    'address1' => $validated['address1'],
                    'address2' => $validated['address2'],
                    'address3' => $validated['address3'],
                    'address4' => $validated['address4'] ?? null,
                ]);

                // 愛犬（トレーニー）の情報は扱わない（2026-10 変更。以前はここで 1 頭目を
                // 登録・更新していたが、受付アンケートに分離した。requirements.md 6-15-5）。
                // 送信に古い trainee_* の項目が含まれていても、validated() に入らないため使われない。

                // ログイン用リンクを使い切りにする
                $tokenRecord->update(['is_used' => true]);

                // 対応するメールアドレス登録用トークン（未使用のもの）も使い切りにする。
                // 全体の期限管理はこのタイミングで終了する。
                $tokenRecord->client->emailRegistrationTokens()
                    ->where('is_used', false)
                    ->update(['is_used' => true]);

                // 登録完了メールを登録アドレス宛に送信する。失敗時は全ロールバックし、
                // ログイン用リンクは未使用のまま残る（同じ URL からやり直せる）。
                // Client::update() は email に触らないため、更新前後で $client->email は同値。
                $sendingMail = true;
                Mail::to($tokenRecord->client->email)
                    ->send(new ClientSetupCompletedMail($tokenRecord->client, $loginUrl));
            });
        } catch (\Throwable $e) {
            // 全ロールバック済み。お客様側からは「初回設定が失敗しました」としか見えないため、
            // 原因追跡のためにログを残す。以前はどの失敗でも「メールの送信に失敗」と出して
            // いたため、メールの送信で失敗したかどうかを分けて出す。
            //
            // 会員 ID・例外クラス名・コードだけを残し、例外のメッセージや例外そのものは残さない
            // （会員削除の「最後の守り」〔requirements.md 6-3-5〕と同じ持ち方）。DB の例外の
            // メッセージには SQL のバインド値（氏名・住所・電話番号・パスワードのハッシュ）が、
            // メールの例外にはメールアドレスが含まれうるため。
            $context = [
                'client_id' => $tokenRecord->client_id,
                'exception' => get_class($e),
                'code' => $e->getCode(),
            ];
            if ($sendingMail) {
                Log::error('[ClientSetupCompletedMail] 初回設定完了メールの送信に失敗したため、初回設定の変更を取り消しました', $context);
            } else {
                Log::error('初回設定の処理に失敗したため、変更を取り消しました', $context);
            }

            // 入力エラー以外の失敗はフォームの上に出す（設計書 §2-7 の規約。段階 4-2 で
            // キーを `setup` から専用の `form` に移した）。
            return back()
                ->withInput()
                ->withErrors(['form' => 'マイページの登録を完了できませんでした。時間を置いて再度お試しください。改善しない場合は担当のトレーナーにご連絡ください。']);
        }

        // 自動ログインは GET で完了している。そのままダッシュボードへ。
        return redirect()->route('client-portal.dashboard')
            ->with('success', 'マイページの登録が完了しました。');
    }

    private function invalidTokenView(string $title, string $message): View
    {
        return view('client.setup.invalid-token', [
            'title' => $title,
            'message' => $message,
        ]);
    }
}
