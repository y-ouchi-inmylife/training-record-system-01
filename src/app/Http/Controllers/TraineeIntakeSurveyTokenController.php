<?php

namespace App\Http\Controllers;

use App\Models\Trainee;
use App\Services\TraineeIntakeSurveyTokenService;
use App\Support\InvitationMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 愛犬アンケート案内（愛犬アンケート用 URL のトークン。DS-1000）の発行・取消（2026-10 追加）。
 *
 * 要件定義書 6-16-8・6-16-9、api-design.md 4-1-16。発行・取消は S-0305 会員詳細の
 * トレーニーカードから呼ばれる（発行は会員詳細だけ。トレーニー登録 S-0308 では発行しない。2026-10 変更）。
 * 案内画面（S-0311、GET …/print）は、会員詳細の「愛犬アンケートの案内を表示」から同じタブで開く
 * （2026-10-17 変更：当初は別タブ）。
 *
 * ## 戻り先と完了メッセージ（2026-10 変更）
 *
 * 発行のあとも取消のあとも**常に S-0305 会員詳細**に戻し、完了メッセージはいつもの `success`（画面の上端）
 * （2026-10-17 変更：その前は、発行のあとは S-0311 を開き、完了メッセージは載せなかった。下の store()）。
 * 愛犬アンケート案内の発行・表示・取消は会員詳細だけで行う（案内を渡す相手は会員で、マイページ登録案内も
 * 会員詳細でしか操作しないため。要件定義書 6-16-8 備考）。
 *
 * 経緯：段階3a では S-0309 トレーニー詳細からも発行・取消を行えるようにし、`return_to`
 * （client / trainee）で戻り先を切り替え、S-0309 に戻るときは欄の中に出すための別のキー
 * （intake_survey_success）で完了メッセージを渡していた。同じ 2026-10 に、操作を会員詳細に寄せたため
 * なくした（`return_to` が送られても使わない）
 */
class TraineeIntakeSurveyTokenController extends Controller
{
    /**
     * 愛犬アンケート案内を発行する（POST /trainees/{trainee}/intake-survey-tokens）。
     *
     * 発行のあとは会員詳細（S-0305）に戻り、完了メッセージ「{表示名}の愛犬アンケートの案内を発行しました。」を
     * 出す（2026-10-17 変更。表示名は会員詳細のトレーニーの名前と同じ display_name）。S-0311 は開かない
     * （案内は必要なときに［愛犬アンケートの案内を表示］から開く。マイページ登録案内と同じ）。
     * 戻る位置はページの上（完了メッセージがページの上に出るため、トレーニーの欄の位置には移さない）。
     *
     * 発行できなかったとき：
     * - 有効な案内がすでにある（回答待ち。愛犬性格診断の回答待ちも含む。二重送信・古い画面から押し直したなど）：新しく発行せず、
     *   会員詳細に戻ってエラーの知らせ「{表示名}には、すでに案内中の案内があります。」を出す（2026-10-19 変更：その前は知らせなし）
     * 回答済みのトレーニーにも発行する（答え直してもらうため。2026-10-19 変更：その前は 409）
     *
     * 設計書: requirements.md 6-16-8、6-3-6 備考「発行のあとの戻り先」
     */
    public function store(Request $request, Trainee $trainee, TraineeIntakeSurveyTokenService $service): RedirectResponse
    {
        $token = $service->issue($trainee, $request->user());

        $redirect = redirect()->route('clients.show', $trainee->client_id);

        if ($token === null) {
            // 回答待ちの案内（同じ種類・もう一方の種類とも）があって発行しなかったときは、エラーの知らせを出す
            // （2026-10-19 変更：何も言わずに戻ると、発行できなかったことが分からないため。その前は知らせなしで戻った）
            return $redirect->with('error', $trainee->display_name.'には、すでに案内中の案内があります。');
        }

        return $redirect->with('success', $trainee->display_name.'の'.__('messages.intake_survey_name').'の案内を発行しました。');
    }

    /**
     * 発行の URL を GET で開かれたとき（GET /trainees/{trainee}/intake-survey-tokens。2026-10-17 追加）。
     * 発行せずに、そのトレーニーの会員の会員詳細（S-0305）に移す（405 にしない）。
     * マイページ登録案内（ClientEmailRegistrationTokenController::redirectToClient）と同じ
     */
    public function redirectToClient(Trainee $trainee): RedirectResponse
    {
        return redirect()->route('clients.show', $trainee->client_id);
    }

    /**
     * 愛犬アンケート案内を取り消す（DELETE /trainees/{trainee}/intake-survey-tokens）。
     * 有効な（未回答の）トークンだけを物理削除する。回答済みのトークンには触らない。
     * 有効な案内がなければ 409
     */
    public function destroy(Trainee $trainee): RedirectResponse
    {
        // 有効な（未回答で期限内の）案内だけを消す。期限が切れた案内は記録として残す（2026-10-19 変更）
        $deleted = $trainee->intakeSurveyTokens()->active()->delete();
        if ($deleted === 0) {
            abort(409, '取り消す'.__('messages.intake_survey_name').'の案内がありません。');
        }

        return redirect()->route('clients.show', $trainee->client_id)
            ->with('success', __('messages.intake_survey_name').'の案内を取り消しました。');
    }

    /**
     * 愛犬アンケート案内画面（S-0311、GET /trainees/{trainee}/intake-survey-tokens/print）。
     *
     * 有効な（未回答の）トークンがあれば、QR コード・URL・手順を載せた案内を出す。なければ同じビューで
     * 「有効な愛犬アンケート案内がありません。」を出す（回答済み・取消済み・未発行のいずれでも同じ。
     * 発行できる場所には触れない）。マイページ登録案内の印刷画面（S-0307）と同じ作り。
     * 表示はアクセスログに記録しない（S-0307 の表示も記録していないため）。
     *
     * お客様に渡す URL は route() で作る。会員側のドメインのグループのルートなので、会員側のホストが付く。
     * お客様の氏名はビューに渡さない（紙を紛失したときに氏名と URL が同時に漏れないようにするため）。
     */
    public function print(Trainee $trainee): View
    {
        $token = $trainee->activeIntakeSurveyToken;

        if (! $token) {
            return view('trainees.intake-survey-token-print', [
                'trainee' => $trainee,
                'url' => null,
                'copyText' => null,
            ]);
        }

        $url = route('client-portal.intake-survey.show', ['token' => $token->token]);

        return view('trainees.intake-survey-token-print', [
            'trainee' => $trainee,
            'url' => $url,
            // LINE・メールで送る文面（画面に出す文とコピーする文は同じ。2026-10-18 変更：InvitationMessage に移した）
            // 有効期限があれば文面に「リンクの有効期限：…まで」の行を入れる（2026-10-19 追加。期限なしの案内は今までどおり）
            'copyText' => InvitationMessage::intakeSurvey($trainee, $url, $token->expires_at),
        ]);
    }
}
