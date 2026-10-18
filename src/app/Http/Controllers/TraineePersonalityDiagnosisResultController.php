<?php

namespace App\Http\Controllers;

use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 愛犬性格診断詳細（S-0313）と評価文の保存（2026-10-18 追加。要件定義書 6-16-15）、愛犬性格診断の結果の削除
 * （要件定義書 6-16-14、api-design.md 4-1-16。2026-10 追加。2026-10-18 変更：送信元を S-0309 から S-0313 に移した）。
 *
 * 詳細と評価文の保存：
 * - トレーナー（一般・管理者）なら見られる・保存できる。{personalityDiagnosisResult} は {trainee} の結果に限る（scopeBindings）
 * - 評価文の状態（未作成・下書き・公開済み）は列に持たず、評価文と結び付いたトレーニング記録から決める（evaluation_status）
 * - 公開済みの評価文は保存を受け付けない（409。直すのはトレーニング記録の編集。画面ではテキストエリアを出さない）
 * - 公開（［トレーニング記録にして公開］）は次の段階（②）で作る。今は出さない
 *
 * 結果の削除：
 *
 * 性格診断は前回・初回と比べるため、間違った回答（別の愛犬のつもりで答えた、試しの回答、誤送信など）が残ると比較がずれる。
 * そのため、管理者が結果を 1 件ずつ削除できるようにする。受付アンケートの回答は今までどおり削除できない。
 *
 * - 権限はトレーニーの削除（TraineeController::destroy）と同じ：管理者だけ（同じ確かめ方・同じ文言の 403）。
 *   システム管理者は、トレーニー管理のルートの practitioners ミドルウェアで 403
 * - {personalityDiagnosisResult} は {trainee} の結果に限る（ルートの scopeBindings。別のトレーニーの結果なら 404）
 * - 物理削除。結果に使った案内（回答済みのトークン）は消さない
 * - アクセスログ（delete_trainee_personality_diagnosis_result）は LogAccess ミドルウェアで記録する
 */
class TraineePersonalityDiagnosisResultController extends Controller
{
    /**
     * 愛犬性格診断詳細（GET /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}。S-0313）。
     *
     * レーダーチャートは、この結果を今回にし、それより前の結果から前回・初回を取る（Trainee::personalityRadarDataAsOf）。
     * そのためトレーニーの結果を全部（新しい順に）読み込む。表示はアクセスログに記録しない
     */
    public function show(Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): View
    {
        $trainee->load('personalityDiagnosisResults');
        $personalityDiagnosisResult->load(['trainingRecord', 'evaluationUpdatedBy']);

        return view('trainees.personality-diagnosis-results.show', [
            'trainee' => $trainee,
            'result' => $personalityDiagnosisResult,
            'radarData' => $trainee->personalityRadarDataAsOf($personalityDiagnosisResult),
        ]);
    }

    /**
     * 評価文を保存する（PUT /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}）。
     *
     * - 10,000 文字以内。改行は LF にそろえてから数える（受付アンケートの自由記述と同じ。ブラウザは改行を 1 文字と数えるため）
     * - 空（空白だけを含む）で保存すると NULL（未作成に戻る）。それ以外は入力のまま保存する（前後の空白は TrimStrings で取れる）
     * - 最後に保存したトレーナーと日時を残す（evaluation_updated_by・evaluation_updated_at）
     * - 公開済みなら 409（保存しない）
     * - アクセスログ（edit_trainee_personality_diagnosis_evaluation）は LogAccess ミドルウェアで記録する
     */
    public function update(Request $request, Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): RedirectResponse
    {
        if ($personalityDiagnosisResult->evaluation_status === TraineePersonalityDiagnosisResult::EVALUATION_PUBLISHED) {
            abort(409, 'この評価文は公開済みです。直すときは、トレーニング記録の編集で直してください。');
        }

        $text = $request->input('evaluation_text');
        if (is_string($text)) {
            $request->merge(['evaluation_text' => str_replace(["\r\n", "\r"], "\n", $text)]);
        }
        $validated = $request->validate([
            'evaluation_text' => 'nullable|string|max:'.TraineePersonalityDiagnosisResult::EVALUATION_MAX_LENGTH,
        ]);

        $personalityDiagnosisResult->update([
            'evaluation_text' => blank($validated['evaluation_text'] ?? null) ? null : $validated['evaluation_text'],
            'evaluation_updated_by' => auth()->id(),
            'evaluation_updated_at' => now(),
        ]);

        return redirect()
            ->route('trainee-personality-diagnosis-results.show', [$trainee, $personalityDiagnosisResult])
            ->with('success', '評価文を保存しました。');
    }

    /**
     * 性格診断の結果を 1 件削除する（DELETE /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}）。
     * 完了後はトレーニー詳細（S-0309）の上から開く（完了メッセージは画面の上端に出るため、性格診断の欄には飛ばさない）
     */
    public function destroy(Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): RedirectResponse
    {
        // 管理トレーナーのみ削除可能（TraineeController::destroy と同じ形・同じ文言）
        if (! auth()->user()->isAdmin()) {
            abort(403, '管理者のみ削除できます。');
        }

        $personalityDiagnosisResult->delete();

        return redirect()
            ->route('trainees.show', $trainee)
            ->with('success', __('messages.personality_diagnosis_name').'の結果を削除しました。');
    }
}
