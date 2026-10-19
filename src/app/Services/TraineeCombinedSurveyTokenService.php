<?php

namespace App\Services;

use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyToken;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 「愛犬アンケート＆愛犬性格診断」の案内の発行（2026-10-19 追加。要件定義書 6-16-8・6-16-11、api-design.md 4-1-16）。
 *
 * 運用上、2 つを同時に送りたいことがあるため（2026-10-19 のユーザーレビュー）、今の 2 つの案内（愛犬アンケートの案内 DS-1000・
 * 愛犬性格診断の案内 DS-1100）を 1 つのトランザクションで作り、愛犬アンケートの案内の trainee_personality_diagnosis_token_id で
 * 結び付ける。お客様に渡すのは愛犬アンケートの案内のリンク 1 つで、愛犬アンケート → 愛犬性格診断 の順に続けて答えてもらう。
 * 愛犬性格診断の案内も今までどおり自分のリンクを持つ（途中でやめたときに、マイページのお知らせから続きを答えられるように）。
 */
class TraineeCombinedSurveyTokenService
{
    /**
     * トレーニーに愛犬アンケート＆愛犬性格診断の案内を発行し、作った愛犬アンケートの案内（渡すリンクの方）を返す。
     *
     * 「1 頭に出しておける案内は 1 つまで」：組み合わせは 1 つの案内として数える。回答待ちの案内（どちらの種類でも）が
     * あれば発行せず null を返す（単独の発行と同じ。ロックの作りも TraineeIntakeSurveyTokenService と同じ）。
     * 期限は 2 つとも同じ（発行した日から 7 日後の日の終わり）。1 つの値を 2 つに入れる。
     */
    public function issue(Trainee $trainee, Trainer $practitioner): ?TraineeIntakeSurveyToken
    {
        return DB::transaction(function () use ($trainee, $practitioner) {
            Trainee::query()->whereKey($trainee->id)->lockForUpdate()->first();

            $hasActive = TraineeIntakeSurveyToken::query()
                ->where('trainee_id', $trainee->id)
                ->active()
                ->exists()
                || TraineePersonalityDiagnosisToken::query()
                    ->where('trainee_id', $trainee->id)
                    ->active()
                    ->exists();
            if ($hasActive) {
                return null;
            }

            $expiresAt = TraineeIntakeSurveyToken::expiresAtForNewToken();

            // 愛犬アンケートの案内 → 愛犬性格診断の案内の順に作り、あとから結び付ける。愛犬性格診断の案内の発行日時が
            // 愛犬アンケートの案内より前にならないようにするため（会員詳細の「期限切れ」の行は、いちばん新しい案内〔同じ日時なら
            // 愛犬性格診断〕で決める。Trainee::expired_survey_kind）
            $intake = TraineeIntakeSurveyToken::query()->create([
                'token' => Str::random(32),
                'trainee_id' => $trainee->id,
                'expires_at' => $expiresAt,
                'created_by' => $practitioner->id,
            ]);
            $personality = TraineePersonalityDiagnosisToken::query()->create([
                'token' => Str::random(32),
                'trainee_id' => $trainee->id,
                'expires_at' => $expiresAt,
                'created_by' => $practitioner->id,
            ]);
            $intake->update(['trainee_personality_diagnosis_token_id' => $personality->id]);

            return $intake;
        });
    }
}
