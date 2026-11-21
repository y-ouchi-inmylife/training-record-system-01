<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleSettingRequest;
use App\Support\ScheduleSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 予定の設定画面（S-1601。2026-11 追加。設計書 screen-design.md 3-16・requirements.md 6-17-10）。
 *
 * 利用を登録するときの開始時刻・終了時刻の初期値（営業開始時刻・営業終了時刻）を、管理者が変える。
 * 変えても登録済みの利用の時刻は変わらない。
 * アクセスログは記録しない（要約プロンプト・愛犬性格診断のプロンプトの設定の更新と同じ。LogAccess の対象にしない）。
 */
class ScheduleSettingController extends Controller
{
    /**
     * 予定の設定画面
     */
    public function edit(): View
    {
        return view('settings.schedule', [
            'openTime' => ScheduleSetting::openTime(),
            'closeTime' => ScheduleSetting::closeTime(),
        ]);
    }

    /**
     * 予定の設定の更新（行がなければ作り、あれば更新する）
     */
    public function update(ScheduleSettingRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        ScheduleSetting::save($validated['schedule_open_time'], $validated['schedule_close_time']);

        return redirect()->route('settings.schedule.edit')
            ->with('success', '予定の設定を更新しました。');
    }
}
