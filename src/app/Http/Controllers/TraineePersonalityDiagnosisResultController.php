<?php

namespace App\Http\Controllers;

use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Services\EvaluationDraft\EvaluationDraftFailedException;
use App\Services\EvaluationDraft\EvaluationDraftGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 愛犬性格診断詳細（S-0313）と評価文の更新・公開・公開取消（2026-10-18 追加。要件定義書 6-16-15・6-16-16）、
 * 愛犬性格診断の結果の削除（要件定義書 6-16-14、api-design.md 4-1-16。2026-10 追加。2026-10-18 変更：送信元を S-0309 から S-0313 に移した）。
 *
 * 詳細と評価文：
 * - トレーナー（一般・管理者）なら見られる・更新・公開・公開取消ができる。{personalityDiagnosisResult} は {trainee} の結果に限る（scopeBindings）
 * - 評価文の状態（なし・下書き・公開済み）は列に持たず、評価文と公開した日時（evaluation_published_at）から決める（evaluation_status）
 * - 公開は評価文そのものに「公開済み」の印（公開した日時・公開したトレーナー）を付ける。トレーニング記録は作らない
 *   （2026-10-18 変更：評価文と記録の本文の二重持ちをやめるため。その前は、公開で評価文を本文にしたトレーニング記録を作っていた）
 * - 公開済みの評価文も更新できる（すぐ会員様の画面に出る）。ただし公開済みのあいだは空にできない
 * - 公開・公開取消は、結果の行を排他ロックして状態を確かめ直す。すでに公開済み・すでに公開を取り消しているときは、
 *   エラーにせず S-0313 に戻す（2 回押されても困らないように）
 * - アクセスログ（edit_／publish_／unpublish_trainee_personality_diagnosis_evaluation）は LogAccess ミドルウェアで記録する
 *
 * 結果の削除：
 *
 * 性格診断は前回・初回と比べるため、間違った回答（別の愛犬のつもりで答えた、試しの回答、誤送信など）が残ると比較がずれる。
 * そのため、管理者が結果を 1 件ずつ削除できるようにする。愛犬アンケートの回答は今までどおり削除できない。
 *
 * - 権限はトレーニーの削除（TraineeController::destroy）と同じ：管理者だけ（同じ確かめ方・同じ文言の 403）。
 *   システム管理者は、トレーニー管理のルートの practitioners ミドルウェアで 403
 * - {personalityDiagnosisResult} は {trainee} の結果に限る（ルートの scopeBindings。別のトレーニーの結果なら 404）
 * - 物理削除。結果に使った案内（回答済みのトークン）は消さない。公開済みの評価文も一緒に消える
 * - アクセスログ（delete_trainee_personality_diagnosis_result）は LogAccess ミドルウェアで記録する
 */
class TraineePersonalityDiagnosisResultController extends Controller
{
    /** 公開済みのあいだに空で更新しようとしたときの文言（設計書 S-0313「バリデーションチェック」） */
    private const EMPTY_WHILE_PUBLISHED_MESSAGE = '公開中の評価文は空にできません。公開を取り消してから消してください。';

    /**
     * 愛犬性格診断詳細（GET /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}。S-0313）。
     *
     * レーダーチャートは、この結果を今回にし、それより前の結果から前回・初回を取る（Trainee::personalityRadarDataAsOf）。
     * そのためトレーニーの結果を全部（新しい順に）読み込む。表示はアクセスログに記録しない。
     * 見出しの右の会員（S-0305 へのリンク）と内部ID のため、トレーニーの会員も読み込む（2026-10-18 追加）
     */
    public function show(Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): View
    {
        $trainee->load(['personalityDiagnosisResults', 'client']);
        $personalityDiagnosisResult->load(['evaluationUpdatedBy']);

        return view('trainees.personality-diagnosis-results.show', [
            'trainee' => $trainee,
            'result' => $personalityDiagnosisResult,
            'radarData' => $trainee->personalityRadarDataAsOf($personalityDiagnosisResult),
        ]);
    }

