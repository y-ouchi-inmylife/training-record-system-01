<?php

namespace App\Services;

use App\Models\ClientUsage;
use App\Models\Trainee;
use App\Support\ScheduleSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 同時に預かれる数の数え方（2026-11 追加。requirements.md 6-17-11、用語集 No.43）。
 *
 * - 利用 1 件を 1 と数える（トレーニーがない利用も 1 と数える）
 * - 事業所全体（すべての会員の利用）で数える
 * - 同じ日の中で、時間帯が重なる利用を数える。時間帯は終わりを含まない（「12:00 まで」と「12:00 から」は重ならない）
 * - 「ある時間帯の預かる数」は、その時間帯の中で最も多く重なる瞬間の預かる数。「ある日の預かる数」は、その日の中で最も多く重なる瞬間の預かる数
 * - 編集のときは、編集中の利用自身を数えない（$excludeUsageId）
 *
 * 予約状況の表示（UsageScheduleController）と、利用の保存時の上限の確認（ClientUsageController）で、この計算を使う。
 * 上限（同時に預かれる数）は App\Support\ScheduleSetting::capacity()。
 *
 * 時刻は「HH:MM」にそろえて比べる（DB の TIME は「HH:MM:SS」、画面の入力は「HH:MM」のため。分より細かい時刻は扱わない）。
 */
class UsageCapacityService
{
    /**
     * その日の利用（事業所全体）。開始時刻 → 終了時刻 → id の順。$excludeUsageId の利用は入れない
     *
     * @return Collection<int, ClientUsage>
     */
    public function usagesOn(string $date, ?int $excludeUsageId = null): Collection
    {
        return ClientUsage::query()
            ->whereDate('used_on', $date)
            ->when($excludeUsageId !== null, fn ($query) => $query->where('id', '!=', $excludeUsageId))
            ->orderBy('start_time')
            ->orderBy('end_time')
            ->orderBy('id')
            ->get();
    }

    /**
     * その日の預かる数（その日の中で最も多く重なる瞬間の預かる数）
     */
    public function dayCount(string $date, ?int $excludeUsageId = null): int
    {
        return self::maxOverlap(self::intervalsOf($this->usagesOn($date, $excludeUsageId)));
    }

    /**
     * 月の日ごとの預かる数。預かる数が 1 以上の日だけを、日付（Y-m-d）の昇順で返す
     *
     * @param  string  $month  「Y-m」
     * @return array<string, int>
     */
    public function monthCounts(string $month, ?int $excludeUsageId = null): array
    {
        $counts = [];
        foreach ($this->monthUsagesByDate($month, $excludeUsageId) as $date => $dayUsages) {
            $count = self::maxOverlap(self::intervalsOf($dayUsages));
            if ($count > 0) {
                $counts[$date] = $count;
            }
        }

        return $counts;
    }

    /**
     * 月の日ごとの予約（利用）の件数（2026-11 追加。カレンダーのマスの右上に出す）。
     * トレーニーの有無に関係なく 1 件ずつ数える。$excludeUsageId（編集中の利用自身）は数えない。
     * 件数が 1 以上の日だけを、日付（Y-m-d）の昇順で返す（monthCounts と同じ形）
     *
     * @param  string  $month  「Y-m」
     * @return array<string, int>
     */
    public function monthUsageCounts(string $month, ?int $excludeUsageId = null): array
    {
        return $this->monthUsagesByDate($month, $excludeUsageId)
            ->map(fn (Collection $dayUsages) => $dayUsages->count())
            ->all();
    }

    /**
     * 月の利用を、利用日（Y-m-d）ごとにまとめたもの（日付の昇順）。$excludeUsageId の利用は入れない
     *
     * @return Collection<string, Collection<int, ClientUsage>>
     */
    private function monthUsagesByDate(string $month, ?int $excludeUsageId): Collection
    {
        $first = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        return ClientUsage::query()
            ->whereDate('used_on', '>=', $first->toDateString())
            ->whereDate('used_on', '<=', $first->copy()->endOfMonth()->toDateString())
            ->when($excludeUsageId !== null, fn ($query) => $query->where('id', '!=', $excludeUsageId))
            ->orderBy('used_on')
            ->get(['id', 'used_on', 'start_time', 'end_time'])
            ->groupBy(fn (ClientUsage $usage) => $usage->used_on->toDateString());
    }

