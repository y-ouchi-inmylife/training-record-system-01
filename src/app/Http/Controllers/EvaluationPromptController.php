<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\EvaluationDraft\PersonalityEvaluationPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 愛犬性格診断の評価文プロンプト設定（2026-10-19 追加。設計書 S-0602）。要約プロンプト（SummaryPromptController）と同じ作り。
 *
 * S-0313 の［AIで下書き］で AI に渡す指示文を、管理者が直す。値は system_settings の evaluation_prompt_current。
 * 保存したことがなければ、画面には既定の指示文（PersonalityEvaluationPrompt::DEFAULT_TEMPLATE）を出す
 */
class EvaluationPromptController extends Controller
{
    /**
     * 評価文プロンプト設定画面
     */
    public function edit(): View
    {
        return view('settings.evaluation-prompts', [
            'currentPrompt' => PersonalityEvaluationPrompt::template(),
            'placeholders' => PersonalityEvaluationPrompt::placeholders(),
        ]);
    }

    /**
     * 評価文プロンプト設定更新（5000 文字以内。要約プロンプトと同じ）
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'evaluation_prompt' => 'required|string|max:'.PersonalityEvaluationPrompt::MAX_LENGTH,
        ]);

        SystemSetting::updateOrCreate(
            ['key' => PersonalityEvaluationPrompt::SETTING_KEY],
            ['value' => $validated['evaluation_prompt']]
        );

        return redirect()->route('settings.evaluation-prompts.edit')
            ->with('success', __('messages.personality_diagnosis_name').'の評価文プロンプト設定を保存しました。');
    }
}
