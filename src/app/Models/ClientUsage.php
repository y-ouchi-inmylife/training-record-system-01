<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 利用モデル（D-1000）
 *
 * 契約回数のうち使った回数の記録。どの契約（ClientContract）の分かを
 * client_contract_id で持つ（NULL は「契約なし」＝未割り当て）。契約を削除すると
 * DB の外部キー（ON DELETE SET NULL）で「契約なし」に戻る。トレーニング記録
 * （TrainingRecord）とはひも付けない（記録の件数と利用回数は一致しないため）。
 * 利用 1 件は 1 回分（回数のカラムは持たない。2 回分使ったときは 2 件登録する）。
 * 残りのない契約への割り当ては ClientUsageRequest でエラーにするが、
 * 会員の残り回数がマイナスになる登録は止めない。
 * 削除は物理削除、権限は一般トレーナーも可（設計書 requirements.md 6-17-6）。
 *
 * どのトレーニー（Trainee）の分かを trainee_id で持つ（2026-11 追加。NULL は「トレーニーなし」）。
 * トレーニーを削除すると DB の外部キー（ON DELETE SET NULL）で「トレーニーなし」になり、利用そのものは残る。
 * 開始時刻・終了時刻（start_time・end_time）も持つ（2026-11 追加）。DB からは「HH:MM:SS」の文字列で返る。
 * 利用日が今日より後の利用は予定として扱う（状態の列は持たない。設計書 requirements.md 6-17-9）。
 */
class ClientUsage extends Model
{
    protected $fillable = [
        'client_id',
        'client_contract_id',
        'trainee_id',
        'used_on',
        'start_time',
        'end_time',
        'note',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'used_on' => 'date',
        ];
    }

    /**
     * 会員
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * 割り当てた契約（D-0900）。未割り当て（「契約なし」）なら null
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ClientContract::class, 'client_contract_id');
    }

    /**
     * どのトレーニーの分か（2026-11 追加）。「トレーニーなし」なら null
     */
    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    /**
     * 開始時刻を「HH:MM」で返す（入力欄に入れる値。DB からは「HH:MM:SS」で返るため。2026-11 追加）
     */
    public function getStartTimeInputAttribute(): string
    {
        return self::toInputTime($this->start_time);
    }

    /**
     * 終了時刻を「HH:MM」で返す（入力欄に入れる値。2026-11 追加）
     */
    public function getEndTimeInputAttribute(): string
    {
        return self::toInputTime($this->end_time);
    }

    /**
     * 時刻の表示「8:00〜19:00」（時は先頭の 0 を付けない、分は 2 桁。設計書 screen-design.md S-0305 セクション5。2026-11 追加）
     */
    public function getTimeRangeLabelAttribute(): string
    {
        return self::toDisplayTime($this->start_time).'〜'.self::toDisplayTime($this->end_time);
    }

    /**
     * 会員名（Client::display_name。名が空のとき末尾に空白を残さない。2026-11 追加：UsageScheduleController から移した）
     */
    public function getScheduleClientNameAttribute(): string
    {
        return trim((string) $this->client?->display_name);
    }

    /**
     * トレーニーの名前（名前が未登録なら「名前未登録」。トレーニーがない利用は空文字。2026-11 追加：UsageScheduleController から移した）
     */
    public function getScheduleTraineeNameAttribute(): string
    {
        return (string) ($this->trainee?->display_name ?? '');
    }

    /**
     * 「トレーニー（会員名）」。名前が未登録なら「名前未登録（会員名）」、トレーニーがない利用は「会員名」だけ
     * （かっこを付けない。2026-11 変更：その前は「（会員名）」で、かっこだけが残って見えたため）。
     * 予約状況（UsageScheduleController）と「Googleカレンダーに追加」の予定の名前（GoogleCalendarLinkService）で使う
     * （2026-11 追加：UsageScheduleController から移した）
     */
    public function getScheduleLabelAttribute(): string
    {
        if ($this->trainee === null) {
            return $this->schedule_client_name;
        }

        return $this->schedule_trainee_name.'（'.$this->schedule_client_name.'）';
    }

    /**
     * 曜日付きの利用日「2026/11/26(木)」（曜日は日本語の 1 文字。会員詳細の利用の行と、登録・更新の完了のメッセージボックスで使う。
     * 2026-11 追加：ContractTicketService から移した）
     */
    public function getUsedOnLabelAttribute(): string
    {
        return $this->used_on->format('Y/m/d').'('.self::WEEKDAYS[$this->used_on->dayOfWeek].')';
    }

    /** 曜日（日曜始まり） */
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /**
     * 日付の短い見出し「n/j(曜)」（例「11/26(木)」。2026-11 追加。予約状況のその日の予約の見出しと、保存時の上限の確認の注意に使う）
     */
    public static function toShortDateLabel(\DateTimeInterface|string $date): string
    {
        $date = \Illuminate\Support\Carbon::parse($date);

        return $date->format('n/j').'('.self::WEEKDAYS[$date->dayOfWeek].')';
    }

    /**
     * 「HH:MM:SS」または「HH:MM」を「HH:MM」に直す。空なら空文字
     */
    public static function toInputTime(?string $time): string
    {
        return $time === null || $time === '' ? '' : substr($time, 0, 5);
    }

    /**
     * 「HH:MM:SS」または「HH:MM」を「G:i」（時は先頭の 0 なし、分は 2 桁）に直す。空なら空文字
     */
    public static function toDisplayTime(?string $time): string
    {
        $hm = self::toInputTime($time);
        if ($hm === '') {
            return '';
        }
        [$hour, $minute] = explode(':', $hm);

        return ((int) $hour).':'.$minute;
    }

    /**
     * 最終更新者（トレーナー）
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'updated_by');
    }
}