    /**
     * その日のその時間帯に利用を 1 件入れたときの、その時間帯の預かる数（入れる利用も 1 と数える）と、
     * 預かる数が最も多くなる時間帯（保存時の上限の確認の注意に出す）。
     * 最も多くなる時間帯が離れて 2 つ以上あるときは、早いほうを返す。
     *
     * @return array{count: int, start: string, end: string} start・end は「HH:MM」
     */
    public function peakWith(string $date, string $startTime, string $endTime, ?int $excludeUsageId = null): array
    {
        $start = self::hm($startTime);
        $end = self::hm($endTime);

        // 時間帯が重なる利用だけを、その時間帯の中に切り詰めて使う（終わりを含まないので、境目で接するものは重ならない）
        $intervals = [[$start, $end]];
        foreach (self::intervalsOf($this->usagesOn($date, $excludeUsageId)) as [$s, $e]) {
            if ($s < $end && $e > $start) {
                $intervals[] = [max($s, $start), min($e, $end)];
            }
        }

        // 境目で区切った小さな時間帯ごとに預かる数を数え、いちばん多い預かる数が続く最初のひとまとまりを取る
        $points = [];
        foreach ($intervals as [$s, $e]) {
            $points[$s] = true;
            $points[$e] = true;
        }
        $points = array_keys($points);
        sort($points, SORT_STRING);

        $best = ['count' => 0, 'start' => $start, 'end' => $end];
        $running = null; // 今数えているひとまとまり（いちばん多い預かる数が続いている間）
        for ($i = 0; $i < count($points) - 1; $i++) {
            [$from, $to] = [$points[$i], $points[$i + 1]];
            $count = 0;
            foreach ($intervals as [$s, $e]) {
                if ($s <= $from && $e >= $to) {
                    $count++;
                }
            }

            if ($count > $best['count']) {
                $best = ['count' => $count, 'start' => $from, 'end' => $to];
                $running = true;
            } elseif ($count === $best['count'] && $running && $best['end'] === $from) {
                $best['end'] = $to; // 同じ預かる数が続く間は、ひとまとまりとしてつなげる
            } else {
                $running = false;
            }
        }

        return $best;
    }

    /**
     * 利用の保存の前・保存時の確認の判定（2026-11 追加。requirements.md 6-17-11「保存時の確認」）。
     * 保存時の確認（ClientUsageController）と、保存の前の問い合わせ（UsageScheduleController::check）で同じものを使う。
     *
     * 上限の確認：
     * - 利用日が今日（アプリのタイムゾーン）より前なら確認しない
     * - その利用を入れたときの時間帯の預かる数（peakWith）が上限（ScheduleSetting::capacity()）を超えるときだけ確認する
     *   （上限ちょうどは確認しない）。start・end は預かる数が最も多くなる時間帯（「HH:MM」）
     *
     * 重なりの確認（2026-11 追加）：
     * - 同じトレーニー・同じ利用日で、時間帯が重なる利用がすでにあるとき（overlappingUsage）。過去の日も確かめる
     * - トレーニーが選ばれていない（null）ときは確かめない。overlap はいちばん早い重なる利用の時間帯（「HH:MM」）
     *
     * needs_confirmation は、どちらかが要るとき true
     *
     * @return array{needs_confirmation: bool, needs_capacity_confirmation: bool, count: int, capacity: int, start: string, end: string,
     *               needs_overlap_confirmation: bool, overlap: array{start: string, end: string}|null, trainee_label: string|null}
     */
    public function confirmation(string $date, string $startTime, string $endTime, ?int $excludeUsageId = null, ?int $traineeId = null): array
    {
        $date = Carbon::parse($date)->toDateString();
        $peak = $this->peakWith($date, $startTime, $endTime, $excludeUsageId);
        $capacity = ScheduleSetting::capacity();
        $needsCapacity = $date >= today()->toDateString() && $peak['count'] > $capacity;

        $overlapping = $traineeId === null ? null : $this->overlappingUsage($date, $startTime, $endTime, $traineeId, $excludeUsageId);
        $trainee = $traineeId === null ? null : Trainee::find($traineeId);

        return [
            'needs_confirmation' => $needsCapacity || $overlapping !== null,
            'needs_capacity_confirmation' => $needsCapacity,
            'count' => $peak['count'],
            'capacity' => $capacity,
            'start' => $peak['start'],
            'end' => $peak['end'],
            'needs_overlap_confirmation' => $overlapping !== null,
            'overlap' => $overlapping === null ? null : [
                'start' => self::hm($overlapping->start_time),
                'end' => self::hm($overlapping->end_time),
            ],
            // トレーナー側の表示名（名前が未登録なら「名前未登録」。Trainee::display_name）
            'trainee_label' => $trainee?->display_name,
        ];
    }

