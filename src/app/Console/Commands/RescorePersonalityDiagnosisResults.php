<?php

namespace App\Console\Commands;

use App\Models\TraineePersonalityDiagnosisResult;
use App\Support\PersonalityDiagnosisScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 保存済みの愛犬性格診断の結果を、今の採点の決まりで計算し直す（2026-10-18 追加）。
 *
 * 採点の決まりを変えたときは、設問の版は変えず、保存してある 50 問の回答（answers）から点数とタイプを計算し直して、
 * すべての結果を同じ決まりにそろえる（要件定義書 6-16「保存済みの結果の計算し直し」・D-1200「設問の版」）。
 * 変わる結果だけを更新し、変わった件数を出す。何度流しても同じ結果になる（2 回目以降は 0 件）。
 *
 * 使用例:
 *   php artisan personality-diagnosis:rescore --dry-run   # 変わる件数と中身を出すだけ（更新しない）
 *   php artisan personality-diagnosis:rescore             # 計算し直して更新する
 *
 * 2026-10-18：全問「未経験」のカテゴリを点数なし（null）、全カテゴリが点数なしのタイプを判定なし（null）にしたときに使う
 * （先に 2026_10_18_000002_make_personality_diagnosis_result_scores_nullable を流しておくこと）
 */
class RescorePersonalityDiagnosisResults extends Command
{
    protected $signature = 'personality-diagnosis:rescore {--dry-run : 変わる件数と中身を出すだけで、更新しない}';

    protected $description = '保存済みの愛犬性格診断の結果を、今の採点の決まりで計算し直します';

    public function handle(PersonalityDiagnosisScorer $scorer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;
        $changed = 0;

        DB::transaction(function () use ($scorer, $dryRun, &$total, &$changed) {
            TraineePersonalityDiagnosisResult::query()->orderBy('id')->lockForUpdate()->each(
                function (TraineePersonalityDiagnosisResult $result) use ($scorer, $dryRun, &$total, &$changed) {
                    $total++;
                    $new = $scorer->score($result->answers)->toResultAttributes();
                    $current = $result->scores();
                    $diff = [];
                    foreach ($new as $column => $value) {
                        $old = $column === 'personality_type'
                            ? $result->personality_type
                            : $current[substr($column, strlen('score_'))];
                        if ($old !== $value) {
                            $diff[$column] = [$old, $value];
                        }
                    }
                    if ($diff === []) {
                        return;
                    }

                    $changed++;
                    $this->line("結果 #{$result->id}：".collect($diff)
                        ->map(fn (array $pair, string $column) => $column.' '.($pair[0] ?? 'null').' → '.($pair[1] ?? 'null'))
                        ->implode('、'));
                    if (! $dryRun) {
                        $result->forceFill($new)->save();
                    }
                }
            );
        });

        $this->info(($dryRun ? '［試しに数えただけで、更新していません］' : '').
            "結果 {$total} 件のうち、{$changed} 件が".($dryRun ? '変わります。' : '変わりました。'));

        return Command::SUCCESS;
    }
}
