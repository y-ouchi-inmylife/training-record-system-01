<?php

namespace App\Services;

use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 性格診断の案内（性格診断用 URL のトークン。DS-1100）の発行（2026-10 追加。要件定義書 6-16-11）。
 *
 * 会員詳細（S-0305）の「性格診断」の行の［案内を発行］から使う。受付アンケート案内の発行
 * （TraineeIntakeSurveyTokenService）と同じ作りで、違いは**前の結果（回答済みの案内）があっても発行できる**こと
 * （何回でも受けられるため）。
 */
class TraineePersonalityDiagnosisTokenService
{
    /**
     * トレーニーに性格診断の案内を発行する。
     *
     * 有効な（answered_at が NULL の）案内がなければ発行して、作ったトークンを返す。有効な案内がすでにあるときは
     * 発行せず null を返す（呼び出し側で今ある案内を開く）。
     *
     * ロックについて：同じトレーニーに有効な案内を 2 つ作らないため、トランザクションの中でトレーニーの行を
     * SELECT ... FOR UPDATE でロックしてから確かめる（受付アンケートと同じ。db-schema.md DS-1100）。
     */
    public function issue(Trainee $trainee, Trainer $practitioner): ?TraineePersonalityDiagnosisToken
    {
        return DB::transaction(function () use ($trainee, $practitioner) {
            Trainee::query()->whereKey($trainee->id)->lockForUpdate()->first();

            $hasActive = TraineePersonalityDiagnosisToken::query()
                ->where('trainee_id', $trainee->id)
                ->whereNull('answered_at')
                ->exists();
            if ($hasActive) {
                return null;
            }

            return TraineePersonalityDiagnosisToken::query()->create([
                'token' => Str::random(32),
                'trainee_id' => $trainee->id,
                'created_by' => $practitioner->id,
            ]);
        });
    }
}