    /**
     * 同じトレーニー・同じ利用日で、時間帯が重なる利用（2026-11 追加。重なりの確認）。
     * 時間帯は終わりを含まない（「12:00 まで」と「12:00 から」は重ならない。預かる数の数え方と同じ）。
     * $excludeUsageId（編集中の利用自身）は除く。2 件以上あるときは、開始時刻 → 終了時刻 → id の順でいちばん早いもの。なければ null
     */
    public function overlappingUsage(string $date, string $startTime, string $endTime, int $traineeId, ?int $excludeUsageId = null): ?ClientUsage
    {
        $start = self::hm($startTime);
        $end = self::hm($endTime);

        return $this->usagesOn($date, $excludeUsageId)
            ->first(fn (ClientUsage $usage) => (int) $usage->trainee_id === $traineeId
                && self::hm($usage->start_time) < $end
                && self::hm($usage->end_time) > $start);
    }

    /**
     * 確認の文（2026-11 追加。保存時の確認の黄色の注意に使う）。要る確認の文を、重なり → 上限の順に並べ、最後に $tail を 1 回だけ付ける。
     * - 重なり：「アムロ は、11/26(木) 13:00〜19:00 にすでに予約があります。」
     * - 上限：「この利用を入れると、11/26(木) 15:00〜17:00 に同時に預かる数が 4 になり、上限（3）を超えます。」
     * 画面の確認ダイアログは、同じ文を usage-schedule-core.js の usageConfirmMessage で組み立てる
     *
     * @param  array<string, mixed>  $confirmation  confirmation() の戻り値
     */
    public static function confirmationMessage(string $date, array $confirmation, string $tail): string
    {
        $dateLabel = ClientUsage::toShortDateLabel($date);
        $message = '';
        if ($confirmation['needs_overlap_confirmation']) {
            $message .= $confirmation['trainee_label'].' は、'.$dateLabel.' '
                .ClientUsage::toDisplayTime($confirmation['overlap']['start']).'〜'.ClientUsage::toDisplayTime($confirmation['overlap']['end'])
                .' にすでに予約があります。';
        }
        if ($confirmation['needs_capacity_confirmation']) {
            $message .= 'この利用を入れると、'.$dateLabel.' '
                .ClientUsage::toDisplayTime($confirmation['start']).'〜'.ClientUsage::toDisplayTime($confirmation['end'])
                .' に同時に預かる数が '.$confirmation['count'].' になり、上限（'.$confirmation['capacity'].'）を超えます。';
        }

        return $message.$tail;
    }

    /**
     * 時間帯の集まりの中で、最も多く重なる瞬間の数。終わりを含まないので、同じ時刻では「終わり」を「始まり」より先に数える
     *
     * @param  array<int, array{0: string, 1: string}>  $intervals  [開始, 終了]（「HH:MM」）
     */
    public static function maxOverlap(array $intervals): int
    {
        $events = [];
        foreach ($intervals as [$start, $end]) {
            $events[] = [$start, 1];
            $events[] = [$end, -1];
        }
        usort($events, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $current = 0;
        $max = 0;
        foreach ($events as [, $delta]) {
            $current += $delta;
            $max = max($max, $current);
        }

        return $max;
    }

    /**
     * 利用の時間帯（「HH:MM」の [開始, 終了]）
     *
     * @param  iterable<ClientUsage>  $usages
     * @return array<int, array{0: string, 1: string}>
     */
    private static function intervalsOf(iterable $usages): array
    {
        $intervals = [];
        foreach ($usages as $usage) {
            $intervals[] = [self::hm($usage->start_time), self::hm($usage->end_time)];
        }

        return $intervals;
    }

    /**
     * 時刻を「HH:MM」にそろえる（「08:00」「08:00:00」→「08:00」。入力は検証〔date_format:H:i〕で 2 桁の時にそろっている）
     */
    private static function hm(?string $time): string
    {
        return ClientUsage::toInputTime($time);
    }
}
