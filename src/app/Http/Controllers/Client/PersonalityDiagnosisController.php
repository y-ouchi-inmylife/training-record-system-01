<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPersonalityDiagnosisRequest;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Support\ClientMypageLink;
use App\Support\MypageRegistrationInvite;
use App\Support\PersonalityDiagnosisQuestions;
use App\Support\PersonalityDiagnosisScorer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * 性格診断の回答（S-1415・S-1416、要件定義書 6-15-18。2026-10 追加）。お客様が性格診断の案内の URL
 * （性格診断用 URL）から、ログインせずに開く。愛犬アンケートの回答（IntakeSurveyController）と同じ作り。
 *
 * - 案内には有効期限がない。送信して結果を保存した時点で使い切りになる（開いただけでは使い切りにしない）
 * - 使えない URL（存在しない・取消済み・トレーニー削除済み）と回答済みは、専用の画面（200）で伝える
 * - 愛犬アンケートと違い、トレーニーの値は書き換えない（結果として残すだけ）
 * - お客様の回答はアクセスログに記録しない（回答日時が回答の記録を兼ねる）
 * - 愛犬アンケート＆愛犬性格診断の案内（2026-10-19 追加）の愛犬性格診断は、画面の上に進み具合（2/2）を出す。送信したあとは単独と同じ完了の画面
 * - 時間切れ（419）のときの出し直しは bootstrap/app.php から showForm() を呼ぶ（段階3 のコミット 2）
 */
class PersonalityDiagnosisController extends Controller
{
    public const UNAVAILABLE = 'unavailable';

    public const ANSWERED = 'answered';

    // 有効期限が切れた案内（2026-10-19 追加。愛犬アンケートと同じ。期限が空の案内は期限切れにしない）
    public const EXPIRED = 'expired';

    /**
     * 性格診断回答画面（S-1415。GET /client-portal/personality-diagnosis/{token}）
     */
    public function show(string $token): View
    {
        return self::showForm($token);
    }

    /**
     * トークンを確かめて、回答の画面（または使えない URL の画面）を返す。
     *
     * @param  array<string, string>  $restoredAnswers  時間切れで出し直すときに選んだ状態に戻す回答（正しい値だけ。自由記述の質問（4 問）は列名のキー）
     * @param  bool  $expired  時間切れで出し直したか（画面の上に知らせを出す）
     */
    public static function showForm(string $token, array $restoredAnswers = [], bool $expired = false): View
    {
        $tokenRecord = TraineePersonalityDiagnosisToken::query()->where('token', $token)->first();
        $reason = self::checkToken($tokenRecord);
        if ($reason !== true) {
            return self::unavailableView($reason, $token);
        }

        return view('client.personality-diagnosis.show', [
            'token' => $token,
            'categories' => PersonalityDiagnosisQuestions::categories(),
            'questionIdsByCategory' => PersonalityDiagnosisQuestions::questionIdsByCategory(),
            'questions' => PersonalityDiagnosisQuestions::questions(),
            'choices' => PersonalityDiagnosisQuestions::choices(),
            // 自由記述の質問（4 問）（2026-10-19 に愛犬アンケートから移した。50 問のあとに出す）。初期値は空（50 問と同じく、
            // 答え直しでも前回の答えは入れない）。時間切れで出し直すときは送られてきた答えを戻す（$restoredAnswers の列名のキー）
            'lifeQuestions' => PersonalityDiagnosisQuestions::lifeQuestions(),
            'restoredAnswers' => $restoredAnswers,
            'expired' => $expired,
            // 愛犬アンケート＆愛犬性格診断の案内の愛犬性格診断なら、進み具合（2/2）を出す（2026-10-19 追加。マイページのお知らせから
            // 開いたとき〔途中でやめた続き〕も同じ）。愛犬アンケートを送信して進んできたときは、受け付けたことを一言で伝える
            'showProgress' => $tokenRecord->combinedIntakeSurveyToken()->exists(),
            'combinedIntakeAnswered' => (bool) session('combinedIntakeAnswered', false),
        ]);
    }