    /**
     * 評価文を更新する（PUT /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}）。
     *
     * - 10,000 文字以内。改行は LF にそろえてから数える（愛犬アンケートの自由記述と同じ。ブラウザは改行を 1 文字と数えるため）
     * - 空（空白だけを含む）で保存すると NULL（「なし」に戻る）。ただし公開済みのあいだは空にできない（入力エラー）
     * - 公開済みでも保存できる（そのまま会員様の画面に出る。2026-10-18 変更：その前は公開済みなら 409）
     * - 最後に保存したトレーナーと日時を残す（evaluation_updated_by・evaluation_updated_at）
     */
    public function update(Request $request, Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): RedirectResponse
    {
        $isPublished = $personalityDiagnosisResult->evaluation_status === TraineePersonalityDiagnosisResult::EVALUATION_PUBLISHED;
        $text = $this->validateEvaluationText($request, required: false);
        if ($isPublished && $text === null) {
            throw ValidationException::withMessages(['evaluation_text' => self::EMPTY_WHILE_PUBLISHED_MESSAGE]);
        }

        $personalityDiagnosisResult->update([
            'evaluation_text' => $text,
            'evaluation_updated_by' => auth()->id(),
            'evaluation_updated_at' => now(),
        ]);

        return redirect()
            ->route('trainee-personality-diagnosis-results.show', [$trainee, $personalityDiagnosisResult])
            ->with('success', $isPublished ? '評価文を更新しました。会員様のマイページにも反映されています。' : '評価文を更新しました。');
    }

    /**
     * 評価文を公開する（POST …/{personalityDiagnosisResult}/publish。S-0313-M01）。
     *
     * 公開済みなら（2 回目の送信を含む）、入力のチェックより先に見て、何もせず S-0313 に戻す。そうでなければ、
     * 1 つのトランザクションで結果の行を排他ロックして取り直し、欄の文を保存（保存と同じ決まり。公開では必須）してから、
     * 公開した日時・公開したトレーナーを入れる（保存していない直しも公開される）。トレーニング記録は作らない
     */
    public function publish(Request $request, Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): RedirectResponse
    {
        if ($personalityDiagnosisResult->evaluation_status === TraineePersonalityDiagnosisResult::EVALUATION_PUBLISHED) {
            return $this->backToShow($trainee, $personalityDiagnosisResult, 'この評価文は、すでに公開しています。');
        }

        $text = $this->validateEvaluationText($request, required: true);

        $published = DB::transaction(function () use ($personalityDiagnosisResult, $text) {
            $result = TraineePersonalityDiagnosisResult::query()->whereKey($personalityDiagnosisResult->id)->lockForUpdate()->firstOrFail();
            if ($result->evaluation_published_at !== null) {
                return false;
            }

            $now = now();
            $result->update([
                'evaluation_text' => $text,
                'evaluation_updated_by' => auth()->id(),
                'evaluation_updated_at' => $now,
                'evaluation_published_at' => $now,
                'evaluation_published_by' => auth()->id(),
            ]);

            return true;
        });

        return $this->backToShow($trainee, $personalityDiagnosisResult, $published ? '評価文を公開しました。' : 'この評価文は、すでに公開しています。');
    }

