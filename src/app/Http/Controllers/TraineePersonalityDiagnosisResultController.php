<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
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
 * 愛犬性格診断詳細（S-0313）とアドバイスの更新・公開・公開取消（2026-10-18 追加。要件定義書 6-16-15・6-16-16）、
 * 愛犬性格診断の結果の削除（要件定義書 6-16-14、api-design.md 4-1-16。2026-10 追加。2026-10-18 変更：送信元を S-0309 から S-0313 に移した）。
 *
 * 詳細とアドバイス：
 * - トレーナー（一般・管理者）なら見られる・更新・公開・公開取消ができる。{personalityDiagnosisResult} は {trainee} の結果に限る（scopeBindings）
 * - アドバイスの状態（なし・下書き・公開済み）は列に持たず、アドバイスと公開した日時（evaluation_published_at）から決める（evaluation_status）
 * - 公開はアドバイスそのものに「公開済み」の印（公開した日時・公開したトレーナー）を付ける。トレーニング記録は作らない
 *   （2026-10-18 変更：評価文と記録の本文の二重持ちをやめるため。その前は、公開で評価文を本文にしたトレーニング記録を作っていた）
 * - 公開済みのアドバイスも更新できる（すぐ会員様の画面に出る）。ただし公開済みのあいだは空にできない
 * - 公開・公開取消は、結果の行を排他ロックして状態を確かめ直す。すでに公開済み・すでに公開を取り消しているときは、
 *   エラーにせず S-0313 に戻す（2 回押されても困らないように）
 * - アクセスログ：公開・公開取消（publish_／unpublish_trainee_personality_diagnosis_evaluation）は LogAccess ミドルウェアで記録する。
 *   アドバイス・スタッフ用メモの編集（edit_trainee_personality_diagnosis_evaluation・edit_trainee_personality_diagnosis_staff_memo）は、
 *   変わった方だけをこのコントローラで記録する（2026-10-19 変更：［更新］を 1 つにまとめたため。その前はルートごとにミドルウェアで記録していた）
 *
 * 結果の削除：
 *
 * 性格診断は前回・初回と比べるため、間違った回答（別の愛犬のつもりで答えた、試しの回答、誤送信など）が残ると比較がずれる。
 * そのため、管理者が結果を 1 件ずつ削除できるようにする。愛犬アンケートの回答は今までどおり削除できない。
 *
 * - 権限はトレーニーの削除（TraineeController::destroy）と同じ：管理者だけ（同じ確かめ方・同じ文言の 403）。
 *   システム管理者は、トレーニー管理のルートの practitioners ミドルウェアで 403
 * - {personalityDiagnosisResult} は {trainee} の結果に限る（ルートの scopeBindings。別のトレーニーの結果なら 404）
 * - 物理削除。結果に使った案内（回答済みのトークン）は消さない。公開済みのアドバイスも一緒に消える
 * - アクセスログ（delete_trainee_personality_diagnosis_result）は LogAccess ミドルウェアで記録する
 */
