<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AssertsIntakeSurveyName;
use Tests\Concerns\AssertsPersonalityDiagnosisName;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員のマイページ（S-1402）の愛犬のカードの、回答待ちの案内のお知らせ（2026-10 追加。要件定義書 6-15-3、
 * 設計書 S-1402「回答待ちの案内のお知らせ」）。
 *
 * - その愛犬に回答待ちの案内があるときだけ、名前の行のすぐ下に「〇〇にお答えください。」と［回答する］
 *   （印刷した案内と同じ回答の URL）。両方あれば 愛犬アンケート → 性格診断 の順
 * - 「回答待ち」は会員詳細（S-0305）と同じ判定（愛犬アンケートは回答がなく有効な案内がある、性格診断は有効な案内がある）
 * - 回答済み・取消済み（行がない）の案内、ほかの愛犬・ほかの会員の案内は出さない。クエリはトレーニーの数で増えない
 *
 * 実際の HTTP で開く（ClientDashboardPersonalityDiagnosisTest と同じ表の準備）。
 */
class ClientDashboardSurveyNoticeTest extends TestCase
{
    use AssertsIntakeSurveyName;
    use AssertsPersonalityDiagnosisName;
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        // マイページのご連絡先の登録のお願いで、契約があるかを見る（2026-10-18 追加。中身は空）
        $this->createClientCountTables();
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
        // 愛犬アンケート（回答待ちの案内のお知らせの判定に使う。2026-10 追加）
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

