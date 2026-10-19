<?php

namespace App\Http\Controllers;

use App\Models\Trainee;
use App\Services\SurveyMypageRegistrationService;
use App\Services\TraineeCombinedSurveyTokenService;
use App\Support\CombinedSurveyName;
use App\Support\InvitationMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 「愛犬アンケート＆愛犬性格診断」の案内の発行・取消・ご案内の画面（2026-10-19 追加。要件定義書 6-16-8・6-16-11、
 * api-design.md 4-1-16）。
 *
 * 2 つの案内（愛犬アンケートの案内・愛犬性格診断の案内）を同時に作って結び付け、1 つのリンク（愛犬アンケートの案内のリンク）で
 * 続けて答えてもらう。発行・取消は会員詳細（S-0305）のトレーニーの欄から呼ばれ、どちらのあとも会員詳細に戻る
 * （単独の案内と同じ。TraineeIntakeSurveyTokenController）。
 */
class TraineeCombinedSurveyTokenController extends Controller
{
    /**
     * 愛犬アンケート＆愛犬性格診断の案内を発行する（POST /trainees/{trainee}/combined-survey-tokens）。
     * 回答待ちの案内（どちらの種類でも）があれば発行せず、単独の発行と同じエラーの知らせを出す
     */
    public function store(Request $request, Trainee $trainee, TraineeCombinedSurveyTokenService $service, SurveyMypageRegistrationService $mypage): RedirectResponse
    {
        // 「マイページ登録の案内も発行する」がオンなら、同じトランザクションでマイページ登録の案内も発行する（2026-10-19 追加。
        // 要件定義書 6-16-18。有効な案内がある会員〔登録待ち〕・登録待ち（初回設定）・利用中なら何もしない）
        [$token, $mypageIssued] = DB::transaction(function () use ($request, $trainee, $service, $mypage) {
            $token = $service->issue($trainee, $request->user());
            $mypageIssued = $token !== null && $request->boolean('with_mypage_registration')
                && $mypage->issueFor($trainee, $request->user());

            return [$token, $mypageIssued];
        });

        $redirect = redirect()->route('clients.show', $trainee->client_id);

        if ($token === null) {
            return $redirect->with('error', $trainee->display_name.'には、すでに案内中の案内があります。');
        }

        return $redirect->with('success', $trainee->display_name.'の'.CombinedSurveyName::name().'の案内を発行しました。'.SurveyMypageRegistrationService::completionNote($mypageIssued));
    }

    /**
     * 発行の URL を GET で開かれたとき（GET /trainees/{trainee}/combined-survey-tokens）。発行せずに会員詳細に移す
     * （単独の案内と同じ）
     */
    public function redirectToClient(Trainee $trainee): RedirectResponse
    {
        return redirect()->route('clients.show', $trainee->client_id);
    }

    /**
     * 愛犬アンケート＆愛犬性格診断の案内を取り消す（DELETE /trainees/{trainee}/combined-survey-tokens）。
     *
     * まだ答えていない分（有効な〔未回答で期限内の〕組み合わせの愛犬アンケートの案内と、結び付いた有効な愛犬性格診断の案内）を
     * まとめて物理削除する。回答済み・期限切れの案内には触らない。取り消す組み合わせの案内がなければ 409。
     * 愛犬アンケートだけ答えたあと（残りは愛犬性格診断だけ）は、会員詳細から単独の愛犬性格診断の取消を使う
     */
    public function destroy(Trainee $trainee): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($trainee) {
            $intake = $trainee->intakeSurveyTokens()->active()->whereNotNull('trainee_personality_diagnosis_token_id')->first();
            if ($intake === null) {
                return 0;
            }
            $trainee->personalityDiagnosisTokens()->active()->whereKey($intake->trainee_personality_diagnosis_token_id)->delete();

            return $intake->delete() ? 1 : 0;
        });
        if ($deleted === 0) {
            abort(409, '取り消す'.CombinedSurveyName::name().'の案内がありません。');
        }

        return redirect()->route('clients.show', $trainee->client_id)
            ->with('success', CombinedSurveyName::name().'の案内を取り消しました。');
    }

    /**
     * 愛犬アンケート＆愛犬性格診断のご案内（S-0314、GET /trainees/{trainee}/combined-survey-tokens/print）。
     *
     * 2 つとも回答待ちのときに、渡すリンク（愛犬アンケートの案内のリンク）・QR コード・文面を出す。そうでなければ
     * 「有効な…の案内がありません。」（愛犬アンケートだけ答えたあとは、会員詳細の［表示］が愛犬性格診断のご案内を開く）。
     * 作りは愛犬アンケートのご案内（S-0311）と同じ（表示はアクセスログに記録しない）
     */
    public function print(Trainee $trainee): View
    {
        if (! $trainee->has_active_combined_survey) {
            return view('trainees.combined-survey-token-print', [
                'trainee' => $trainee,
                'url' => null,
                'copyText' => null,
            ]);
        }

        $token = $trainee->activeIntakeSurveyToken;
        $url = route('client-portal.intake-survey.show', ['token' => $token->token]);

        return view('trainees.combined-survey-token-print', [
            'trainee' => $trainee,
            'url' => $url,
            'copyText' => InvitationMessage::combined($trainee, $url, $token->expires_at),
        ]);
    }
}
