<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientIntakeSurveyRequest;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use App\Models\TraineeIntakeSurveyToken;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Support\ClientMypageLink;
use App\Support\MypageRegistrationInvite;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * 愛犬アンケートの回答（S-1413・S-1414、要件定義書 6-15-17）。お客様が愛犬アンケート案内の URL
 * （愛犬アンケート用 URL）から、ログインせずに開く。
 *
 * - 案内には有効期限がない。送信して回答を保存した時点で使い切りになる（開いただけでは使い切りにしない）
 * - 使えない URL（存在しない・取消済み・トレーニー削除済み）と回答済みは、専用の画面（200）で伝える
 * - お客様の回答はアクセスログに記録しない（回答日時が回答の記録を兼ねる）
 * - 愛犬アンケート＆愛犬性格診断の案内（2026-10-19 追加。案内が愛犬性格診断の案内と結び付いているとき）：画面の上に進み具合（1/2）を出し、
 *   送信したら完了の画面ではなく、結び付いた愛犬性格診断の回答の画面（2/2）へ進む。回答済みの案内を開いたときも、
 *   愛犬性格診断がまだ答えられれば、そちらへ進む（途中でやめたときに、同じリンクから続きを答えられるように）
 */
class IntakeSurveyController extends Controller
{
    private const UNAVAILABLE = 'unavailable';

    private const ANSWERED = 'answered';

    // 有効期限が切れた案内（2026-10-19 追加。案内に 1 週間の有効期限を付けた。期限が空の案内は期限切れにしない）
    private const EXPIRED = 'expired';

    /**
     * 愛犬アンケート回答画面（S-1413。GET /client-portal/intake-survey/{token}）
     */
    public function show(string $token): View|RedirectResponse
    {
        $tokenRecord = TraineeIntakeSurveyToken::query()->where('token', $token)->first();
        if ($tokenRecord === null) {
            return $this->unavailableView(self::UNAVAILABLE, $token);
        }
        if ($tokenRecord->answered_at !== null) {
            // 組み合わせの案内で、愛犬性格診断がまだ答えられるなら、そちらへ進む（2026-10-19 追加）
            $next = $this->nextPersonalityDiagnosisToken($tokenRecord);

            return $next !== null
                ? redirect()->route('client-portal.personality-diagnosis.show', ['token' => $next->token])
                : $this->unavailableView(self::ANSWERED, $token);
        }
        if ($tokenRecord->isExpired()) {
            return $this->unavailableView(self::EXPIRED, $token);
        }
        $trainee = Trainee::query()->find($tokenRecord->trainee_id);
        if ($trainee === null) {
            return $this->unavailableView(self::UNAVAILABLE, $token);
        }

        return view('client.intake-survey.show', [
            'token' => $token,
            'trainee' => $trainee,
            'questions' => TraineeIntakeSurveyResponse::questions(),
            // 前回の回答（最新の回答。なければ null）。暮らしの様子の 4 欄の初期値にする（2026-10-19 追加：回答したあとに
            // 案内を発行し直したとき〔答え直し〕、前回の答えを見ながら直せるように。設計書 S-1413「初期値」）
            'previousResponse' => $trainee->latestIntakeSurveyResponse,
            // 愛犬アンケート＆愛犬性格診断の案内なら、進み具合（1/2）を出す（2026-10-19 追加。続けて答える愛犬性格診断がまだ答えられるとき）
            'showProgress' => $this->nextPersonalityDiagnosisToken($tokenRecord) !== null,
        ]);
    }

    /**
     * 組み合わせの案内（2026-10-19 追加）で、続けて答える愛犬性格診断の案内がまだ答えられる（未回答で期限内）ならその案内。
     * 単独の案内・愛犬性格診断が回答済み・期限切れ・取消済みなら null
     */
    private function nextPersonalityDiagnosisToken(TraineeIntakeSurveyToken $tokenRecord): ?TraineePersonalityDiagnosisToken
    {
        if (! $tokenRecord->isCombined()) {
            return null;
        }
        $next = $tokenRecord->personalityDiagnosisToken;

        return $next !== null && $next->isActive() ? $next : null;
    }

