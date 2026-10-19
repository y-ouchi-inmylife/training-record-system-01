<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーニー詳細（S-0309）の愛犬アンケートまわりと、削除の確認の文言（2026-10 追加）。
 *
 * - 自由記述の質問の欄（愛犬アンケートの回答の自由記述 4 欄）は出さない（2026-10-19 変更：4 問を愛犬性格診断へ移し、診断ごとの答えは
 *   愛犬性格診断詳細 S-0313 で見る。その前は見出し「暮らしの様子」の欄〔id="intake-survey"〕に最新の回答の 4 問を出していた）。
 *   愛犬アンケートの回答の基本情報も出さない（トレーニーの基本情報に反映済み）
 * - 発行・表示・取消のボタン・取消モーダル・完了メッセージは置かない（操作は会員詳細だけ）
 * - 削除の確認：計測値の文の後ろに、回答があれば回答の文、未回答の案内があれば案内の文をこの順で足し、
 *   最後を「このトレーニーを削除しますか？」で締める（どちらもないときは今までの文のまま）
 *
 * ミドルウェアは外さず、実際の HTTP で確かめる。
 */
class TraineeShowIntakeSurveyTest extends TestCase
{
    use CreatesClientCountTables;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->string('neuter_status', 20)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        Schema::create('trainee_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->date('measured_date');
            $table->time('measured_time');
            $table->decimal('weight_kg', 5, 2);
            $table->timestamps();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        // 性格診断の欄（2026-10 追加）
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();
        $this->createTrainingRecordsTableForPersonalityDiagnosis();

        $admin = new Trainer(['name' => '管理太郎', 'role' => 'admin']);
        $admin->id = 100;
        $admin->exists = true;
        $this->actingAs($admin, 'web');

        $client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田']);
        $this->trainee = Trainee::query()->create(['client_id' => $client->id, 'name' => 'モモ']);
    }

    private function issueToken(?string $answeredAt = null): void
    {
        DB::table('trainee_intake_survey_tokens')->insert([
            'token' => bin2hex(random_bytes(16)), 'trainee_id' => $this->trainee->id,
            'answered_at' => $answeredAt, 'created_at' => '2026-10-15 09:00:00', 'updated_at' => '2026-10-15 09:00:00',
        ]);
    }

    private function addResponse(string $answeredAt = '2026-10-16 14:32:00', array $attributes = []): void
    {
        DB::table('trainee_intake_survey_responses')->insert(array_merge([
            'trainee_id' => $this->trainee->id, 'answered_at' => $answeredAt,
            'created_at' => $answeredAt, 'updated_at' => $answeredAt,
        ], $attributes));
    }

