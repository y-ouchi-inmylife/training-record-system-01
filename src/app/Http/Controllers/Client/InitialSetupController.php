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
 * クライアント初回設定コントローラ（S-1403）
 *
 * トークン付き公開 URL（ログイン用リンク）からアクセスする。
 * GET でトークン検証と同時に client guard で自動ログインさせ、
 * POST でパスワード＋基本情報を保存する。
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
                'このURLでは既に初回設定が完了しています。ログイン画面からログインしてください。'
            );
        }

        // 自動ログイン。「リンクを開いただけでは使用済みにしない」ため is_used は更新しない。
        Auth::guard('client')->login($tokenRecord->client);
        request()->session()->regenerate();

        // 既存のトレーニーを 1 頭目（id 昇順の先頭）だけ Blade に渡す。
        // 2 頭目以降は初回設定で扱わない（requirements.md 6-15-5 / 6-16 参照）。
        // trainees リレーションは Client モデルで orderBy('id') 済みのため、
        // first() で 1 頭目が取れる。存在しなければ null（Blade で空欄扱い）。
        $tokenRecord->client->load('trainees');
        $existingTrainee = $tokenRecord->client->trainees->first();

        return view('client.setup.index', [
            'token' => $token,
            'client' => $tokenRecord->client,
            'existingTrainee' => $existingTrainee,
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

        try {
            DB::transaction(function () use ($validated, $tokenRecord, $loginUrl) {
                // パスワード・氏名・連絡先を更新（email は S-1405 で登録済みのため触らない）
                $tokenRecord->client->update([
                    'password' => $validated['password'],
                    'last_name' => $validated['last_name'],
                    'first_name' => $validated['first_name'] ?? null,
                    'last_name_kana' => $validated['last_name_kana'] ?? null,
                    'first_name_kana' => $validated['first_name_kana'] ?? null,
                    'phone1' => $validated['phone1'],
                    'phone2' => $validated['phone2'] ?? null,
                    'postal_code' => $validated['postal_code'],
                    'address1' => $validated['address1'],
                    'address2' => $validated['address2'],
                    'address3' => $validated['address3'],
                    'address4' => $validated['address4'] ?? null,
                ]);

                // 愛犬（トレーニー）の情報を登録・更新する（requirements.md 6-15-5 / 6-16 参照）。
                //   - 既存のトレーニーが 1 件以上あれば `id` 昇順の先頭（1 頭目）を更新
                //   - なければ新規作成
                //   - 2 頭目以降のレコードには一切手を触れない（削除も更新もしない）
                //
                // `updated_by` は null にする：trainees.updated_by は trainers.id への FK で、
                // 会員（clients）由来の更新ではトレーナーIDが存在しないため（実装上も FK 制約違反を避けるため
                // null 一択）。会員自身の更新であることは「初回設定でだけ会員が編集できる」設計方針で
                // 特定される（requirements.md 6-16 の「会員による編集」参照）。
                $traineeData = [
                    'name' => $validated['trainee_name'],
                    'breed' => $validated['trainee_breed'] ?? null,
                    'sex' => $validated['trainee_sex'] ?? null,
                    'birth_date' => $validated['trainee_birth_date'] ?? null,
                    'note' => $validated['trainee_note'] ?? null,
                    'updated_by' => null,
                ];
                $existingTrainee = $tokenRecord->client->trainees()->orderBy('id')->first();
                if ($existingTrainee) {
                    $existingTrainee->update($traineeData);
                } else {
                    $tokenRecord->client->trainees()->create($traineeData);
                }

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
                Mail::to($tokenRecord->client->email)
                    ->send(new ClientSetupCompletedMail($tokenRecord->client, $loginUrl));
            });
        } catch (\Throwable $e) {
            // 全ロールバック済み。既存 5 通の Mailable 送信箇所はログを出していないが、
            // 送信失敗がお客様側からは「初回設定が失敗しました」としか見えないため、
            // 原因追跡のために [ClientSetupCompletedMail] のログを残す。メールアドレスは
            // 個人情報のためログに残さず、client_id で追えるようにする。
            Log::error('[ClientSetupCompletedMail] 初回設定完了メールの送信に失敗し、初回設定を全ロールバックしました: ' . $e->getMessage(), [
                'client_id' => $tokenRecord->client_id,
                'exception' => $e,
            ]);

            // 入力エラー以外の失敗はフォームの上に出す（設計書 §2-7 の規約。段階 4-2 で
            // キーを `setup` から専用の `form` に移した）。
            return back()
                ->withInput()
                ->withErrors(['form' => '初回設定を完了できませんでした。時間を置いて再度お試しください。改善しない場合は担当のトレーナーにご連絡ください。']);
        }

        // 自動ログインは GET で完了している。そのままダッシュボードへ。
        return redirect()->route('client-portal.dashboard')
            ->with('success', '初回設定が完了しました。');
    }

    private function invalidTokenView(string $title, string $message): View
    {
        return view('client.setup.invalid-token', [
            'title' => $title,
            'message' => $message,
        ]);
    }
}