    /**
     * 回答の送信（POST /client-portal/intake-survey/{token}）。回答を保存し、トレーニーの空欄の項目だけに
     * 基本情報を反映し、案内を使い切りにする（要件定義書 6-15-17「トレーニーへの反映」）。
     * 回答はいつも新しい行として保存する（2026-10-19 から、答え直しのときも。前回までの回答は消さずに残し、
     * いちばん新しい回答が今の答えになる。要件定義書 6-15-17「答え直し」）。
     * 成功したら完了画面（S-1414）へリダイレクトする（PRG）。
     *
     * ロックについて：同じ案内から同時に 2 回送られても回答を 1 件だけにするため、トランザクションの中で
     * トークンの行 → トレーニーの行の順に SELECT ... FOR UPDATE でロックしてから確かめる（初回設定
     * S-1403 の二重開封の扱いにならう。設計書 S-1413 備考「同時送信」）。後から来た送信は、ロックが
     * 解けるのを待ってから「回答済み」と判定され、回答済みの画面になる。トレーニーの行もロックするのは、
     * トレーナーが同時にトレーニーを編集・削除しても、反映がずれないようにするため（送信の時点では、お客様の
     * 回答を優先して上書きする）。
     * ロックの順は、ほかの処理（発行のサービスはトレーニーの行だけをロックする）と食い違わない。
     * 注意：テストの DB（SQLite）では lockForUpdate() は何もしないため、排他はテストでは確かめられない。
     */
    public function store(ClientIntakeSurveyRequest $request, string $token): View|RedirectResponse
    {
        $validated = $request->validated();

        $result = DB::transaction(function () use ($validated, $token) {
            $tokenRecord = TraineeIntakeSurveyToken::query()->where('token', $token)->lockForUpdate()->first();
            if ($tokenRecord === null) {
                return self::UNAVAILABLE;
            }
            if ($tokenRecord->answered_at !== null) {
                return self::ANSWERED;
            }
            if ($tokenRecord->isExpired()) {
                return self::EXPIRED;
            }
            $trainee = Trainee::query()->whereKey($tokenRecord->trainee_id)->lockForUpdate()->first();
            if ($trainee === null) {
                return self::UNAVAILABLE;
            }

            // 回答には送られてきた値をそのまま記録する。名前は常に必須なので、トレーニーの名前の有無で
            // 分けない（2026-10 変更。当初は、トレーニーの名前が登録済みならそのときの名前を記録し、未登録なのに
            // 回答の名前が空ならロックのあとに確かめ直して入力エラーにしていた）
            $answeredAt = now();
            $response = [
                'trainee_id' => $trainee->id,
                'trainee_intake_survey_token_id' => $tokenRecord->id,
                'answered_at' => $answeredAt,
            ];
            foreach (['name', 'breed', 'sex', 'birth_year', 'birth_month', 'birth_day', 'neuter_status'] as $key) {
                $response[$key] = $validated[$key] ?? null;
            }
            foreach (array_keys(TraineeIntakeSurveyResponse::questions()) as $column) {
                $response[$column] = $validated[$column] ?? null;
            }
            TraineeIntakeSurveyResponse::query()->create($response);

            $this->reflectToTrainee($trainee, $response);

            $tokenRecord->update(['answered_at' => $answeredAt]);

            return true;
        });

        if ($result !== true) {
            return $this->unavailableView($result, $token);
        }

        // 組み合わせの案内なら、完了の画面ではなく、そのまま愛犬性格診断の回答の画面（2/2）へ進む（2026-10-19 追加）。
        // 愛犬アンケートを受け付けたことを、進んだ先の画面の上に一言で伝える
        $next = $this->nextPersonalityDiagnosisToken(TraineeIntakeSurveyToken::query()->where('token', $token)->firstOrFail());
        if ($next !== null) {
            return redirect()->route('client-portal.personality-diagnosis.show', ['token' => $next->token])
                ->with('combinedIntakeAnswered', true);
        }

        // ［マイページへ戻る］を出すか（ログインしている会員の、自分の愛犬の案内か。2026-10 追加）。
        // 完了の画面はトークンを持たないので、ここで判定してフラッシュで渡す
        $tokenRecord = TraineeIntakeSurveyToken::query()->where('token', $token)->first();

        return redirect()->route('client-portal.intake-survey.thanks')
            ->with('showMypageLink', ClientMypageLink::shouldShow($tokenRecord?->trainee_id))
            // マイページ登録へのお誘い（2026-10-19 追加。結び付けたマイページ登録の案内がまだ使えるときだけ）
            ->with('mypageRegistrationUrl', MypageRegistrationInvite::url($tokenRecord));
    }

