<?php

namespace App\Http\Controllers;

use App\Models\Trainee;
use App\Services\TraineeIntakeSurveyTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 受付アンケート案内（受付アンケート用 URL のトークン。DS-1000）の発行・取消（2026-10 追加）。
 *
 * 要件定義書 6-16-8・6-16-9、api-design.md 4-1-16。発行・取消は S-0305 会員詳細の
 * トレーニーカードと S-0309 トレーニー詳細の受付アンケートの欄から呼ばれる。
 * 案内画面（S-0311、GET …/print）は段階3b で足す。
 *
 * ## 戻り先と完了メッセージ（api-design.md §3「受付アンケート案内エンドポイントの `return_to`」）
 *
 * - `return_to=client`：S-0305 会員詳細に戻し、完了メッセージはいつもの `success`（画面の上端）
 * - それ以外（既定は `trainee`）：S-0309 の受付アンケートの欄（`#intake-survey`）に戻し、
 *   完了メッセージは欄の中に出すための別のキー `intake_survey_success` で渡す。戻り先が画面の
 *   下のほうなので、上端のメッセージは見えず、しかも 5 秒で自動で消えるため（screen-design.md S-0309）
 * - 計測値の `redirectAfter()` と同じく、URL 文字列は受け取らない（open redirect を避ける）
 */
class TraineeIntakeSurveyTokenController extends Controller
{
    /** S-0309 の受付アンケートの欄の中に出す完了メッセージのセッションのキー */
    public const SESSION_KEY = 'intake_survey_success';

    /**
     * 受付アンケート案内を発行する（POST /trainees/{trainee}/intake-survey-tokens）。
     * 状態が「未発行」でなければ 409（UI 側でボタンを出し分けているため通常は到達しない）
     */
    public function store(Request $request, Trainee $trainee, TraineeIntakeSurveyTokenService $service): RedirectResponse
    {
        $token = $service->issue($trainee, $request->user());
        if ($token === null) {
            abort(409, '受付アンケートの案内はすでに発行されています。');
        }

        return $this->redirectAfter($request, $trainee, '受付アンケートの案内を発行しました。');
    }

    /**
     * 受付アンケート案内を取り消す（DELETE /trainees/{trainee}/intake-survey-tokens）。
     * 有効な（未回答の）トークンだけを物理削除する。回答済みのトークンには触らない。
     * 有効な案内がなければ 409
     */
    public function destroy(Request $request, Trainee $trainee): RedirectResponse
    {
        $deleted = $trainee->intakeSurveyTokens()->whereNull('answered_at')->delete();
        if ($deleted === 0) {
            abort(409, '取り消す受付アンケートの案内がありません。');
        }

        return $this->redirectAfter($request, $trainee, '受付アンケートの案内を取り消しました。');
    }

    /**
     * 送信元（`return_to`）に応じて戻り先と完了メッセージのキーを決める。
     * `client` 以外・欠落時は `trainee` として扱う（計測値の redirectAfter と同じ安全側の扱い）
     */
    private function redirectAfter(Request $request, Trainee $trainee, string $message): RedirectResponse
    {
        if ($request->input('return_to') === 'client') {
            return redirect()->route('clients.show', $trainee->client_id)->with('success', $message);
        }

        return redirect()->to(route('trainees.show', $trainee).'#intake-survey')->with(self::SESSION_KEY, $message);
    }
}
