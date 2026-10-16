<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員のマイページ（S-1402）の愛犬のカードの、回答待ちの案内のお知らせ（2026-10 追加。要件定義書 6-15-3、
 * 設計書 S-1402「回答待ちの案内のお知らせ」）。
 *
 * - その愛犬に回答待ちの案内があるときだけ、名前の行のすぐ下に「〇〇にお答えください。」と［回答する］
 *   （印刷した案内と同じ回答の URL）。両方あれば 受付アンケート → 性格診断 の順
 * - 「回答待ち」は会員詳細（S-0305）と同じ判定（受付アンケートは回答がなく有効な案内がある、性格診断は有効な案内がある）
 * - 回答済み・取消済み（行がない）の案内、ほかの愛犬・ほかの会員の案内は出さない。クエリはトレーニーの数で増えない
 *
 * 実際の HTTP で開く（ClientDashboardPersonalityDiagnosisTest と同じ表の準備）。
 */
class ClientDashboardSurveyNoticeTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->date('training_date');
            $table->time('training_time')->nullable();
            $table->unsignedBigInteger('trainer1_id')->nullable();
            $table->unsignedBigInteger('trainer2_id')->nullable();
        });
        Schema::create('media_records', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('media_record_training_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_record_id');
            $table->unsignedBigInteger('training_record_id');
            $table->integer('sort_order')->default(0);
        });
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name')->nullable();
            $table->string('photo_path', 500)->nullable();
        });
        Schema::create('trainee_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->date('measured_date');
            $table->time('measured_time');
            $table->decimal('weight_kg', 5, 2);
        });
        // 受付アンケート（回答待ちの案内のお知らせの判定に使う。2026-10 追加）
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $this->client = $this->makeClient('山田');
    }

    private function makeClient(string $lastName): Client
    {
        return Client::query()->create([
            'internal_id' => 'C-'.$lastName, 'last_name' => $lastName, 'first_name' => '太郎', 'email' => $lastName.'@example.com',
        ]);
    }

    private function trainee(string $name, ?Client $client = null): int
    {
        return DB::table('trainees')->insertGetId(['client_id' => ($client ?? $this->client)->id, 'name' => $name]);
    }

    private function measurement(int $traineeId): void
    {
        DB::table('trainee_measurements')->insert([
            'trainee_id' => $traineeId, 'measured_date' => '2026-10-10', 'measured_time' => '09:00:00', 'weight_kg' => '5.00',
        ]);
    }

    private function intakeToken(int $traineeId, string $token, ?string $answeredAt = null): int
    {
        return DB::table('trainee_intake_survey_tokens')->insertGetId([
            'token' => $token, 'trainee_id' => $traineeId, 'answered_at' => $answeredAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function personalityToken(int $traineeId, string $token, ?string $answeredAt = null): void
    {
        DB::table('trainee_personality_diagnosis_tokens')->insert([
            'token' => $token, 'trainee_id' => $traineeId, 'answered_at' => $answeredAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function open(?Client $client = null): string
    {
        return $this->actingAs($client ?? $this->client, 'client')
            ->get(route('client-portal.dashboard'))->assertOk()->getContent();
    }

    /** 愛犬 1 頭分のカード（名前の見出しから、カードの終わりまで） */
    private function card(string $html, string $callName): string
    {
        $start = strpos($html, '>'.$callName.'</h2>');
        $this->assertNotFalse($start, $callName.' のカードがない');

        return substr($html, $start, strpos($html, '</article>', $start) - $start);
    }

    private function noticeKinds(string $card): array
    {
        preg_match_all('/data-client-survey-notice="([a-z]+)"/u', $card, $m);

        return $m[1];
    }

    public function test_性格診断が回答待ちならお知らせと回答のURLへの回答するを出す(): void
    {
        $id = $this->trainee('モモ');
        $this->personalityToken($id, 'pdtoken0000000000000000000000001');

        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertSame(['personality'], $this->noticeKinds($card));
        $this->assertStringContainsString('<div class="c-survey-notice c-survey-notice--personality" data-client-survey-notice="personality">', $card);
        $this->assertStringContainsString('<span>性格診断にお答えください。</span>', $card);
        // 印刷した案内と同じ回答の URL を、同じタブで開く（target は付けない）。会員側の主な操作の青の塗り
        $this->assertStringContainsString(
            '<a href="'.route('client-portal.personality-diagnosis.show', ['token' => 'pdtoken0000000000000000000000001']).'" class="btn btn-primary btn-sm text-nowrap">回答する</a>',
            $card
        );
        $this->assertStringNotContainsString('target="_blank"', $card);
        // アイコンは見た目の印（読み上げない）
        $this->assertStringContainsString('class="c-survey-notice-icon"', $card);
        $this->assertMatchesRegularExpression('/<svg class="c-survey-notice-icon"[^>]*aria-hidden="true"/u', $card);
    }

    public function test_受付アンケートが回答待ちならお知らせと回答のURLへの回答するを出す(): void
    {
        $id = $this->trainee('モモ');
        $this->intakeToken($id, 'intaketoken000000000000000000001');

        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertSame(['intake'], $this->noticeKinds($card));
        $this->assertStringContainsString('<span>受付アンケートにお答えください。</span>', $card);
        $this->assertStringContainsString(
            '<a href="'.route('client-portal.intake-survey.show', ['token' => 'intaketoken000000000000000000001']).'" class="btn btn-primary btn-sm text-nowrap">回答する</a>',
            $card
        );
    }

    public function test_両方が回答待ちなら受付アンケートから性格診断の順で名前の行のすぐ下(): void
    {
        $id = $this->trainee('モモ');
        $this->measurement($id);
        $this->personalityToken($id, 'pdtoken0000000000000000000000001');
        $this->intakeToken($id, 'intaketoken000000000000000000001');

        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertSame(['intake', 'personality'], $this->noticeKinds($card));
        // 名前の行のすぐ下、写真・体重のグラフの段の上
        $this->assertMatchesRegularExpression('/^>モモちゃん<\/h2>\s*(?:\{\{--.*?--\}\}\s*)?<div class="d-flex flex-column gap-2 mb-2" data-client-survey-notices>/us', $card);
        $this->assertLessThan(strpos($card, 'data-trainee-card-row'), strpos($card, 'data-client-survey-notices'));
    }

    public function test_回答済みや取消済みの案内では出さない(): void
    {
        // 受付アンケート：回答があれば（回答済み）出さない
        $answered = $this->trainee('ハナ');
        $tokenId = $this->intakeToken($answered, 'intaketoken000000000000000000002', '2026-10-01 09:00:00');
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $answered, 'trainee_intake_survey_token_id' => $tokenId, 'answered_at' => '2026-10-01 09:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 性格診断：回答済みの案内（使い切り）だけなら出さない
        $this->personalityToken($answered, 'pdtoken0000000000000000000000002', '2026-10-02 09:00:00');
        // 取消済み：案内の行が消えているので、何もない愛犬と同じ
        $cancelled = $this->trainee('ソラ');

        $html = $this->open();

        $this->assertSame([], $this->noticeKinds($this->card($html, 'ハナちゃん')));
        $this->assertSame([], $this->noticeKinds($this->card($html, 'ソラちゃん')));
        $this->assertStringNotContainsString('data-client-survey-notices', $html);
    }

    public function test_ほかの愛犬やほかの会員の案内は出さない(): void
    {
        $momo = $this->trainee('モモ');
        $hana = $this->trainee('ハナ');
        $this->personalityToken($hana, 'pdtoken0000000000000000000000003');
        $other = $this->makeClient('佐藤');
        $othersDog = $this->trainee('リク', $other);
        $this->intakeToken($othersDog, 'intaketoken000000000000000000004');
        $this->personalityToken($othersDog, 'pdtoken0000000000000000000000004');

        $html = $this->open();

        $this->assertSame([], $this->noticeKinds($this->card($html, 'モモちゃん')));
        $this->assertSame(['personality'], $this->noticeKinds($this->card($html, 'ハナちゃん')));
        $this->assertStringNotContainsString('リク', $html);
        $this->assertStringNotContainsString('pdtoken0000000000000000000000004', $html);
        $this->assertStringNotContainsString('intaketoken000000000000000000004', $html);
    }

    public function test_回答待ちの案内がない愛犬のカードは今までどおり(): void
    {
        $id = $this->trainee('モモ');
        $this->measurement($id);

        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertStringNotContainsString('data-client-survey-notice', $card);
        $this->assertStringNotContainsString('回答する', $card);
        $this->assertMatchesRegularExpression('/^>モモちゃん<\/h2>\s*(?:\{\{--.*?--\}\}\s*)*<div class="row g-3 align-items-start" data-trainee-card-row>/us', $card);
    }

    public function test_案内の読み込みはトレーニーの数で増えない(): void
    {
        $id = $this->trainee('モモ');
        $this->intakeToken($id, 'intaketoken000000000000000000005');
        $this->personalityToken($id, 'pdtoken0000000000000000000000005');
        $three = $this->makeClient('佐藤');
        foreach (['ハル', 'ナツ', 'アキ'] as $i => $name) {
            $tid = $this->trainee($name, $three);
            $this->intakeToken($tid, 'intaketoken00000000000000000001'.$i);
            $this->personalityToken($tid, 'pdtoken000000000000000000000001'.$i);
        }

        $count = function (Client $client, string $table): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->open($client);
            $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"'.$table.'"'));
            DB::disableQueryLog();

            return $queries->count();
        };

        foreach (['trainee_intake_survey_tokens', 'trainee_intake_survey_responses', 'trainee_personality_diagnosis_tokens'] as $table) {
            $this->assertSame(1, $count($this->client, $table), $table);
            $this->assertSame(1, $count($three, $table), $table);
        }
    }

    public function test_文は1か所の翻訳ファイルにある(): void
    {
        $this->assertSame('受付アンケートにお答えください。', __('messages.client_survey_notice.intake'));
        $this->assertSame('性格診断にお答えください。', __('messages.client_survey_notice.personality'));
        $this->assertSame('回答する', __('messages.client_survey_notice.answer'));
        $partial = file_get_contents(resource_path('views/client/_survey-notices.blade.php'));
        $this->assertStringNotContainsString('にお答えください。', $partial);
    }
}
