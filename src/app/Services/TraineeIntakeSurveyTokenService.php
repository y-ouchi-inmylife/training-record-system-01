<?php

namespace App\Services;

use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use App\Models\TraineeIntakeSurveyToken;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 受付アンケート案内（受付アンケート用 URL のトークン。DS-1000）の発行。
 *
 * トレーニー登録（S-0308 の「受付アンケートの案内を発行する」）と、会員詳細（S-0305）の
 * 「案内を発行」の両方から使う（要件定義書 6-16-8、api-design.md 4-1-16）。トレーニー詳細（S-0309）
 * からは発行しない（状態の表示のみ）。
 */
class TraineeIntakeSurveyTokenService
{
    /**
     * トレーニーに受付アンケート案内を発行する。
     *
     * 状態が「未発行」（回答も有効な案内もない）のときだけ発行し、作ったトークンを返す。
     * 回答がある（回答済み）とき、または有効な案内がすでにあるときは発行せず null を返す
     * （呼び出し側で 409 にする）。
     *
     * ロックについて：同じトレーニーに有効な（answered_at が NULL の）案内を 2 つ作らないため、
     * トランザクションの中でトレーニーの行を SELECT ... FOR UPDATE でロックしてから確かめる。
     * 「answered_at が NULL の行は 1 つまで」という条件付きのユニーク制約は MySQL では書けない
     * ため、アプリ側で守る（db-schema.md DS-1000）。2 人のトレーナーがほぼ同時に押しても、
     * 後の方はロックが解けるのを待ってから「有効な案内がある」と判定される。
     * このプロジェクトでロック（lockForUpdate）を使うのはここが初めて。
     *
     * マイページ登録案内の発行のような「古いものを消してから作る」作りにはしない
     * （受付アンケート案内は発行し直さない。要件定義書 6-16-8 備考）。
     *
     * 呼び出し側がすでにトランザクションの中にいる場合（トレーニー登録と同時の発行）は、
     * その中の入れ子のトランザクションとして動く。
     */
    public function issue(Trainee $trainee, Trainer $practitioner): ?TraineeIntakeSurveyToken
    {
        return DB::transaction(function () use ($trainee, $practitioner) {
            Trainee::query()->whereKey($trainee->id)->lockForUpdate()->first();

            // 回答済みなら発行しない（回答は 1 件。要件定義書 6-16-8）
            $hasResponse = TraineeIntakeSurveyResponse::query()
                ->where('trainee_id', $trainee->id)
                ->exists();
            if ($hasResponse) {
                return null;
            }

            $hasActive = TraineeIntakeSurveyToken::query()
                ->where('trainee_id', $trainee->id)
                ->whereNull('answered_at')
                ->exists();
            if ($hasActive) {
                return null;
            }

            return TraineeIntakeSurveyToken::query()->create([
                'token' => Str::random(32),
                'trainee_id' => $trainee->id,
                'created_by' => $practitioner->id,
            ]);
        });
    }
}
