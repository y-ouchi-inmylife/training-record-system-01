<?php

namespace App\Http\Controllers;

use App\Models\Trainee;
use App\Services\SurveyMypageRegistrationService;
use App\Services\TraineePersonalityDiagnosisTokenService;
use App\Support\InvitationMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 性格診断の案内（性格診断用 URL のトークン。DS-1100）の発行・取消・表示（2026-10 追加）。
 *
 * 要件定義書 6-16-11・6-16-12、api-design.md 4-1-16。愛犬アンケート案内（TraineeIntakeSurveyTokenController）と
 * 同じ作り。発行・取消は S-0305 会員詳細のトレーニーカードの「性格診断」の行から呼ばれる（会員詳細だけで行う）。
 * 違いは、前の結果（回答済みの案内）があっても発行できること（何回でも受けられるため）。
 *
 * - 発行のあとは S-0305 会員詳細に戻し、完了メッセージは上端の success（2026-10-17 変更：その前は
 *   S-0312 にリダイレクトし、完了メッセージは載せなかった。愛犬アンケート案内と同じ）
 * - 取消のあとは S-0305 会員詳細に戻し、完了メッセージは上端の success
 */
class TraineePersonalityDiagnosisTokenController extends Controller
{
    /**
     * 性格診断の案内を発行する（POST /trainees/{trainee}/personality-diagnosis-tokens）。
     * 発行のあとは会員詳細に戻り、完了メッセージ「{表示名}の性格診断の案内を発行しました。」を出す（2026-10-17 変更）。
     * 有効な案内がすでにある（愛犬アンケートの回答待ちも含む。二重送信・古い画面から押し直したなど）ときは、新しく作らず、
     * 会員詳細に戻ってエラーの知らせ「{表示名}には、すでに案内中の案内があります。」を出す（2026-10-19 変更：その前は知らせなし）
     */
    public function store(Request $request, Trainee $trainee, TraineePersonalityDiagnosisTokenService $service, SurveyMypageRegistrationService $mypage): RedirectResponse
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
            // 回答待ちの案内（同じ種類・もう一方の種類とも）があって発行しなかったときは、エラーの知らせを出す
            // （2026-10-19 変更：何も言わずに戻ると、発行できなかったことが分からないため。その前は知らせなしで戻った）
            return $redirect->with('error', $trainee->display_name.'には、すでに案内中の案内があります。');
        }

        return $redirect->with('success', $trainee->display_name.'の'.__('messages.personality_diagnosis_name').'の案内を発行しました。'.SurveyMypageRegistrationService::completionNote($mypageIssued));
    }

    /**
     * 発行の URL を GET で開かれたとき（GET /trainees/{trainee}/personality-diagnosis-tokens。2026-10-17 追加）。
     * 発行せずに、そのトレーニーの会員の会員詳細（S-0305）に移す（405 にしない。愛犬アンケート案内と同じ）
     */
    public function redirectToClient(Trainee $trainee): RedirectResponse
    {
        return redirect()->route('clients.show', $trainee->client_id);
    }

    /**
     * 性格診断の案内を取り消す（DELETE /trainees/{trainee}/personality-diagnosis-tokens）。
     * 有効な（未回答の）トークンだけを物理削除する。回答済みのトークンと結果には触らない。
     * 有効な案内がなければ 409（愛犬アンケートと同じ）
     */
    public function destroy(Trainee $trainee): RedirectResponse
    {
        // 有効な（未回答で期限内の）案内だけを消す。期限が切れた案内は記録として残す（2026-10-19 変更）
        $deleted = $trainee->personalityDiagnosisTokens()->active()->delete();
        if ($deleted === 0) {
            abort(409, '取り消す'.__('messages.personality_diagnosis_name').'の案内がありません。');
        }

        return redirect()->route('clients.show', $trainee->client_id)
            ->with('success', __('messages.personality_diagnosis_name').'の案内を取り消しました。');
    }

    /**
     * 性格診断案内画面（S-0312、GET /trainees/{trainee}/personality-diagnosis-tokens/print）。
     *
     * 有効な（未回答の）トークンがあれば、QR コード・URL・手順を載せた案内を出す。なければ同じビューで
     * 「有効な性格診断の案内がありません。」を出す（未発行・取消済み・前の案内に回答済みのいずれでも同じ）。
     * 愛犬アンケート案内画面（S-0311）と同じ作り。表示はアクセスログに記録しない。
     * お客様の氏名・愛犬の名前はビューに渡さない（愛犬アンケートと同じ）。
     */
    public function print(Trainee $trainee): View
    {
        $token = $trainee->activePersonalityDiagnosisToken;

        if (! $token) {
            return view('trainees.personality-diagnosis-token-print', [
                'trainee' => $trainee,
                'url' => null,
                'copyText' => null,
            ]);
        }

        $url = route('client-portal.personality-diagnosis.show', ['token' => $token->token]);

        return view('trainees.personality-diagnosis-token-print', [
            'trainee' => $trainee,
            'url' => $url,
            // LINE・メールで送る文面（画面に出す文とコピーする文は同じ。2026-10-18 変更：InvitationMessage に移した）
            // 有効期限があれば文面に「リンクの有効期限：…まで」の行を入れる（2026-10-19 追加。期限なしの案内は今までどおり）
            'copyText' => InvitationMessage::personalityDiagnosis($trainee, $url, $token->expires_at),
        ]);
    }
}