    /**
     * 愛犬アンケート回答完了画面（S-1414。GET /client-portal/intake-survey/thanks）。
     * トークンを使わない固定の画面で、直接開かれても同じ文面を出す（愛犬の名前も出さない）
     */
    public function thanks(): View
    {
        return view('client.intake-survey.thanks', [
            'showMypageLink' => (bool) session('showMypageLink', false),
            'mypageRegistrationUrl' => session('mypageRegistrationUrl'),
        ]);
    }

    /**
     * 回答の基本情報で、トレーニーの値を上書きする（要件定義書 6-15-17「トレーニーへの反映」。2026-10 変更）。
     *
     * - 名前：回答の値で上書き（必須なので必ず入る）
     * - 犬種・性別・去勢・避妊：回答に値があれば上書き（「不明」〔unknown〕も上書き）。空・未選択なら変えない
     *   （お客様が答えなかったときに、トレーナーが入れた値を消さないため）
     * - 誕生日：年・月・日をひとまとまりで扱う。3 つとも空なら変えない。どれかが入っていれば、3 つまとめて
     *   回答の値で上書きする（空の欄は空で上書き。一部だけ上書きすると組み合わせが崩れるため）
     * - 自由記述：反映しない（トレーニーに対応する項目がない）
     *
     * updated_by（最終更新者のトレーナー）は変えない（お客様の操作のため。写真の登録と同じ）。
     * 反映で値が変わらなければ保存しない（更新日時も変えない）。変わったかどうかは Eloquent の isDirty() で
     * 見る（誕生日の整数のキャストを踏まえて比べるため。'2020' と 2020 は同じとみなされる）。
     *
     * 経緯：当初はトレーニーの空欄の項目だけに反映していた（名前は未登録のときだけ、犬種・性別・去勢・避妊は
     * NULL のときだけ〔「不明」は入力済みとみなす〕、去勢・避妊の「分からない」は「不明」に読み替え、誕生日は
     * 3 つとも NULL のときだけ）。
     */
    private function reflectToTrainee(Trainee $trainee, array $response): void
    {
        $changes = ['name' => $response['name']];
        foreach (['breed', 'sex', 'neuter_status'] as $key) {
            if ($response[$key] !== null) {
                $changes[$key] = $response[$key];
            }
        }
        if ($response['birth_year'] !== null || $response['birth_month'] !== null || $response['birth_day'] !== null) {
            $changes['birth_year'] = $response['birth_year'];
            $changes['birth_month'] = $response['birth_month'];
            $changes['birth_day'] = $response['birth_day'];
        }

        $trainee->fill($changes);
        if ($trainee->isDirty()) {
            $trainee->save();
        }
    }

    /**
     * 使えない URL の画面（回答済み／使えない。設計書 S-1413「使えない URL の画面」）
     */
    private function unavailableView(string $reason, string $token): View
    {
        return view('client.intake-survey.unavailable', [
            'message' => match ($reason) {
                self::ANSWERED => 'このアンケートはすでにご回答いただいています。',
                // 期限切れの文はマイページ登録のご案内の期限切れの文に合わせた（2026-10-19 追加）
                self::EXPIRED => 'このリンクは有効期限が切れています。お手数ですがトレーナーにお問い合わせください。',
                default => 'このアンケートはご利用いただけません。お手数ですがトレーナーにお問い合わせください。',
            },
            // ［マイページへ戻る］：ログインしている会員の、自分の愛犬の案内のときだけ（2026-10 追加）
            'showMypageLink' => ClientMypageLink::shouldShow(
                TraineeIntakeSurveyToken::query()->where('token', $token)->value('trainee_id')
            ),
        ]);
    }
}