    private function addMeasurements(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::table('trainee_measurements')->insert([
                'trainee_id' => $this->trainee->id, 'measured_date' => "2026-10-0{$i}", 'measured_time' => '08:00:00', 'weight_kg' => 4.5,
            ]);
        }
    }

    /** 削除ボタンの onsubmit の confirm() に渡る文言 */
    private function deleteConfirmMessage(string $html): string
    {
        $this->assertSame(1, preg_match("/onsubmit=\"return confirm\\('([^']*)'\\)\">\\s*<input type=\"hidden\" name=\"_token\"[^>]*>\\s*<input type=\"hidden\" name=\"_method\" value=\"DELETE\">\\s*<button type=\"submit\" class=\"btn btn-danger\">削除<\\/button>/u", $html, $m));

        return $m[1];
    }

    /** 自由記述の質問の欄（と愛犬アンケートの回答の中身）が画面にないこと（2026-10-19 変更） */
    private function assertNoLifeCard(string $html): void
    {
        $this->assertStringNotContainsString('id="intake-survey"', $html);
        $this->assertStringNotContainsString('暮らしの様子', $html);
        $this->assertStringNotContainsString('data-intake-survey-answers', $html);
        $this->assertStringNotContainsString('●', $html);
        foreach (PersonalityDiagnosisQuestions::lifeQuestions() as $question) {
            $this->assertStringNotContainsString($question['question'], $html);
        }
    }

    public function test_未発行でも回答待ちでも回答済みでも自由記述の質問の欄は出さない(): void
    {
        $this->assertNoLifeCard($this->get(route('trainees.show', $this->trainee))->assertOk()->getContent());

        $this->issueToken();
        $this->assertNoLifeCard($this->get(route('trainees.show', $this->trainee))->assertOk()->getContent());

        DB::table('trainee_intake_survey_tokens')->update(['answered_at' => '2026-10-16 14:32:00']);
        $this->addResponse('2026-10-16 14:32:00', ['breed' => 'トイプードル']);
        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();
        $this->assertNoLifeCard($html);
        // 回答の基本情報も出さない（画面上部はトレーニーの値）。状態の文字・回答日時も出さない
        $this->assertStringNotContainsString('トイプードル', $html);
        $this->assertStringNotContainsString('2026/10/16', $html);
        $this->assertStringNotContainsString('回答済み', $html);
        $this->assertNoOperations($html);
    }

    /** 欄（と画面全体）に操作のボタン・取消モーダルがないこと */
    private function assertNoOperations(string $html): void
    {
        $this->assertStringNotContainsString('>愛犬アンケートの案内を発行<', $html);
        $this->assertStringNotContainsString('>案内を取消<', $html);
        $this->assertStringNotContainsString('案内を表示', $html);
        $this->assertStringNotContainsString('intakeSurveyCancelModal', $html);
        $this->assertStringNotContainsString(route('trainee-intake-survey-tokens.store', $this->trainee), $html);
    }

    public function test_欄の中に完了メッセージは出さない(): void
    {
        // 段階3a で使っていたキーが残っていても、欄の中には出さない（操作を会員詳細に寄せたため）
        $html = $this->withSession(['intake_survey_success' => '愛犬アンケートの案内を発行しました。'])
            ->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringNotContainsString('愛犬アンケートの案内を発行しました。', $html);
    }

    // ---- 削除の確認の文言 ----

    public function test_削除の確認_案内なし計測値なしは今までどおり(): void
    {
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_案内なし計測値ありは今までどおり(): void
    {
        $this->addMeasurements(2);
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーには 2 件の計測値が登録されています。トレーニーを削除すると計測値も一緒に削除されます。削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_案内あり計測値なし(): void
    {
        $this->issueToken();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('発行済みの愛犬アンケートの案内も使えなくなります。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_案内あり計測値あり(): void
    {
        $this->issueToken();
        $this->addMeasurements(3);
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーには 3 件の計測値が登録されています。トレーニーを削除すると計測値も一緒に削除されます。発行済みの愛犬アンケートの案内も使えなくなります。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_回答済みの案内しかなければ案内の文は足さない(): void
    {
        $this->issueToken('2026-10-16 10:00:00');
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_回答あり計測値なし(): void
    {
        $this->issueToken('2026-10-16 14:32:00');
        $this->addResponse();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('愛犬アンケートの回答も一緒に削除されます。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_回答あり計測値あり(): void
    {
        $this->issueToken('2026-10-16 14:32:00');
        $this->addResponse();
        $this->addMeasurements(2);
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーには 2 件の計測値が登録されています。トレーニーを削除すると計測値も一緒に削除されます。愛犬アンケートの回答も一緒に削除されます。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_回答と未回答の案内の両方があれば回答の文から足す(): void
    {
        // 第1段階では起きない組み合わせ（回答済みには発行しない）だが、文の順を確かめる
        $this->addResponse();
        $this->issueToken();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('愛犬アンケートの回答も一緒に削除されます。発行済みの愛犬アンケートの案内も使えなくなります。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    // ---- 削除の確認：性格診断（2026-10 追加。要件定義書 6-16-4） ----

    private function addPersonalityResult(): void
    {
        DB::table('trainee_personality_diagnosis_results')->insert([
            'trainee_id' => $this->trainee->id, 'answered_at' => '2026-10-16 15:00:00', 'question_version' => 'v1',
            'answers' => '{}', 'score_people' => 0, 'score_dogs' => 0, 'score_possessiveness' => 0, 'score_separation' => 0,
            'score_fear' => 0, 'score_trainability' => 0, 'score_excitability' => 0, 'personality_type' => 'calm',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function issuePersonalityToken(): void
    {
        DB::table('trainee_personality_diagnosis_tokens')->insert([
            'token' => bin2hex(random_bytes(16)), 'trainee_id' => $this->trainee->id, 'answered_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_削除の確認_愛犬アンケートの回答と性格診断の結果の両方があればつないで出す(): void
    {
        $this->issueToken('2026-10-16 14:32:00');
        $this->addResponse();
        $this->addPersonalityResult();
        $this->addMeasurements(2);
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーには 2 件の計測値が登録されています。トレーニーを削除すると計測値も一緒に削除されます。愛犬アンケートの回答と愛犬性格診断の結果も一緒に削除されます。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_性格診断の結果だけ(): void
    {
        $this->addPersonalityResult();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('愛犬性格診断の結果も一緒に削除されます。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_性格診断の未回答の案内だけ(): void
    {
        $this->issuePersonalityToken();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('発行済みの愛犬性格診断の案内も使えなくなります。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_すべてあれば消えるものから使えなくなる案内の順(): void
    {
        $this->addResponse();
        $this->issueToken();
        $this->addPersonalityResult();
        $this->issuePersonalityToken();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('愛犬アンケートの回答と愛犬性格診断の結果も一緒に削除されます。発行済みの愛犬アンケートの案内と愛犬性格診断の案内も使えなくなります。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    // ---- 削除の確認：公開済みのアドバイス（2026-10-18 追加。要件定義書 6-16-4・6-16-16。2026-10-18 変更：その前は
    //      結果から作ったトレーニング記録が残ることの文） ----

    /** 最後の結果のアドバイスを公開済みにする */
    private function publishLatestEvaluation(): void
    {
        DB::table('trainee_personality_diagnosis_results')->where('id', DB::table('trainee_personality_diagnosis_results')->max('id'))
            ->update(['evaluation_text' => 'アドバイス', 'evaluation_published_at' => '2026-10-17 10:00:00']);
    }

    public function test_削除の確認_公開済みのアドバイスがあれば会員様の画面から消えることを足す(): void
    {
        $this->addPersonalityResult();
        $this->publishLatestEvaluation();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('愛犬性格診断の結果も一緒に削除されます。公開済みの愛犬性格診断のアドバイスも、会員様のマイページから消えます。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_すべてあればアドバイスの文は消えるものの文のあと案内の文の前(): void
    {
        $this->addResponse();
        $this->issueToken();
        $this->addPersonalityResult();
        $this->publishLatestEvaluation();
        $this->issuePersonalityToken();
        $this->addMeasurements(1);
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーには 1 件の計測値が登録されています。トレーニーを削除すると計測値も一緒に削除されます。愛犬アンケートの回答と愛犬性格診断の結果も一緒に削除されます。公開済みの愛犬性格診断のアドバイスも、会員様のマイページから消えます。発行済みの愛犬アンケートの案内と愛犬性格診断の案内も使えなくなります。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_アドバイスが下書きならアドバイスの文は出さない(): void
    {
        $this->addPersonalityResult();
        DB::table('trainee_personality_diagnosis_results')->update(['evaluation_text' => '下書き']);
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertStringNotContainsString('会員様のマイページから消えます', $this->deleteConfirmMessage($html));
        $this->assertStringNotContainsString('グラフのない記録', $this->deleteConfirmMessage($html));
    }

    // ---- 答え直し（2026-10-19 追加：回答したあとも、いつでも案内を発行し直して答え直してもらえる） ----

    public function test_削除の確認_回答が2件でも文は今までどおり(): void
    {
        $this->issueToken('2026-10-01 09:00:00');
        $this->addResponse('2026-10-01 09:00:00');
        $this->issueToken('2026-10-10 09:00:00');
        $this->addResponse('2026-10-10 09:00:00');
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('愛犬アンケートの回答も一緒に削除されます。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }
}
