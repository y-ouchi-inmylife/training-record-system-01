<?php

namespace App\Services;

use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyToken;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 愛犬アンケート案内（愛犬アンケート用 URL のトークン。DS-1000）の発行。
 *
 * 会員詳細（S-0305）の「愛犬アンケートの案内を発行」から使う（要件定義書 6-16-8、api-design.md 4-1-16）。トレーニー登録
 * （S-0308）とトレーニー詳細（S-0309）からは発行しない（2026-10 変更。当初はトレーニー登録の
 * 「愛犬アンケートの案内を発行する」のチェックからも使っていた）。
 */
class TraineeIntakeSurveyTokenService
{
    /**
     * トレーニーに愛犬アンケート案内を発行する。
     *
     * 有効な案内（未回答のトークン）がないときに発行し、作ったトークンを返す。有効な案内がすでにあるときは
     * 発行せず null を返す（呼び出し側は何もせずに会員詳細に戻す）。
     * 回答の有無は見ない（2026-10-19 変更：ユーザーレビューで、暮らしの様子は変わっていくので、回答したあとも
     * 案内を発行し直して答え直してもらえるようにした。その前は、回答済みなら発行せず null を返し、呼び出し側で 409 にしていた）。
     *
     * ロックについて：同じトレーニーに有効な（answered_at が NULL の）案内を 2 つ作らないため、
     * トランザクションの中でトレーニーの行を SELECT ... FOR UPDATE でロックしてから確かめる。
     * 「answered_at が NULL の行は 1 つまで」という条件付きのユニーク制約は MySQL では書けない
     * ため、アプリ側で守る（db-schema.md DS-1000）。2 人のトレーナーがほぼ同時に押しても、
     * 後の方はロックが解けるのを待ってから「有効な案内がある」と判定される。
     * このプロジェクトでロック（lockForUpdate）を使うのはここが初めて。
     *
     * マイページ登録案内の発行のような「古いものを消してから作る」作りにはしない
     * （有効な案内があるうちは発行し直さない。要件定義書 6-16-8 備考）。
     *
     * 呼び出し側がすでにトランザクションの中にいる場合は、その中の入れ子のトランザクションとして動く。
     */
    public function issue(Trainee $trainee, Trainer $practitioner): ?TraineeIntakeSurveyToken
    {
        return DB::transaction(function () use ($trainee, $practitioner) {
            Trainee::query()->whereKey($trainee->id)->lockForUpdate()->first();

            // 有効な案内＝未回答で期限内のもの（2026-10-19 変更：期限が切れた案内は数えないので、期限が切れたら
            // すぐ発行し直せる。期限が切れた行は消さずに残す）
            $hasActive = TraineeIntakeSurveyToken::query()
                ->where('trainee_id', $trainee->id)
                ->active()
                ->exists();
            if ($hasActive) {
                return null;
            }

            return TraineeIntakeSurveyToken::query()->create([
                'token' => Str::random(32),
                'trainee_id' => $trainee->id,
                // 有効期限は発行した日から 7 日後の日の終わり（2026-10-19 追加。マイページ登録のご案内と同じ決め方）
                'expires_at' => TraineeIntakeSurveyToken::expiresAtForNewToken(),
                'created_by' => $practitioner->id,
            ]);
        });
    }
}
