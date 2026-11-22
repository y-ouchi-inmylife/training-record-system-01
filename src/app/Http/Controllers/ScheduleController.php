<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * カレンダー画面（S-1602。2026-11 追加。要件定義書 6-17-12、screen-design.md S-1602、api-design.md 4-1-17）。
 *
 * 会員を選ばずに、事業所全体の利用（予約）を月のカレンダーとその日の予約の一覧で見る、見るだけの画面。
 * 利用の登録・編集・削除は今までどおり会員詳細（S-0305）の利用モーダルで行う。
 * カレンダーと一覧は利用モーダルと同じ部品（<x-usage-schedule>・resources/js/usage-schedule.js）で、
 * 中身は画面が内部 API（api.usage-schedule.month・day）から読む。ここは選んでいる日と今日を渡すだけ。
 * 参照なのでアクセスログは記録しない（LogAccess の ACTION_MAP に足さない）。
 */
class ScheduleController extends Controller
{
    /**
     * カレンダー画面。選んでいる日は ?date=YYYY-MM-DD。ないとき・正しくない日付のときは、入力エラーにせず今日にする
     */
    public function index(Request $request): View
    {
        $today = today()->toDateString();

        return view('schedule.index', [
            'date' => self::validDate($request->query('date')) ?? $today,
            'today' => $today,
        ]);
    }

    /**
     * 「YYYY-MM-DD」の形で、ある日付ならその文字列。そうでなければ null（形が違う・ない日付〔2026-02-30 など〕・配列など）
     */
    private static function validDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
}
