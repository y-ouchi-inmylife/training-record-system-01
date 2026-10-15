<?php

namespace App\Http\Controllers;

use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use Illuminate\Http\RedirectResponse;

/**
 * 性格診断の結果の削除（要件定義書 6-16-14、api-design.md 4-1-16。2026-10 追加）。
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
            ->with('success', '性格診断の結果を削除しました。');
    }
}
