<?php

namespace App\Http\Controllers;

use App\Http\Requests\TraineeRequest;
use App\Models\Client;
use App\Models\Trainee;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * トレーニー（D-0700）の CRUD コントローラ。
 *
 * ClientController の書き方に合わせている：
 * - `store` / `update` で `updated_by` はコントローラで明示代入（モデルイベント不使用）
 * - `destroy` は管理者のみ（`isAdmin()` チェック）
 * - フラッシュメッセージのキーは `success` / `error`
 */
class TraineeController extends Controller
{
    /**
     * トレーニー登録画面（S-0308）
     *
     * URL パラメータの会員に紐づくトレーニーを新規登録する。
     */
    public function create(Client $client): View
    {
        return view('trainees.create', compact('client'));
    }

    /**
     * トレーニー登録処理
     */
    public function store(TraineeRequest $request, Client $client): RedirectResponse
    {
        $validated = $request->validated();
        $validated['client_id'] = $client->id;
        $validated['updated_by'] = auth()->id();

        // 愛犬アンケート案内はここでは発行しない（2026-10 変更。要件定義書 6-16-1）。案内の発行は会員詳細
        // （S-0305）の［愛犬アンケートの案内を発行］だけで行う。以前の「愛犬アンケートの案内を発行する」のチェックの値
        // （issue_intake_survey）が送られてきても、validated() に含まれないため使われない
        Trainee::create($validated);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'トレーニーを登録しました。');
    }

    /**
     * トレーニー詳細画面（S-0309）
     *
     * 段階②で計測値の一覧・登録・編集・削除も本画面に含める。登録・編集は
     * モーダル（`_measurement-modal.blade.php`）で行うため、`create` / `edit` の
     * 画面遷移は持たない。
     */
    public function show(Trainee $trainee): View
    {
        // latestIntakeSurveyResponse と activeIntakeSurveyToken は愛犬アンケートの欄の状態と、
        // 削除確認の文言に使う（2026-10 追加）。personalityDiagnosisResults は性格診断の欄の結果の一覧
        // （新しい順。2026-10 追加。何件あってもクエリは 1 本）。personalityDiagnosisResults と activePersonalityDiagnosisToken は
        // 削除確認の文言にも使う（2026-10 追加）。結果の表の評価文の状態は結果の列だけで決まる（2026-10-18）
        $trainee->load(['client', 'measurements', 'latestIntakeSurveyResponse', 'activeIntakeSurveyToken', 'personalityDiagnosisResults', 'activePersonalityDiagnosisToken']);

        // 削除確認ダイアログに件数を含めるため、コントローラで数えて渡す。
        $measurementCount = $trainee->measurements->count();

        // 写真＋体重推移グラフの表示データ（2026-09 追加、S-0309 設計方針参照）。
        // 会員側 Client\DashboardController::buildWeightCharts() と同じ形（id / name /
        // photoUrl / datasets）を Trainee::getWeightChartDataAttribute() から取る。
        // measurements は上の $trainee->load(['client', 'measurements']) で既に読み込み済み。
        $weightChart = $trainee->weight_chart_data;

        // 削除確認ダイアログの文言はコントローラ側で組み立てて Blade に渡す
        // （$deleteConfirmMessage）。Blade 内で @json() を使って onsubmit 属性に
        // 埋め込む形にすると、@json() が出力する "..." が onsubmit="..." の
        // ダブルクォート境界と競合して confirm() が発火せず、確認なしで削除される
        // 不具合が発生したため（2026-09）。他の onsubmit="return confirm('...')" と
        // 同じシングルクォート形式で埋め込む。**文言にシングルクォート・改行を
        // 含めないこと**（含めると onsubmit 内の JS 文字列リテラルが壊れる）。
        //
        // 愛犬アンケート・性格診断（2026-10 追加。要件定義書 6-16-4、設計書 S-0309「削除」）：計測値の文の後ろに、
        // 消えるもの（愛犬アンケートの回答・性格診断の結果のうち、あるものだけを「と」でつなぎ「〜も一緒に削除されます。」）、
        // 使えなくなる未回答の案内（愛犬アンケート案内・性格診断の案内のうち、あるものだけを「と」でつなぎ
        // 「発行済みの〜も使えなくなります。」）をこの順で足し、最後を「このトレーニーを削除しますか？」で締める。
        // 文と文は区切りなしで続ける（confirm() では改行が使えないため）。どれもないときは、これまでの文のまま
        // （0 件のときの出し方を含めて変えない）。2026-10 変更：性格診断の結果・案内を足した。
        // 2026-10-18 追加：公開済みの愛犬性格診断の評価文があるときは、消えるものの文のあとに
        // 「公開済みの愛犬性格診断の評価文も、会員様のマイページから消えます。」を足す
        // （2026-10-18 変更：その前は、結果から作ったトレーニング記録が残ることの文だった）
        $measurementSentence = $measurementCount > 0
            ? "このトレーニーには {$measurementCount} 件の計測値が登録されています。トレーニーを削除すると計測値も一緒に削除されます。"
            : '';
        $deleted = array_keys(array_filter([
            __('messages.intake_survey_name').'の回答' => $trainee->latestIntakeSurveyResponse !== null,
            __('messages.personality_diagnosis_name').'の結果' => $trainee->personalityDiagnosisResults->isNotEmpty(),
        ]));
        $invalidated = array_keys(array_filter([
            __('messages.intake_survey_name').'の案内' => $trainee->activeIntakeSurveyToken !== null,
            __('messages.personality_diagnosis_name').'の案内' => $trainee->activePersonalityDiagnosisToken !== null,
        ]));
        $intakeSurveySentence = '';
        if ($deleted !== []) {
            $intakeSurveySentence .= implode('と', $deleted).'も一緒に削除されます。';
        }
        if ($trainee->personalityDiagnosisResults->contains(fn ($result) => $result->evaluation_published_at !== null)) {
            $intakeSurveySentence .= '公開済みの'.__('messages.personality_diagnosis_name').'の評価文も、会員様のマイページから消えます。';
        }
        if ($invalidated !== []) {
            $intakeSurveySentence .= '発行済みの'.implode('と', $invalidated).'も使えなくなります。';
        }
        if ($intakeSurveySentence !== '') {
            $deleteConfirmMessage = $measurementSentence.$intakeSurveySentence.'このトレーニーを削除しますか？';
        } else {
            $deleteConfirmMessage = $measurementCount > 0
                ? $measurementSentence.'削除しますか？'
                : 'このトレーニーを削除しますか？';
        }

        // 計測値モーダルの新規登録時の初期日時はここでは渡さない。
        // モーダルを開いた瞬間のブラウザ時刻を JavaScript でセットする
        // （設計書 S-0309「新規登録時の初期値」参照）。以前はここで
        // Carbon::now() から $defaultMeasuredDate / $defaultMeasuredTime を
        // 組み立てて渡していたが、ページ読み込み時に確定するため画面を
        // 開いたまま時間が経つと古い日時が入る不具合があった。JS に一本化
        // することで日時管理の二重化も解消する。

        return view('trainees.show', compact(
            'trainee',
            'measurementCount',
            'deleteConfirmMessage',
            'weightChart'
        ));
    }

    /**
     * トレーニー編集画面（S-0310）
     */
    public function edit(Trainee $trainee): View
    {
        $trainee->load('client');

        return view('trainees.edit', compact('trainee'));
    }

    /**
     * トレーニー更新処理
     */
    public function update(TraineeRequest $request, Trainee $trainee): RedirectResponse
    {
        $validated = $request->validated();
        $validated['updated_by'] = auth()->id();
        $trainee->update($validated);

        return redirect()
            ->route('trainees.show', $trainee)
            ->with('success', 'トレーニー情報を更新しました。');
    }

    /**
     * トレーニー削除処理（管理者のみ）
     *
     * 削除後は元の会員詳細画面へ戻すため、削除前に client_id を退避してから使う。
     */
    public function destroy(Trainee $trainee): RedirectResponse
    {
        // 管理トレーナーのみ削除可能（ClientController::destroy と同じ形・同じ文言）
        if (! auth()->user()->isAdmin()) {
            abort(403, '管理者のみ削除できます。');
        }

        $clientId = $trainee->client_id;
        $trainee->delete();

        return redirect()
            ->route('clients.show', $clientId)
            ->with('success', 'トレーニーを削除しました。');
    }
}
