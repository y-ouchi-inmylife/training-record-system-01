<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * 予定の設定（S-1601。2026-11 追加。設計書 requirements.md 6-17-10・db-schema.md DS-0100）の値を読む。
 *
 * 営業開始時刻・営業終了時刻は、利用を登録するときの開始時刻・終了時刻の初期値になる（用語集 No.42）。
 * 値は system_settings の schedule_open_time・schedule_close_time（「HH:MM」の文字列）。
 * **行がないときは、ここに持つ初期値（08:00・19:00）で動く**（本番ではシーダーを流さず、migrate だけで反映するため）。
 * キャッシュは持たない（ほかの設定値と同じく、使うたびに読む）。
 */
class ScheduleSetting
{
    public const OPEN_TIME_KEY = 'schedule_open_time';

    public const CLOSE_TIME_KEY = 'schedule_close_time';

    /** 営業開始時刻の初期値（行がないとき・シーダー） */
    public const DEFAULT_OPEN_TIME = '08:00';

    /** 営業終了時刻の初期値（行がないとき・シーダー） */
    public const DEFAULT_CLOSE_TIME = '19:00';

    /**
     * 営業開始時刻（HH:MM）
     */
    public static function openTime(): string
    {
        return self::read(self::OPEN_TIME_KEY, self::DEFAULT_OPEN_TIME);
    }

    /**
     * 営業終了時刻（HH:MM）
     */
    public static function closeTime(): string
    {
        return self::read(self::CLOSE_TIME_KEY, self::DEFAULT_CLOSE_TIME);
    }

    /**
     * 営業開始時刻・営業終了時刻を保存する（行がなければ作り、あれば更新する）
     */
    public static function save(string $openTime, string $closeTime): void
    {
        SystemSetting::updateOrCreate(['key' => self::OPEN_TIME_KEY], ['value' => $openTime]);
        SystemSetting::updateOrCreate(['key' => self::CLOSE_TIME_KEY], ['value' => $closeTime]);
    }

    /**
     * 値を読む。行がない・空のときは初期値
     */
    private static function read(string $key, string $default): string
    {
        $value = SystemSetting::where('key', $key)->value('value');

        return blank($value) ? $default : (string) $value;
    }
}
