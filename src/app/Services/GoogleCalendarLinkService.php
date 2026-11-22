<?php

namespace App\Services;

use App\Models\ClientUsage;

/**
 * 「Googleカレンダーに追加」のリンクの URL（2026-11 追加。requirements.md「Google カレンダーに追加」、
 * screen-design.md S-0305 セクション5・S-1602「Googleカレンダーに追加」）。
 *
 * Google カレンダーの登録画面（予定を作成）を、名前・日時・説明が入った状態で開く URL を作る。Google の API は使わない。
 * 一方向・その時点の写しで、あとで利用を変えたり削除したりしても Google カレンダーには反映されない。
 * 会員詳細の利用の行・その日の JSON（カレンダー画面）・登録と更新の直後の完了のメッセージボックス（S-0305-M10）で、ここの URL を使う
 * （URL の作り方と、今日以降かの判定を、ほかに書かない）。
 *
 * - 説明欄は会員詳細の絶対 URL だけ。**利用のメモは載せない**
 * - URL の項目と日時の書き方は Google 公式の説明が見つからず、第三者の文書 add-event-to-calendar-docs
 *   （https://github.com/InteractionDesignFoundation/add-event-to-calendar-docs/blob/main/services/google.md）に基づく。
 *   Google にログインしたブラウザで、値が入ることを確かめた。ログインしていないと紹介ページに移ることがある
 */
class GoogleCalendarLinkService
{
    private const BASE_URL = 'https://calendar.google.com/calendar/render';

    /** 利用の日時のタイムゾーン（URL で明示する） */
    private const TIMEZONE = 'Asia/Tokyo';

    /**
     * リンクの URL。利用日が今日以降の利用だけ（今日を含む。時刻は判定に使わない）。過去の利用は null（リンクを出さない）
     */
    public function urlFor(ClientUsage $usage): ?string
    {
        if ($usage->used_on->lt(today())) {
            return null;
        }

        // 空白を「%20」にするため RFC 3986 でエンコードする（既定では「+」になる）
        return self::BASE_URL.'?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $usage->schedule_label,
            'dates' => self::dateTime($usage, $usage->start_time_input).'/'.self::dateTime($usage, $usage->end_time_input),
            'ctz' => self::TIMEZONE,
            'details' => route('clients.show', $usage->client_id),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 「YYYYMMDDTHHMMSS」。末尾に Z を付けない（Z を付けると UTC の時刻になるため。タイムゾーンは ctz で渡す）
     *
     * @param  string  $time  「HH:MM」
     */
    private static function dateTime(ClientUsage $usage, string $time): string
    {
        return $usage->used_on->format('Ymd').'T'.str_replace(':', '', $time).'00';
    }
}
