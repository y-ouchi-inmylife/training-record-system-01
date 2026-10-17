<?php

namespace App\Http\Controllers;

use App\Models\Trainee;
use App\Services\TraineeIntakeSurveyTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 受付アンケート案内（受付アンケート用 URL のトークン。DS-1000）の発行・取消（2026-10 追加）。
 *
 * 要件定義書 6-16-8・6-16-9、api-design.md 4-1-16。発行・取消は S-0305 会員詳細の
 * トレーニーカードから呼ばれる（発行は会員詳細だけ。トレーニー登録 S-0308 では発行しない。2026-10 変更）。
 * 案内画面（S-0311、GET …/print）は、会員詳細の「受付アンケートの案内を表示」から同じタブで開く
 * （2026-10-17 変更：当初は別タブ）。
 *
 * ## 戻り先と完了メッセージ（2026-10 変更）
 *
 * 発行のあとは、同じタブで S-0311 を開き、完了メッセージは載せない（下の store()）。
 * 取消のあとは**常に S-0305 会員詳細**に戻し、完了メッセージはいつもの `success`（画面の上端）。
 * 受付アンケート案内の発行・表示・取消は会員詳細だけで行う（案内を渡す相手は会員で、マイページ登録案内も
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
     * 受付アンケート案内を発行する（POST /trainees/{trainee}/intake-survey-tokens）。
     *
     * 発行のあとは、同じタブで S-0311 にリダイレクトする（2026-10 変更）。発行のあとの次の作業は
     * 案内を見せる・印刷する・文面をコピーすることなので、「受付アンケートの案内を表示」を押し直す
     * 手間をなくす。完了メッセージは載せない（S-0311 は印刷用の画面のため。S-0311 が開くこと自体が
     * 発行できたことの知らせになる）。S-0311 の［戻る］は会員詳細へのリンク。
     *
     * 発行できなかったとき：
     * - 有効な案内がすでにある（回答待ち。二重送信・ほかのタブで発行済みなど）：新しく発行せず、
     *   今ある案内の S-0311 を開く（お客様に渡すものは同じため）
     * - 回答済み：今までどおり 409（UI 側でボタンを出し分けているため通常は到達しない）
     *
     * 設計書: requirements.md 6-16-8、6-3-6 備考「発行のあとに案内の画面を開く理由」
     */
    public function store(Request $request, Trainee $trainee, TraineeIntakeSurveyTokenService $service): RedirectResponse
    {
        $token = $service->issue($trainee, $request->user());
        // 状態の判定順（回答がある → 回答済み）に合わせて、回答の有無を先に見る
        if ($token === null && $trainee->intakeSurveyResponses()->exists()) {
            abort(409, '受付アンケートの案内はすでに発行されています。');
        }

        return redirect()->route('trainee-intake-survey-tokens.print', $trainee);
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
     * 受付アンケート案内を取り消す（DELETE /trainees/{trainee}/intake-survey-tokens）。
     * 有効な（未回答の）トークンだけを物理削除する。回答済みのトークンには触らない。
     * 有効な案内がなければ 409
     */
    public function destroy(Trainee $trainee): RedirectResponse
    {
        $deleted = $trainee->intakeSurveyTokens()->whereNull('answered_at')->delete();
        if ($deleted === 0) {
            abort(409, '取り消す受付アンケートの案内がありません。');
        }

        return redirect()->route('clients.show', $trainee->client_id)
            ->with('success', '受付アンケートの案内を取り消しました。');
    }

    /**
     * 受付アンケート案内画面（S-0311、GET /trainees/{trainee}/intake-survey-tokens/print）。
     *
     * 有効な（未回答の）トークンがあれば、QR コード・URL・手順を載せた案内を出す。なければ同じビューで
     * 「有効な受付アンケート案内がありません。」を出す（回答済み・取消済み・未発行のいずれでも同じ。
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
            'copyText' => $this->buildCopyText($url),
        ]);
    }

    /**
     * メール／LINE 送付用の案内文面を組み立てる（screen-design.md S-0311「案内文面のコピー」）。
     *
     * S-0307 の決め事にそろえる：件名を入れない・挨拶を入れない・QR コードに触れない・
     * 事業者名は最後の行で、config('app.client_portal_company') が未設定なら行ごと出さない。
     * 愛犬の名前は使わない（名前の有無にかかわらず同じ文。2026-10 変更。トレーナーが電話などで聞いて入れた
     * 名前が間違っていると、お客様に間違った名前を見せることになるため。当初は名前があれば
     * 「モモちゃんのトレーニングの参考にするため、…」としていた）。
     *
     * 文面を直すときは、上の設計書と本メソッドを同時に更新すること。
     */
    private function buildCopyText(string $url): string
    {
        $company = config('app.client_portal_company');

        $lines = [
            'トレーニングの参考にするため、受付アンケートへのご回答をお願いします。',
            '下のリンクから、愛犬のことと暮らしの様子をお聞かせください。',
            '',
            $url,
            '',
            'ご回答は1回かぎりです。送信すると、このリンクは使えなくなります。',
        ];

        // 事業者名が空のときは、直前の空行を含めて丸ごと出さない
        if (! empty($company)) {
            $lines[] = '';
            $lines[] = $company;
        }

        return implode("\n", $lines);
    }
}