    /**
     * 回答の送信（POST /client-portal/personality-diagnosis/{token}）。回答を採点して結果を保存し、案内を使い切りにする。
     * 成功したら完了画面（S-1416）へリダイレクトする（PRG）。
     *
     * ロックについて：同じ案内から同時に 2 回送られても結果を 1 件だけにするため、トランザクションの中でトークンの行を
     * SELECT ... FOR UPDATE でロックしてから確かめる（愛犬アンケートと同じ）。後から来た送信は、ロックが解けるのを
     * 待ってから「回答済み」と判定される。トレーニーは書き換えないので、トレーニーの行はロックしない。
     * 注意：テストの DB（SQLite）では lockForUpdate() は何もしないため、排他はテストでは確かめられない。
     */
    public function store(ClientPersonalityDiagnosisRequest $request, string $token, PersonalityDiagnosisScorer $scorer): View|RedirectResponse
    {
        $answers = $request->answers();
        $lifeAnswers = $request->lifeAnswers();

        $result = DB::transaction(function () use ($answers, $lifeAnswers, $token, $scorer) {
            $tokenRecord = TraineePersonalityDiagnosisToken::query()->where('token', $token)->lockForUpdate()->first();
            $reason = self::checkToken($tokenRecord);
            if ($reason !== true) {
                return $reason;
            }

            $answeredAt = now();
            TraineePersonalityDiagnosisResult::query()->create([
                'trainee_id' => $tokenRecord->trainee_id,
                'trainee_personality_diagnosis_token_id' => $tokenRecord->id,
                'answered_at' => $answeredAt,
                'question_version' => PersonalityDiagnosisQuestions::VERSION,
                'answers' => $answers,
                ...$scorer->score($answers)->toResultAttributes(),
                // 自由記述の質問（4 問）（2026-10-19 追加。その診断の結果に保存する）
                ...$lifeAnswers,
            ]);

            $tokenRecord->update(['answered_at' => $answeredAt]);

            return true;
        });

        if ($result !== true) {
            return self::unavailableView($result, $token);
        }

        // ［マイページへ戻る］を出すか（ログインしている会員の、自分の愛犬の案内か。2026-10 追加。愛犬アンケートと同じ）。
        // 完了の画面はトークンを持たないので、ここで判定してフラッシュで渡す
        $tokenRecord = TraineePersonalityDiagnosisToken::query()->where('token', $token)->first();

        return redirect()->route('client-portal.personality-diagnosis.thanks')
            ->with('showMypageLink', ClientMypageLink::shouldShow($tokenRecord?->trainee_id))
            // マイページ登録へのお誘い（2026-10-19 追加。会員に有効なマイページ登録の案内があるときだけ。
            // 愛犬アンケート＆愛犬性格診断は、最後のこの画面だけに出す）
            ->with('mypageRegistrationUrl', MypageRegistrationInvite::url($tokenRecord?->trainee_id));
    }

    /**
     * 性格診断回答完了画面（S-1416。GET /client-portal/personality-diagnosis/thanks）。
     * トークンを使わない固定の画面で、直接開かれても同じ文面を出す（愛犬の名前も結果も出さない。S-1414 と同じ）
     */
    public function thanks(): View
    {
        return view('client.personality-diagnosis.thanks', [
            'showMypageLink' => (bool) session('showMypageLink', false),
            'mypageRegistrationUrl' => session('mypageRegistrationUrl'),
        ]);
    }

    /**
     * トークンが使えるか。使えれば true、使えなければ理由（UNAVAILABLE / ANSWERED / EXPIRED）。
     * 確かめる順は愛犬アンケートと同じ（存在しない → 回答済み → 期限切れ → トレーニーが存在しない。期限切れは 2026-10-19 追加）
     */
    private static function checkToken(?TraineePersonalityDiagnosisToken $tokenRecord): true|string
    {
        if ($tokenRecord === null) {
            return self::UNAVAILABLE;
        }
        if ($tokenRecord->answered_at !== null) {
            return self::ANSWERED;
        }
        if ($tokenRecord->isExpired()) {
            return self::EXPIRED;
        }
        if (! Trainee::query()->whereKey($tokenRecord->trainee_id)->exists()) {
            return self::UNAVAILABLE;
        }

        return true;
    }

    /**
     * 使えない URL の画面（回答済み／使えない。設計書 S-1415「使えない URL の画面」）
     */
    private static function unavailableView(string $reason, string $token): View
    {
        return view('client.personality-diagnosis.unavailable', [
            'message' => match ($reason) {
                self::ANSWERED => 'この'.__('messages.personality_diagnosis_name').'はすでにご回答いただいています。',
                // 期限切れの文は愛犬アンケート・マイページ登録のご案内と同じ（2026-10-19 追加）
                self::EXPIRED => 'このリンクは有効期限が切れています。お手数ですがトレーナーにお問い合わせください。',
                default => 'この'.__('messages.personality_diagnosis_name').'はご利用いただけません。お手数ですがトレーナーにお問い合わせください。',
            },
            // ［マイページへ戻る］：ログインしている会員の、自分の愛犬の案内のときだけ（2026-10 追加）
            'showMypageLink' => ClientMypageLink::shouldShow(
                TraineePersonalityDiagnosisToken::query()->where('token', $token)->value('trainee_id')
            ),
        ]);
    }
}
