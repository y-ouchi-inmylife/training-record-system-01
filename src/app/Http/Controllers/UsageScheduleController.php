<?php

namespace App\Http\Controllers;

use App\Models\ClientUsage;
use App\Services\UsageCapacityService;
use App\Support\ScheduleSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 利用の予約状況の内部 API（2026-11 追加。api-design.md 4-2-6、screen-design.md S-0305-M05「予約状況」）。
 *
 * 利用モーダルの予約状況（カレンダーとその日の予約。<x-usage-schedule>・resources/js/usage-schedule.js）と、
 * 利用の保存の前の確認（check。2026-11 追加）が読む。
 * 預かる数は事業所全体で数える（UsageCapacityService。保存時の上限の確認と同じ計算）。上限は ScheduleSetting::capacity()。
 * 権限は利用の登録・編集と同じ（routes/api.php の auth・practitioners）。参照なので、アクセスログには記録しない。
 */
class UsageScheduleController extends Controller
{
    /**
     * 月の日ごとの預かる数・予約の件数（2026-11 追加）と上限（GET /api/usage-schedule/month）
     */
    public function month(Request $request, UsageCapacityService $service): JsonResponse
    {
        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
            'exclude_usage_id' => 'nullable|integer',
        ]);
        $exclude = isset($validated['exclude_usage_id']) ? (int) $validated['exclude_usage_id'] : null;

        return response()->json([
            'month' => $validated['month'],
            'today' => today()->toDateString(),
            'capacity' => ScheduleSetting::capacity(),
            // 預かる数が 1 以上の日だけ。日がなくても JSON のオブジェクト（{}）にする
            'days' => (object) $service->monthCounts($validated['month'], $exclude),
            // 日ごとの予約の件数（2026-11 追加。カレンダーのマスの右上に出す）。件数が 1 以上の日だけ。days と同じ形
            'usage_counts' => (object) $service->monthUsageCounts($validated['month'], $exclude),
        ]);
    }

    /**
     * その日の預かる数・上限・予約の一覧（GET /api/usage-schedule/day）
     */
    public function day(Request $request, UsageCapacityService $service): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'exclude_usage_id' => 'nullable|integer',
        ]);
        $exclude = isset($validated['exclude_usage_id']) ? (int) $validated['exclude_usage_id'] : null;

        $usages = $service->usagesOn($validated['date'], $exclude)->load(['client', 'trainee']);

        return response()->json([
            'date' => $validated['date'],
            'date_label' => ClientUsage::toShortDateLabel($validated['date']),
            'capacity' => ScheduleSetting::capacity(),
            'count' => $service->dayCount($validated['date'], $exclude),
            'usages' => $usages->map(fn (ClientUsage $usage) => [
                'time_range' => $usage->time_range_label,
                'label' => self::usageLabel($usage),
            ])->values()->all(),
        ]);
    }

    /**
     * 利用の保存の前の確認（GET /api/usage-schedule/check。2026-11 追加。api-design.md 4-2-6、screen-design.md S-0305-M05）。
     * この利用を入れたときの預かる数・同じトレーニーの重なり（2026-11 追加）と、確認ダイアログが要るかを返す。
     * 判定は保存時の確認と同じ（UsageCapacityService::confirmation）。trainee_id を渡さないときは重なりを確かめない。
     * 入力が正しくないときは 422（画面は確認せずに送信し、保存時の検証に任せる）
     */
    public function check(Request $request, UsageCapacityService $service): JsonResponse
    {
        $validated = $request->validate([
            'used_on' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'trainee_id' => 'nullable|integer',
            'exclude_usage_id' => 'nullable|integer',
        ]);
        $exclude = isset($validated['exclude_usage_id']) ? (int) $validated['exclude_usage_id'] : null;
        $traineeId = isset($validated['trainee_id']) ? (int) $validated['trainee_id'] : null;

        $confirmation = $service->confirmation($validated['used_on'], $validated['start_time'], $validated['end_time'], $exclude, $traineeId);
        $overlap = $confirmation['overlap'];

        return response()->json([
            // どちらかの確認が要るとき true（2026-11 変更：その前は上限の確認だけ）
            'needs_confirmation' => $confirmation['needs_confirmation'],
            'needs_capacity_confirmation' => $confirmation['needs_capacity_confirmation'],
            'count' => $confirmation['count'],
            'capacity' => $confirmation['capacity'],
            'peak_start' => ClientUsage::toDisplayTime($confirmation['start']),
            'peak_end' => ClientUsage::toDisplayTime($confirmation['end']),
            // 同じトレーニーの重なり（2026-11 追加）
            'needs_overlap_confirmation' => $confirmation['needs_overlap_confirmation'],
            'overlap_start' => $overlap === null ? null : ClientUsage::toDisplayTime($overlap['start']),
            'overlap_end' => $overlap === null ? null : ClientUsage::toDisplayTime($overlap['end']),
            'trainee_label' => $confirmation['trainee_label'],
            'date_label' => ClientUsage::toShortDateLabel($validated['used_on']),
        ]);
    }

    /**
     * 「トレーニー（会員名）」。名前が未登録なら「名前未登録（会員名）」、トレーニーがない利用は「（会員名）」。
     * 会員名は Client::display_name（姓名。名が空のとき末尾に空白を残さない）
     */
    private static function usageLabel(ClientUsage $usage): string
    {
        $clientName = trim((string) $usage->client?->display_name);

        return ($usage->trainee?->display_name ?? '').'（'.$clientName.'）';
    }
}