    /**
     * 評価文の公開取消（POST …/{personalityDiagnosisResult}/unpublish。S-0313-M03。2026-10-18 追加）。
     *
     * 結果の行を排他ロックして取り直し、公開済みなら公開した日時・公開したトレーナーを NULL に戻す（評価文は残す。状態は下書き）。
     * 公開していなければ何もせず S-0313 に戻す。欄に保存していない直しがあっても保存しない（リクエストの評価文は使わない）。
     * 完了メッセージは「{名前}の公開を取り消しました。」（ほかの取消の「〜の案内を取り消しました。」にそろえる。2026-10-18 変更：その前は「評価文の公開をやめました。」）
     */
    public function unpublish(Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): RedirectResponse
    {
        $unpublished = DB::transaction(function () use ($personalityDiagnosisResult) {
            $result = TraineePersonalityDiagnosisResult::query()->whereKey($personalityDiagnosisResult->id)->lockForUpdate()->firstOrFail();
            if ($result->evaluation_published_at === null) {
                return false;
            }

            $result->update(['evaluation_published_at' => null, 'evaluation_published_by' => null]);

            return true;
        });

        return $this->backToShow($trainee, $personalityDiagnosisResult, $unpublished ? __('messages.personality_diagnosis_name').'の公開を取り消しました。' : 'この評価文は公開していません。');
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

    /**
     * スタッフ用メモを更新する（PUT …/{personalityDiagnosisResult}/staff-memo。S-0313 のスタッフ用メモの［更新］。2026-10-19 追加）。
     *
     * 評価文の［更新］とは別のルート・別のフォーム（スタッフ用メモだけを保存し、評価文・公開の状態・最終更新〔評価文の〕は変えない。
     * 逆に評価文の［更新］ではスタッフ用メモを変えない）。10,000 文字以内。改行は LF にそろえてから数える（評価文と同じ）。
     * 空（空白だけを含む）なら NULL。会員様の画面には一切出さない
     */
    public function updateStaffMemo(Request $request, Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): RedirectResponse
    {
        $text = $request->input('staff_memo');
        if (is_string($text)) {
            $request->merge(['staff_memo' => str_replace(["\r\n", "\r"], "\n", $text)]);
        }
        $validated = $request->validate([
            'staff_memo' => 'nullable|string|max:'.TraineePersonalityDiagnosisResult::STAFF_MEMO_MAX_LENGTH,
        ]);

        $personalityDiagnosisResult->update(['staff_memo' => blank($validated['staff_memo'] ?? null) ? null : $validated['staff_memo']]);

        return $this->backToShow($trainee, $personalityDiagnosisResult, 'スタッフ用メモを更新しました。');
    }

    /**
     * 評価文とスタッフ用メモの下書きを AI で作る（POST …/{personalityDiagnosisResult}/evaluation-draft。S-0313 の［AIで下書き］。2026-10-19 追加）。
     *
     * その場で AI を呼び（キューは使わない）、JSON の { evaluation, staff_memo, staff_memo_missing } を返す。何も保存しない
     * （画面の JS が 2 つの欄に入れ、トレーナーがそれぞれ［更新］で保存する）。公開済みでも作れる。何度でも作れる。
     * 失敗したら 502 と決まった文だけを返す（細かい原因はログにだけ。EvaluationDraftGenerator の実装が残す）。
     * AI の会社は EvaluationDraftGenerator の実装で決まる（今は Claude）。アクセスログには記録しない
     */
    public function draft(Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult, EvaluationDraftGenerator $generator): JsonResponse
    {
        try {
            $draft = $generator->generate($personalityDiagnosisResult);
        } catch (EvaluationDraftFailedException) {
            return response()->json(['message' => 'AIの下書きを作れませんでした。時間をおいてもう一度お試しください。'], 502);
        }

        return response()->json([
            'evaluation' => $draft->evaluation,
            'staff_memo' => $draft->staffMemo,
            'staff_memo_missing' => $draft->staffMemoMissing,
        ]);
    }

    /**
     * 評価文の入力を確かめて、保存する値を返す（更新と公開で同じ決まり。2026-10-18 に保存から切り出した）。
     *
     * - 改行は LF にそろえてから数える（愛犬アンケートの自由記述と同じ。ブラウザは改行を 1 文字と数えるため）。10,000 文字以内
     * - 空（空白だけを含む）なら null（なし）。公開では必須（空なら入力エラー）
     */
    private function validateEvaluationText(Request $request, bool $required): ?string
    {
        $text = $request->input('evaluation_text');
        if (is_string($text)) {
            $request->merge(['evaluation_text' => str_replace(["\r\n", "\r"], "\n", $text)]);
        }
        $validated = $request->validate([
            'evaluation_text' => ($required ? 'required' : 'nullable').'|string|max:'.TraineePersonalityDiagnosisResult::EVALUATION_MAX_LENGTH,
        ]);

        return blank($validated['evaluation_text'] ?? null) ? null : $validated['evaluation_text'];
    }

    /**
     * S-0313 に戻り、完了メッセージを出す（公開・公開取消の成功と、すでにその状態だったとき）
     */
    private function backToShow(Trainee $trainee, TraineePersonalityDiagnosisResult $result, string $message): RedirectResponse
    {
        return redirect()
            ->route('trainee-personality-diagnosis-results.show', [$trainee, $result])
            ->with('success', $message);
    }
}
