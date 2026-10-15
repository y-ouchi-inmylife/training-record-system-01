<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPersonalityDiagnosisRequest;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Support\PersonalityDiagnosisQuestions;
use App\Support\PersonalityDiagnosisScorer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * 性格診断の回答（S-1415・S-1416、要件定義書 6-15-18。2026-10 追加）。お客様が性格診断の案内の URL
 * （性格診断用 URL）から、ログインせずに開く。受付アンケートの回答（IntakeSurveyController）と同じ作り。
 *
 * - 案内には有効期限がない。送信して結果を保存した時点で使い切りになる（開いただけでは使い切りにしない）
 * - 使えない URL（存在しない・取消済み・トレーニー削除済み）と回答済みは、専用の画面（200）で伝える
 * - 受付アンケートと違い、トレーニーの値は書き換えない（結果として残すだけ）
 * - お客様の回答はアクセスログに記録しない（回答日時が回答の記録を兼ねる）
 * - 時間切れ（419）のときの出し直しは bootstrap/app.php から showForm() を呼ぶ（段階3 のコミット 2）
 */
class PersonalityDiagnosisController extends Controller
{
    public const UNAVAILABLE = 'unavailable';

    public const ANSWERED = 'answered';

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
     * @param  array<string, string>  $restoredAnswers  時間切れで出し直すときに選んだ状態に戻す回答（正しい値だけ）
     * @param  bool  $expired  時間切れで出し直したか（画面の上に知らせを出す）
     */
    public static function showForm(string $token, array $restoredAnswers = [], bool $expired = false): View
    {
        $reason = self::checkToken(TraineePersonalityDiagnosisToken::query()->where('token', $token)->first());
        if ($reason !== true) {
            return self::unavailableView($reason);
        }

        return view('client.personality-diagnosis.show', [
            'token' => $token,
            'categories' => PersonalityDiagnosisQuestions::categories(),
            'questionIdsByCategory' => PersonalityDiagnosisQuestions::questionIdsByCategory(),
            'questions' => PersonalityDiagnosisQuestions::questions(),
            'choices' => PersonalityDiagnosisQuestions::choices(),
            'restoredAnswers' => $restoredAnswers,
            'expired' => $expired,
        ]);
    }

    /**
     * 回答の送信（POST /client-portal/personality-diagnosis/{token}）。回答を採点して結果を保存し、案内を使い切りにする。
     * 成功したら完了画面（S-1416）へリダイレクトする（PRG）。
     *
     * ロックについて：同じ案内から同時に 2 回送られても結果を 1 件だけにするため、トランザクションの中でトークンの行を
     * SELECT ... FOR UPDATE でロックしてから確かめる（受付アンケートと同じ）。後から来た送信は、ロックが解けるのを
     * 待ってから「回答済み」と判定される。トレーニーは書き換えないので、トレーニーの行はロックしない。
     * 注意：テストの DB（SQLite）では lockForUpdate() は何もしないため、排他はテストでは確かめられない。
     */
    public function store(ClientPersonalityDiagnosisRequest $request, string $token, PersonalityDiagnosisScorer $scorer): View|RedirectResponse
    {
        $answers = $request->answers();

        $result = DB::transaction(function () use ($answers, $token, $scorer) {
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
            ]);

            $tokenRecord->update(['answered_at' => $answeredAt]);

            return true;
        });

        if ($result !== true) {
            return self::unavailableView($result);
        }

        return redirect()->route('client-portal.personality-diagnosis.thanks');
    }

    /**
     * 性格診断回答完了画面（S-1416。GET /client-portal/personality-diagnosis/thanks）。
     * トークンを使わない固定の画面で、直接開かれても同じ文面を出す（愛犬の名前も結果も出さない。S-1414 と同じ）
     */
    public function thanks(): View
    {
        return view('client.personality-diagnosis.thanks');
    }

    /**
     * トークンが使えるか。使えれば true、使えなければ理由（UNAVAILABLE / ANSWERED）。
     * 確かめる順は受付アンケートと同じ（存在しない → 回答済み → トレーニーが存在しない）
     */
    private static function checkToken(?TraineePersonalityDiagnosisToken $tokenRecord): true|string
    {
        if ($tokenRecord === null) {
            return self::UNAVAILABLE;
        }
        if ($tokenRecord->answered_at !== null) {
            return self::ANSWERED;
        }
        if (! Trainee::query()->whereKey($tokenRecord->trainee_id)->exists()) {
            return self::UNAVAILABLE;
        }

        return true;
    }

    /**
     * 使えない URL の画面（回答済み／使えない。設計書 S-1415「使えない URL の画面」）
     */
    private static function unavailableView(string $reason): View
    {
        return view('client.personality-diagnosis.unavailable', [
            'message' => $reason === self::ANSWERED
                ? 'この性格診断はすでにご回答いただいています。'
                : 'この性格診断はご利用いただけません。お手数ですがトレーナーにお問い合わせください。',
        ]);
    }
}