class TraineePersonalityDiagnosisResultController extends Controller
{
    /** 公開済みのあいだに空で更新しようとしたときの文言（設計書 S-0313「バリデーションチェック」） */
    private const EMPTY_WHILE_PUBLISHED_MESSAGE = '公開中のアドバイスは空にできません。公開を取り消してから消してください。';

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
     * アドバイスとスタッフ用メモを更新する（PUT /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}。
     * S-0313 のカード「アドバイスとスタッフ用メモ」の［更新］。2026-10-19 変更：ユーザーレビューで［更新］が 2 つあり分かりにくかったため、
     * 2 つを 1 つのフォームで受け取って両方まとめて保存する。その前はアドバイスだけを保存し、スタッフ用メモは別のルート〔PUT …/staff-memo〕）。
     *
     * - どちらも 10,000 文字以内。改行は LF にそろえてから数える（ブラウザは改行を 1 文字と数えるため）。空（空白だけを含む）なら NULL
     * - 公開済みのあいだはアドバイスを空にできない（入力エラー）。公開済みでも保存できる（そのまま会員様の画面に出る）
     * - スタッフ用メモが送られてこなければ（キーがなければ）変えない
     * - アドバイスの最終更新（evaluation_updated_by・evaluation_updated_at）は、アドバイスが変わったときだけ変える
     * - アクセスログは変わった方だけを残す（どちらも変わらなければ残さない）
     */
    public function update(Request $request, Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): RedirectResponse
    {
        $isPublished = $personalityDiagnosisResult->evaluation_status === TraineePersonalityDiagnosisResult::EVALUATION_PUBLISHED;
        [$text, $memo, $hasMemo] = $this->validateTexts($request, evaluationRequired: false);
        if ($isPublished && $text === null) {
            throw ValidationException::withMessages(['evaluation_text' => self::EMPTY_WHILE_PUBLISHED_MESSAGE]);
        }

        $evaluationChanged = $this->saveTexts($request, $trainee, $personalityDiagnosisResult, $text, $memo, $hasMemo);

        return redirect()
            ->route('trainee-personality-diagnosis-results.show', [$trainee, $personalityDiagnosisResult])
            ->with('success', $isPublished && $evaluationChanged
                ? 'アドバイスとスタッフ用メモを更新しました。会員様のマイページにも反映されています。'
                : 'アドバイスとスタッフ用メモを更新しました。');
    }

    /**
     * アドバイスを公開する（POST …/{personalityDiagnosisResult}/publish。S-0313-M01）。
     *
     * 公開済みなら（2 回目の送信を含む）、入力のチェックより先に見て、何もせず S-0313 に戻す。そうでなければ、
     * 1 つのトランザクションで結果の行を排他ロックして取り直し、欄の文を保存（保存と同じ決まり。公開では必須）してから、
     * 公開した日時・公開したトレーナーを入れる（保存していない直しも公開される）。トレーニング記録は作らない。
     * 2026-10-19 変更：スタッフ用メモの欄の文も一緒に保存してから公開する（1 つの［更新］にまとめたため。直したまま押しても、
     * 保存し忘れた古い文が残らないように）。保存でアドバイス・スタッフ用メモが変わっていれば、変わった方の編集の記録も残す
     * （公開の記録は今までどおり LogAccess ミドルウェア）
     */
    public function publish(Request $request, Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): RedirectResponse
    {
        if ($personalityDiagnosisResult->evaluation_status === TraineePersonalityDiagnosisResult::EVALUATION_PUBLISHED) {
            return $this->backToShow($trainee, $personalityDiagnosisResult, 'このアドバイスは、すでに公開しています。');
        }

        [$text, $memo, $hasMemo] = $this->validateTexts($request, evaluationRequired: true);

        $changes = DB::transaction(function () use ($personalityDiagnosisResult, $text, $memo, $hasMemo) {
            $result = TraineePersonalityDiagnosisResult::query()->whereKey($personalityDiagnosisResult->id)->lockForUpdate()->firstOrFail();
            if ($result->evaluation_published_at !== null) {
                return null;
            }

            $evaluationChanged = $result->evaluation_text !== $text;
            $memoChanged = $hasMemo && $result->staff_memo !== $memo;
            $now = now();
            // アドバイスの最終更新は、［更新］と同じくアドバイスの文が変わったときだけ変える（2026-10-19 変更：最終更新を「文を直した日」に
            // そろえた。その前は公開するたびに入れていた）。公開した日時・公開したトレーナーは公開するたびに入れる
            $result->update([
                'evaluation_text' => $text,
                ...($evaluationChanged ? ['evaluation_updated_by' => auth()->id(), 'evaluation_updated_at' => $now] : []),
                'evaluation_published_at' => $now,
                'evaluation_published_by' => auth()->id(),
                ...($hasMemo ? ['staff_memo' => $memo] : []),
            ]);

            return [$evaluationChanged, $memoChanged];
        });
        $published = $changes !== null;
        if ($published) {
            $this->logEdits($request, $trainee, ...$changes);
        }

        return $this->backToShow($trainee, $personalityDiagnosisResult, $published ? 'アドバイスを公開しました。' : 'このアドバイスは、すでに公開しています。');
    }

    /**
     * アドバイスの公開取消（POST …/{personalityDiagnosisResult}/unpublish。S-0313-M03。2026-10-18 追加）。
     *
     * 公開していなければ（2 回目の送信を含む）、入力のチェックより先に見て、何もせず S-0313 に戻す。そうでなければ、
     * 1 つのトランザクションで結果の行を排他ロックして取り直し、公開した日時・公開したトレーナーを NULL に戻し（状態は下書き）、
     * あわせて欄のアドバイスとスタッフ用メモを保存する（［公開］と同じ考え。公開を取り消したうえで保存するので、アドバイスは空でもよい）。
     * アドバイス・スタッフ用メモは、送られてこなければ変えない（古い画面から送られたときに消さないため）。
     * アドバイスの最終更新は、アドバイスの文が変わったときだけ変える。保存で変わった方の編集の記録を残す（公開取消の記録は LogAccess ミドルウェア）。
     * 2026-10-19 変更：直しかけのまま公開取消すると、ブラウザの離れる確認が M03 に重なって出て、進むと直した文が元に戻ってしまい
     * 分かりにくかったため。その前は、欄に保存していない直しがあっても保存しなかった（リクエストのアドバイスは使わなかった）。
     * 完了メッセージは「{名前}の公開を取り消しました。」（ほかの取消の「〜の案内を取り消しました。」にそろえる。2026-10-18 変更：その前は「評価文の公開をやめました。」）
     */
    public function unpublish(Request $request, Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult): RedirectResponse
    {
        if ($personalityDiagnosisResult->evaluation_published_at === null) {
            return $this->backToShow($trainee, $personalityDiagnosisResult, 'このアドバイスは公開していません。');
        }

        [$text, $memo, $hasMemo] = $this->validateTexts($request, evaluationRequired: false);
        $hasText = $request->exists('evaluation_text');

        $changes = DB::transaction(function () use ($personalityDiagnosisResult, $text, $hasText, $memo, $hasMemo) {
            $result = TraineePersonalityDiagnosisResult::query()->whereKey($personalityDiagnosisResult->id)->lockForUpdate()->firstOrFail();
            if ($result->evaluation_published_at === null) {
                return null;
            }

            $evaluationChanged = $hasText && $result->evaluation_text !== $text;
            $memoChanged = $hasMemo && $result->staff_memo !== $memo;
            // 公開を取り消し、そのうえで（下書きとして）2 つの欄を保存する。アドバイスの最終更新は文が変わったときだけ
            $result->update([
                'evaluation_published_at' => null,
                'evaluation_published_by' => null,
                ...($evaluationChanged ? ['evaluation_text' => $text, 'evaluation_updated_by' => auth()->id(), 'evaluation_updated_at' => now()] : []),
                ...($memoChanged ? ['staff_memo' => $memo] : []),
            ]);

            return [$evaluationChanged, $memoChanged];
        });
        $unpublished = $changes !== null;
        if ($unpublished) {
            $this->logEdits($request, $trainee, ...$changes);
        }

        return $this->backToShow($trainee, $personalityDiagnosisResult, $unpublished ? __('messages.personality_diagnosis_name').'の公開を取り消しました。' : 'このアドバイスは公開していません。');
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
     * アドバイスとスタッフ用メモを AI で自動生成する（POST …/{personalityDiagnosisResult}/evaluation-draft。S-0313 の［AIで自動生成］。2026-10-19 追加。
     * 2026-10-19 変更：ボタン名・呼び名を［AIで下書き］から変えた。メソッド・ルートの名前は変えない）。
     *
     * その場で AI を呼び（キューは使わない）、JSON の { evaluation, staff_memo, staff_memo_missing } を返す。何も保存しない
     * （画面の JS が 2 つの欄に入れ、トレーナーが 1 つの［更新］で保存する）。公開済みでも作れる。何度でも作れる。
     * 失敗したら 502 と決まった文だけを返す（細かい原因はログにだけ。EvaluationDraftGenerator の実装が残す）。
     * AI の会社は EvaluationDraftGenerator の実装で決まる（今は Claude）。アクセスログには記録しない
     */
    public function draft(Trainee $trainee, TraineePersonalityDiagnosisResult $personalityDiagnosisResult, EvaluationDraftGenerator $generator): JsonResponse
    {
        try {
            $draft = $generator->generate($personalityDiagnosisResult);
        } catch (EvaluationDraftFailedException) {
            return response()->json(['message' => 'AIで自動生成できませんでした。時間をおいてもう一度お試しください。'], 502);
        }

        return response()->json([
            'evaluation' => $draft->evaluation,
            'staff_memo' => $draft->staffMemo,
            'staff_memo_missing' => $draft->staffMemoMissing,
        ]);
    }

    /**
     * アドバイスとスタッフ用メモの入力を確かめて、保存する値を返す（更新と公開で同じ決まり。2026-10-18 に保存から切り出した。
     * 2026-10-19 変更：スタッフ用メモも一緒に確かめる）。
     *
     * - 改行は LF にそろえてから数える（ブラウザは改行を 1 文字と数えるため）。どちらも 10,000 文字以内
     * - 空（空白だけを含む）なら null。アドバイスは公開では必須（空なら入力エラー）
     * - スタッフ用メモのキーが送られてこなければ、3 つ目を false にして返す（変えない）
     *
     * @return array{0: ?string, 1: ?string, 2: bool} [アドバイス, スタッフ用メモ, スタッフ用メモが送られてきたか]
     */
    private function validateTexts(Request $request, bool $evaluationRequired): array
    {
        foreach (['evaluation_text', 'staff_memo'] as $key) {
            $value = $request->input($key);
            if (is_string($value)) {
                $request->merge([$key => str_replace(["\r\n", "\r"], "\n", $value)]);
            }
        }
        $validated = $request->validate([
            'evaluation_text' => ($evaluationRequired ? 'required' : 'nullable').'|string|max:'.TraineePersonalityDiagnosisResult::EVALUATION_MAX_LENGTH,
            'staff_memo' => 'nullable|string|max:'.TraineePersonalityDiagnosisResult::STAFF_MEMO_MAX_LENGTH,
        ]);

        $text = blank($validated['evaluation_text'] ?? null) ? null : $validated['evaluation_text'];
        $memo = blank($validated['staff_memo'] ?? null) ? null : $validated['staff_memo'];

        return [$text, $memo, $request->exists('staff_memo')];
    }

    /**
     * アドバイスとスタッフ用メモを保存し、変わった方だけアクセスログを残す（［更新］。2026-10-19 追加）。
     * アドバイスの最終更新は、アドバイスが変わったときだけ変える。アドバイスが変わったかを返す
     */
    private function saveTexts(Request $request, Trainee $trainee, TraineePersonalityDiagnosisResult $result, ?string $text, ?string $memo, bool $hasMemo): bool
    {
        $evaluationChanged = $result->evaluation_text !== $text;
        $memoChanged = $hasMemo && $result->staff_memo !== $memo;

        $attributes = [];
        if ($evaluationChanged) {
            $attributes += ['evaluation_text' => $text, 'evaluation_updated_by' => auth()->id(), 'evaluation_updated_at' => now()];
        }
        if ($memoChanged) {
            $attributes['staff_memo'] = $memo;
        }
        if ($attributes !== []) {
            $result->update($attributes);
        }
        $this->logEdits($request, $trainee, $evaluationChanged, $memoChanged);

        return $evaluationChanged;
    }

    /**
     * アドバイス・スタッフ用メモの編集のアクセスログを、変わった方だけ残す（2026-10-19 追加。種類は今までのもの。
     * 対象・IP・ブラウザの残し方は LogAccess ミドルウェアと同じ。対象はトレーニー）
     */
    private function logEdits(Request $request, Trainee $trainee, bool $evaluationChanged, bool $memoChanged): void
    {
        $actions = array_keys(array_filter([
            'edit_trainee_personality_diagnosis_evaluation' => $evaluationChanged,
            'edit_trainee_personality_diagnosis_staff_memo' => $memoChanged,
        ]));
        foreach ($actions as $action) {
            AccessLog::create([
                'trainer_id' => auth()->id(),
                'action' => $action,
                'target_type' => 'Trainee',
                'target_id' => $trainee->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
            ]);
        }
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
