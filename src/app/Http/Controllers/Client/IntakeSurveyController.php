<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientIntakeSurveyRequest;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use App\Models\TraineeIntakeSurveyToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * 受付アンケートの回答（S-1413・S-1414、要件定義書 6-15-17）。お客様が受付アンケート案内の URL
 * （受付アンケート用 URL）から、ログインせずに開く。
 *
 * - 案内には有効期限がない。送信して回答を保存した時点で使い切りになる（開いただけでは使い切りにしない）
 * - 使えない URL（存在しない・取消済み・トレーニー削除済み）と回答済みは、専用の画面（200）で伝える
 * - お客様の回答はアクセスログに記録しない（回答日時が回答の記録を兼ねる）
 */
class IntakeSurveyController extends Controller
{
    private const UNAVAILABLE = 'unavailable';

    private const ANSWERED = 'answered';

    /**
     * 受付アンケート回答画面（S-1413。GET /client-portal/intake-survey/{token}）
     */
    public function show(string $token): View
    {
        $tokenRecord = TraineeIntakeSurveyToken::query()->where('token', $token)->first();
        if ($tokenRecord === null) {
            return $this->unavailableView(self::UNAVAILABLE);
        }
        if ($tokenRecord->answered_at !== null) {
            return $this->unavailableView(self::ANSWERED);
        }
        $trainee = Trainee::query()->find($tokenRecord->trainee_id);
        if ($trainee === null) {
            return $this->unavailableView(self::UNAVAILABLE);
        }

        return view('client.intake-survey.show', [
            'token' => $token,
            'trainee' => $trainee,
            'questions' => TraineeIntakeSurveyResponse::questions(),
        ]);
    }

    /**
     * 回答の送信（POST /client-portal/intake-survey/{token}）。回答を保存し、トレーニーの空欄の項目だけに
     * 基本情報を反映し、案内を使い切りにする（要件定義書 6-15-17「トレーニーへの反映」）。
     * 成功したら完了画面（S-1414）へリダイレクトする（PRG）。
     *
     * ロックについて：同じ案内から同時に 2 回送られても回答を 1 件だけにするため、トランザクションの中で
     * トークンの行 → トレーニーの行の順に SELECT ... FOR UPDATE でロックしてから確かめる（初回設定
     * S-1403 の二重開封の扱いにならう。設計書 S-1413 備考「同時送信」）。後から来た送信は、ロックが
     * 解けるのを待ってから「回答済み」と判定され、回答済みの画面になる。トレーニーの行もロックするのは、
     * トレーナーが同時にトレーニーを編集・削除しても、空欄の判定と反映がずれないようにするため。
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
            $trainee = Trainee::query()->whereKey($tokenRecord->trainee_id)->lockForUpdate()->first();
            if ($trainee === null) {
                return self::UNAVAILABLE;
            }

            // 名前の確かめ直し：検証（ロックの前）のあとにトレーナーが名前を消した場合、名前のない回答に
            // なってしまうため、ロックしたあとの状態でもう一度確かめる。登録済みなら、そのときの名前を記録する
            if (! $trainee->has_name && blank($validated['name'] ?? null)) {
                return null;
            }
            $name = $trainee->has_name ? $trainee->name : $validated['name'];

            $answeredAt = now();
            $response = [
                'trainee_id' => $trainee->id,
                'trainee_intake_survey_token_id' => $tokenRecord->id,
                'answered_at' => $answeredAt,
                'name' => $name,
            ];
            foreach (['breed', 'sex', 'birth_year', 'birth_month', 'birth_day', 'neuter_status'] as $key) {
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

        if ($result === null) {
            return redirect()
                ->route('client-portal.intake-survey.show', ['token' => $token])
                ->withInput()
                ->withErrors(['name' => __('validation.required', ['attribute' => __('validation.attributes.name')])]);
        }
        if ($result !== true) {
            return $this->unavailableView($result);
        }

        return redirect()->route('client-portal.intake-survey.thanks');
    }

    /**
     * 受付アンケート回答完了画面（S-1414。GET /client-portal/intake-survey/thanks）。
     * トークンを使わない固定の画面で、直接開かれても同じ文面を出す（愛犬の名前も出さない）
     */
    public function thanks(): View
    {
        return view('client.intake-survey.thanks');
    }

    /**
     * 回答の基本情報を、トレーニーの空欄の項目だけに反映する（要件定義書 6-15-17「トレーニーへの反映」）。
     *
     * - 名前：未登録のときだけ
     * - 犬種・性別・去勢・避妊：NULL のときだけ（「不明」〔unknown〕は入力済みとみなす）。
     *   去勢・避妊の「分からない」（dont_know）はトレーニーの「不明」（unknown）に読み替える
     * - 誕生日：3 つとも NULL のときだけ、3 つまとめて（一部だけ埋めると組み合わせが崩れるため）
     * - 自由記述：反映しない（トレーニーに対応する項目がない）
     *
     * updated_by（最終更新者のトレーナー）は変えない（お客様の操作のため。写真の登録と同じ）。
     * 反映するものがなければ update() を呼ばない（更新日時も変えない）
     */
    private function reflectToTrainee(Trainee $trainee, array $response): void
    {
        $changes = [];

        if (! $trainee->has_name) {
            $changes['name'] = $response['name'];
        }
        if ($trainee->breed === null && $response['breed'] !== null) {
            $changes['breed'] = $response['breed'];
        }
        if ($trainee->sex === null && $response['sex'] !== null) {
            $changes['sex'] = $response['sex'];
        }
        $neuterStatus = TraineeIntakeSurveyResponse::neuterStatusForTrainee($response['neuter_status']);
        if ($trainee->neuter_status === null && $neuterStatus !== null) {
            $changes['neuter_status'] = $neuterStatus;
        }
        $traineeHasNoBirthday = $trainee->birth_year === null && $trainee->birth_month === null && $trainee->birth_day === null;
        if ($traineeHasNoBirthday && $response['birth_year'] !== null) {
            $changes['birth_year'] = $response['birth_year'];
            $changes['birth_month'] = $response['birth_month'];
            $changes['birth_day'] = $response['birth_day'];
        }

        if ($changes !== []) {
            $trainee->update($changes);
        }
    }

    /**
     * 使えない URL の画面（回答済み／使えない。設計書 S-1413「使えない URL の画面」）
     */
    private function unavailableView(string $reason): View
    {
        return view('client.intake-survey.unavailable', [
            'message' => $reason === self::ANSWERED
                ? 'このアンケートはすでにご回答いただいています。'
                : 'このアンケートはご利用いただけません。お手数ですがトレーナーにお問い合わせください。',
        ]);
    }
}