    /** 愛犬 1 頭分のカード（カード〔article〕の始まりから終わりまで。お知らせは名前の見出しより前にあるため） */
    private function card(string $html, string $callName): string
    {
        $heading = strpos($html, '>'.$callName.'</h2>');
        $this->assertNotFalse($heading, $callName.' のカードがない');
        $start = strrpos(substr($html, 0, $heading), '<article');

        return substr($html, $start, strpos($html, '</article>', $heading) - $start);
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
        $this->assertStringContainsString('<span>愛犬性格診断にお答えください。</span>', $card);
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

    public function test_愛犬アンケートが回答待ちならお知らせと回答のURLへの回答するを出す(): void
    {
        $id = $this->trainee('モモ');
        $this->intakeToken($id, 'intaketoken000000000000000000001');

        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertSame(['intake'], $this->noticeKinds($card));
        $this->assertStringContainsString('<span>愛犬アンケートにお答えください。</span>', $card);
        $this->assertStringContainsString(
            '<a href="'.route('client-portal.intake-survey.show', ['token' => 'intaketoken000000000000000000001']).'" class="btn btn-primary btn-sm text-nowrap">回答する</a>',
            $card
        );
    }

    public function test_両方が回答待ちなら愛犬アンケートから性格診断の順でカードのいちばん上(): void
    {
        $id = $this->trainee('モモ');
        $this->measurement($id);
        $this->personalityToken($id, 'pdtoken0000000000000000000000001');
        $this->intakeToken($id, 'intaketoken000000000000000000001');

        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertSame(['intake', 'personality'], $this->noticeKinds($card));
        // カードの中のいちばん上（名前の行の上）。2026-10 変更：当初は名前の行のすぐ下（タイプとレーダーが離れるため移した）
        $this->assertMatchesRegularExpression(
            '/<div class="c-session-content">\s*<div class="d-flex flex-column gap-2 mb-2" data-client-survey-notices>/u',
            $card
        );
        $this->assertLessThan(strpos($card, '>モモちゃん</h2>'), strpos($card, 'data-client-survey-notices'));
        // 名前の見出しのあとは、すぐ写真・体重のグラフの段（お知らせが間に入らない）
        $this->assertMatchesRegularExpression('/>モモちゃん<\/h2>\s*(?:\{\{--.*?--\}\}\s*)*<div class="row g-3 align-items-start" data-trainee-card-row>/us', $card);
    }

    public function test_回答済みや取消済みの案内では出さない(): void
    {
        // 愛犬アンケート：回答があれば（回答済み）出さない
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
        // カードのいちばん上は今までどおり名前の見出し、そのあとすぐ写真・体重のグラフの段
        $this->assertMatchesRegularExpression('/<div class="c-session-content">\s*<h2 class="mb-2" style="font-size: 1\.1rem;">モモちゃん<\/h2>\s*<div class="row g-3 align-items-start" data-trainee-card-row>/u', $card);
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
        $this->assertSame('愛犬アンケートにお答えください。', __('messages.client_survey_notice.intake'));
        $this->assertSame('愛犬性格診断にお答えください。', __('messages.client_survey_notice.personality'));
        $this->assertSame('回答する', __('messages.client_survey_notice.answer'));
        $partial = file_get_contents(resource_path('views/client/_survey-notices.blade.php'));
        $this->assertStringNotContainsString('にお答えください。', $partial);
    }

    // ---- 愛犬性格診断の名前（2026-10-18 変更：正式名称に決まった。その前は「性格診断」） ----

    public function test_お知らせは愛犬性格診断の名前で出し性格診断は単独で出さない(): void
    {
        $id = $this->trainee('モモ');
        $this->personalityToken($id, 'pd-token');

        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertStringContainsString('愛犬性格診断にお答えください。', $card);
        $this->assertOnlyFullPersonalityDiagnosisName($card);
    }

    public function test_お知らせの名前も文のファイルの1か所の名前から組み立てる(): void
    {
        // client_survey_notice.personality は、同じファイルの名前（$personalityDiagnosisName）から組み立てる
        $source = file_get_contents(lang_path('ja/messages.php'));
        // 名前は「愛犬」「性格診断」に分けた 1 行だけに書き、ふつうの名前はそれをつなげて作る（2026-10-18 変更）
        $this->assertSame(1, substr_count($source, "\$personalityDiagnosisNameParts = ['愛犬', '性格診断'];"));
        $this->assertSame(0, substr_count($source, "'愛犬性格診断'"));
        $this->assertStringContainsString("\$personalityDiagnosisName = implode('', \$personalityDiagnosisNameParts);", $source);
        $this->assertStringContainsString("'personality_diagnosis_name' => \$personalityDiagnosisName,", $source);
        $this->assertStringContainsString("'personality_diagnosis_name_parts' => \$personalityDiagnosisNameParts,", $source);
        $this->assertSame(['愛犬', '性格診断'], __('messages.personality_diagnosis_name_parts'));
        $this->assertStringContainsString("'personality' => \$personalityDiagnosisName.'にお答えください。',", $source);
        $this->assertSame('愛犬性格診断', __('messages.personality_diagnosis_name'));
    }

    // ---- 愛犬アンケートの名前（2026-10-19 変更：その前は「受付アンケート」） ----

    public function test_お知らせの愛犬アンケートの名前も文のファイルの1か所の名前から組み立てる(): void
    {
        $id = $this->trainee('モモ');
        $this->intakeToken($id, 'intaketoken000000000000000000009');
        $this->assertIntakeSurveyNameOnly($this->card($this->open(), 'モモちゃん'));

        // client_survey_notice.intake は、同じファイルの名前（$intakeSurveyName）から組み立てる
        $source = file_get_contents(lang_path('ja/messages.php'));
        $this->assertSame(1, substr_count($source, "\$intakeSurveyNameParts = ['愛犬', 'アンケート'];"));
        $this->assertSame(0, substr_count($source, "'愛犬アンケート'"));
        $this->assertStringContainsString("'intake_survey_name' => \$intakeSurveyName,", $source);
        $this->assertStringContainsString("'intake' => \$intakeSurveyName.'にお答えください。',", $source);
        $this->assertSame('愛犬アンケート', __('messages.intake_survey_name'));
        $this->assertSame(['愛犬', 'アンケート'], __('messages.intake_survey_name_parts'));
        $this->assertStringNotContainsString('受付アンケート', $source);
    }

    // ---- 答え直し（2026-10-19 追加：回答したあとも、いつでも案内を発行し直して答え直してもらえる） ----

    public function test_回答済みの愛犬に答え直しの案内を出したらお知らせを出す(): void
    {
        $id = $this->trainee('モモ');
        $answeredTokenId = $this->intakeToken($id, 'intaketoken000000000000000000010', '2026-10-10 09:00:00');
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $id, 'trainee_intake_survey_token_id' => $answeredTokenId, 'answered_at' => '2026-10-10 09:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $card = $this->card($this->open(), 'モモちゃん');
        $this->assertSame([], $this->noticeKinds($card));

        $this->intakeToken($id, 'intaketoken000000000000000000011');
        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertSame(['intake'], $this->noticeKinds($card));
        $this->assertStringContainsString(route('client-portal.intake-survey.show', ['token' => 'intaketoken000000000000000000011']), $card);
    }

    // ---- 有効期限（2026-10-19 追加：案内に 1 週間の有効期限を付けた。「今日」を固定する） ----

    public function test_期限が切れた案内のお知らせは出さず期限が空の案内は出す(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $expired = $this->trainee('モモ');
        $this->intakeToken($expired, 'intaketoken-expired-0000000000001');
        $this->personalityToken($expired, 'pdtoken-expired-00000000000000001');
        DB::table('trainee_intake_survey_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);
        DB::table('trainee_personality_diagnosis_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);
        $noExpiry = $this->trainee('ハナ');
        $this->intakeToken($noExpiry, 'intaketoken-noexpiry-000000000001');
        $this->personalityToken($noExpiry, 'pdtoken-noexpiry-0000000000000001');

        $html = $this->open();

        $this->assertSame([], $this->noticeKinds($this->card($html, 'モモちゃん')));
        $this->assertSame(['intake', 'personality'], $this->noticeKinds($this->card($html, 'ハナちゃん')));

        // 期限の日のうちは出す
        $this->travelTo(Carbon::parse('2026-10-26 23:59:59'));
        $this->assertSame(['intake', 'personality'], $this->noticeKinds($this->card($this->open(), 'モモちゃん')));
    }

    // ---- 愛犬アンケート＆愛犬性格診断の案内（2026-10-19 追加。設計書 S-1402「回答待ちの案内のお知らせ」） ----

    /** 組み合わせの案内（2 つの案内を作って結び付ける）。愛犬アンケートの案内の id を返す */
    private function combinedTokens(int $traineeId, string $intakeToken, string $personalityToken): int
    {
        $row = ['trainee_id' => $traineeId, 'created_at' => now(), 'updated_at' => now()];
        $personalityId = DB::table('trainee_personality_diagnosis_tokens')->insertGetId(['token' => $personalityToken, ...$row]);

        return DB::table('trainee_intake_survey_tokens')->insertGetId([
            'token' => $intakeToken, 'trainee_personality_diagnosis_token_id' => $personalityId, ...$row,
        ]);
    }

    public function test_組み合わせで2つとも回答待ちならお知らせを1つ出し回答するは組み合わせのリンク(): void
    {
        $id = $this->trainee('モモ');
        $this->combinedTokens($id, 'intaketoken000000000000000000001', 'pdtoken0000000000000000000000001');

        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertSame(['combined'], $this->noticeKinds($card));
        // 色は愛犬性格診断と同じ紫のクラス（2026-10-19 変更：その前は緑と紫の 2 色の c-survey-notice--combined）
        $this->assertStringContainsString('<div class="c-survey-notice c-survey-notice--personality" data-client-survey-notice="combined">', $card);
        $this->assertStringNotContainsString('c-survey-notice--combined', $card);
        // 2 色の指定（グラデーション）は、ビルドした会員側の CSS にも残っていない（2026-10-19 に外した）
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $css = file_get_contents(public_path('build/'.$manifest['resources/sass/client.scss']['file']));
        $this->assertStringNotContainsString('c-survey-notice--combined', $css);
        $this->assertStringContainsString('<span>愛犬アンケート＆愛犬性格診断にお答えください。</span>', $card);
        $this->assertStringContainsString(
            '<a href="'.route('client-portal.intake-survey.show', ['token' => 'intaketoken000000000000000000001']).'" class="btn btn-primary btn-sm text-nowrap">回答する</a>',
            $card
        );
        // アイコンは 2 つ（愛犬アンケートと愛犬性格診断）
        $this->assertSame(2, substr_count($card, '<svg class="c-survey-notice-icon"'));
    }

    public function test_組み合わせで愛犬アンケートを答えたあとは愛犬性格診断のお知らせ(): void
    {
        $id = $this->trainee('モモ');
        $intakeId = $this->combinedTokens($id, 'intaketoken000000000000000000001', 'pdtoken0000000000000000000000001');
        DB::table('trainee_intake_survey_tokens')->where('id', $intakeId)->update(['answered_at' => now()]);

        $card = $this->card($this->open(), 'モモちゃん');

        $this->assertSame(['personality'], $this->noticeKinds($card));
        $this->assertStringContainsString('<span>愛犬性格診断にお答えください。</span>', $card);
        $this->assertStringContainsString(
            '<a href="'.route('client-portal.personality-diagnosis.show', ['token' => 'pdtoken0000000000000000000000001']).'" class="btn btn-primary btn-sm text-nowrap">回答する</a>',
            $card
        );
    }
}
