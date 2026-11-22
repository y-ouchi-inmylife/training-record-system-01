<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * 予定の設定（S-1601。2026-11 追加。設計書 requirements.md 6-17-10・db-schema.md DS-0100）の値を読む。
 *
 * 営業開始時刻・営業終了時刻は、利用を登録するときの開始時刻・終了時刻の初期値になる（用語集 No.42）。
 * 同時に預かれる数（用語集 No.43。requirements.md 6-17-11。2026-11 追加）は、利用の予約状況の満・超過の判定と、
 * 利用の保存時の上限の確認に使う（数え方は App\Services\UsageCapacityService）。
 * 値は system_settings の schedule_open_time・schedule_close_time（「HH:MM」の文字列）・schedule_capacity（数字の文字列）。
 * **行がないときは、ここに持つ初期値（08:00・19:00・3）で動く**（本番ではシーダーを流さず、migrate だけで反映するため）。
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

    /** 同時に預かれる数のキー（2026-11 追加） */
    public const CAPACITY_KEY = 'schedule_capacity';

    /** 同時に預かれる数の初期値（行がないとき・シーダー。2026-11 追加） */
    public const DEFAULT_CAPACITY = 3;

    /** 同時に預かれる数の範囲（画面の検証。2026-11 追加） */
    public const MIN_CAPACITY = 1;

    public const MAX_CAPACITY = 99;

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
     * 同時に預かれる数（2026-11 追加）。行がない・空・整数として読めない・1 未満のときは初期値
     */
    public static function capacity(): int
    {
        $value = self::read(self::CAPACITY_KEY, (string) self::DEFAULT_CAPACITY);
        $capacity = filter_var($value, FILTER_VALIDATE_INT);

        return $capacity === false || $capacity < self::MIN_CAPACITY ? self::DEFAULT_CAPACITY : $capacity;
    }

    /**
     * 営業開始時刻・営業終了時刻・同時に預かれる数を保存する（行がなければ作り、あれば更新する。預かる数は 2026-11 追加）
     */
    public static function save(string $openTime, string $closeTime, int $capacity): void
    {
        SystemSetting::updateOrCreate(['key' => self::OPEN_TIME_KEY], ['value' => $openTime]);
        SystemSetting::updateOrCreate(['key' => self::CLOSE_TIME_KEY], ['value' => $closeTime]);
        SystemSetting::updateOrCreate(['key' => self::CAPACITY_KEY], ['value' => (string) $capacity]);
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
