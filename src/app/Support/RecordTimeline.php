<?php

namespace App\Support;

use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TrainingRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * トレーニング記録と、公開済みの愛犬性格診断のアドバイスを、1 つの一覧に混ぜて新しい順に並べる（2026-10-18 追加。
 * 要件定義書 6-3-3・6-15-6・6-16-16、設計書 S-0305 セクション2・S-1402「愛犬性格診断のアドバイスを混ぜる」）。
 * 会員詳細（S-0305）とマイページ（S-1402）で同じ並びにするため、ここ 1 か所で並べる。
 *
 * 並び：
 * - 日付の新しい順。記録はトレーニング日、アドバイスは公開した日（evaluation_published_at の日付）
 * - 同じ日なら時刻の新しい順。記録はトレーニングの時刻、アドバイスは公開した時刻
 * - 時刻のない記録は、その日のいちばん後ろ（今の記録どうしの並び〔training_time の降順で NULL が後ろ〕と同じ）
 * - 日付と時刻がまったく同じなら、記録が先・アドバイスが後ろ。記録どうし・アドバイスどうしは渡された順のまま
 *   （PHP の並べ替えは安定。記録は呼ぶ側の並びのまま、アドバイスは公開した日時が同じなら id の順）
 *
 * 件数（「トレーニング記録（N件）」「全 N 回」）は記録だけを数える。ここで混ぜた一覧の数を件数に使わない
 */
class RecordTimeline
{
    /** 一覧の 1 件の種類：トレーニング記録 */
    public const RECORD = 'record';

    /** 一覧の 1 件の種類：愛犬性格診断のアドバイス */
    public const EVALUATION = 'evaluation';

    /**
     * 混ぜた一覧を返す。要素は type（RECORD / EVALUATION）・item（渡された要素そのまま）・date（一覧に出す日付）
     *
     * @param  iterable<int, mixed>  $records  トレーニング記録。要素は TrainingRecord か、記録を 'record' に持つ配列（マイページの $sessions）
     * @param  iterable<int, TraineePersonalityDiagnosisResult>  $evaluations  公開済みのアドバイスの結果
     * @return Collection<int, array{type: string, item: mixed, date: CarbonInterface}>
     */
    public static function merge(iterable $records, iterable $evaluations): Collection
    {
        $entries = [];
        foreach ($records as $item) {
            $record = is_array($item) ? $item['record'] : $item;
            $entries[] = [
                'type' => self::RECORD,
                'item' => $item,
                'date' => $record->training_date,
                'key' => self::sortKey($record->training_date->format('Y-m-d'), self::recordTime($record)),
            ];
        }
        foreach (collect($evaluations)->sortBy('id')->sortBy('evaluation_published_at') as $result) {
            $entries[] = [
                'type' => self::EVALUATION,
                'item' => $result,
                'date' => $result->evaluation_published_at,
                'key' => self::sortKey($result->evaluation_published_at->format('Y-m-d'), $result->evaluation_published_at->format('H:i:s')),
            ];
        }

        // 新しい順。キーが同じものは元の並び（記録が先、アドバイスが後ろ）のまま
        usort($entries, fn (array $a, array $b) => strcmp($b['key'], $a['key']));

        return collect($entries)->map(fn (array $entry) => [
            'type' => $entry['type'],
            'item' => $entry['item'],
            'date' => $entry['date'],
        ]);
    }

    /**
     * 並べ替えのキー（文字列で比べて大きいほうが新しい）。時刻がなければ "0"、あれば "1{時刻}" をつなぎ、
     * 同じ日の中では時刻のないものがいちばん小さく（後ろに）なるようにする
     */
    private static function sortKey(string $date, ?string $time): string
    {
        return $date.($time === null ? '0' : '1'.$time);
    }

    /** トレーニングの時刻（H:i:s）。なければ null */
    private static function recordTime(TrainingRecord $record): ?string
    {
        if ($record->training_time === null || $record->training_time === '') {
            return null;
        }

        return substr($record->training_time.':00', 0, 8);
    }
}
