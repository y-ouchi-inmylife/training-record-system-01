<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Support\MypageRegistrationInvite;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 愛犬アンケート・愛犬性格診断の案内に、マイページ登録の案内を付ける（2026-10-19 追加。要件定義書 6-16-18）。
 *
 * - 発行：「マイページ登録の案内も発行する」（with_mypage_registration）がオンなら、同じトランザクションでマイページ登録の案内を作る
 * - 作れるのは、有効なマイページ登録の案内がない会員（「未登録」「登録待ち（期限切れ）」）だけ（2026-10-19 変更）
 * - 完了の画面：答えた会員に有効なマイページ登録の案内があれば、お誘いと［マイページに登録する］（2026-10-19 変更：その前は、
 *   案内に結び付けたマイページ登録の案内があるときだけ）
 *
 * - ご案内の文面（S-0311・S-0312・S-0314）：会員に有効なマイページ登録の案内があれば、マイページ登録の段を足す（2026-10-20 追加。
 *   判定は完了の画面のお誘いと同じ MypageRegistrationInvite::activeToken）
 *
 * 「今日」は 2026-10-19 に固定する（マイページ登録の案内の期限は 7 日後の日の終わり＝2026-10-26 23:59:59）。
 */
class SurveyMypageRegistrationTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 10:00:00');

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
        Schema::create('client_email_registration_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('client_id');
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
        });
        Schema::create('client_login_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('client_id');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsTrainer(): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    /** 発行の送信先（種類ごと） */
    private const STORE_ROUTES = [
        'intake' => 'trainee-intake-survey-tokens.store',
        'combined' => 'trainee-combined-survey-tokens.store',
    ];

    private function issue(string $kind, bool $withMypage = true)
    {
        $this->actingAsTrainer();

        return $this->post(route(self::STORE_ROUTES[$kind], $this->trainee), $withMypage ? ['with_mypage_registration' => '1'] : []);
    }

    private function registrationRows()
    {
        return DB::table('client_email_registration_tokens')->orderBy('id')->get();
    }

    private function registrationToken(string $token = 'regtokenregtokenregtokenregtoken', string $expiresAt = '2026-10-26 23:59:59', bool $used = false): int
    {
        return DB::table('client_email_registration_tokens')->insertGetId([
            'token' => $token, 'client_id' => $this->client->id, 'expires_at' => $expiresAt, 'is_used' => $used,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- 発行 ----

    public function test_オンで発行するとマイページ登録の案内ができる2つの種類とも(): void
    {
        // 2026-10-19 変更：単独の愛犬性格診断の案内は新しく発行しない（発行のモーダルは 2 択）
        $names = ['intake' => '愛犬アンケート', 'combined' => '愛犬アンケート＆愛犬性格診断'];
        foreach ($names as $kind => $name) {
            DB::table('trainee_intake_survey_tokens')->delete();
            DB::table('trainee_personality_diagnosis_tokens')->delete();
            DB::table('client_email_registration_tokens')->delete();

            $this->issue($kind)
                ->assertRedirect(route('clients.show', $this->client))
                ->assertSessionHas('success', 'モモの'.$name.'の案内を発行しました。マイページ登録の案内も発行しました。');

            $registrations = $this->registrationRows();
            $this->assertCount(1, $registrations, $kind);
            $this->assertSame('2026-10-26 23:59:59', $registrations[0]->expires_at);
            $this->assertSame(200, (int) $registrations[0]->created_by);
        }
        // 案内の表に結び付けの列はない（2026-10-19 変更：2026_10_19_000004 で外した）
        $this->assertFalse(Schema::hasColumn('trainee_intake_survey_tokens', 'client_email_registration_token_id'));
        $this->assertFalse(Schema::hasColumn('trainee_personality_diagnosis_tokens', 'client_email_registration_token_id'));
    }

    public function test_期限切れのマイページ登録の案内しかなければ作り直す(): void
    {
        $this->registrationToken('expiredexpiredexpiredexpiredexpi', '2026-10-18 23:59:59');

        $this->issue('combined')->assertSessionHas('success', 'モモの愛犬アンケート＆愛犬性格診断の案内を発行しました。マイページ登録の案内も発行しました。');

        // 今の発行し直しと同じく、期限切れの案内は消して 1 件作る
        $rows = $this->registrationRows();
        $this->assertCount(1, $rows);
        $this->assertNotSame('expiredexpiredexpiredexpiredexpi', $rows[0]->token);
        $this->assertSame('2026-10-26 23:59:59', $rows[0]->expires_at);
    }

    public function test_登録待ちの会員にオンで送られても新しく作らない(): void
    {
        $existing = $this->registrationToken();

        // 完了メッセージに一言は付けない（2026-10-19 変更：その前は「発行済みのものを使います」）
        $this->issue('intake')->assertSessionHas('success', 'モモの愛犬アンケートの案内を発行しました。');

        $rows = $this->registrationRows();
        $this->assertCount(1, $rows);
        $this->assertSame($existing, $rows[0]->id);
    }

    public function test_オフならマイページ登録の案内を作らない(): void
    {
        foreach (array_keys(self::STORE_ROUTES) as $kind) {
            DB::table('trainee_intake_survey_tokens')->delete();
            DB::table('trainee_personality_diagnosis_tokens')->delete();

            $this->issue($kind, withMypage: false)->assertSessionHas('success', fn (string $message) => ! str_contains($message, 'マイページ'));
        }

        $this->assertCount(0, $this->registrationRows());
    }

    public function test_利用中と登録待ち初回設定の会員にはオンで送られても作らない(): void
    {
        foreach ([['email' => 'a@example.com', 'password' => 'x'], ['email' => 'a@example.com', 'password' => null]] as $state) {
            DB::table('clients')->where('id', $this->client->id)->update($state);
            DB::table('trainee_intake_survey_tokens')->delete();

            $this->issue('intake')->assertSessionHas('success', 'モモの愛犬アンケートの案内を発行しました。');
        }

        $this->assertCount(0, $this->registrationRows());
    }

    public function test_回答待ちがあって発行しなかったときはマイページ登録の案内も作らない(): void
    {
        $this->issue('intake', withMypage: false);

        $this->issue('combined')->assertSessionHas('error');

        $this->assertCount(0, $this->registrationRows());
    }

    public function test_単独の愛犬性格診断の発行を送られてもマイページ登録の案内は作らない(): void
    {
        // 2026-10-19 変更：単独の愛犬性格診断の案内は新しく発行しない（作らずエラーの知らせ）。オンで送られてもマイページ登録の案内は作らない
        $this->actingAsTrainer();
        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee), ['with_mypage_registration' => '1'])
            ->assertSessionHas('error');

        $this->assertCount(0, $this->registrationRows());
        $this->assertSame(0, DB::table('trainee_personality_diagnosis_tokens')->count());
    }

    public function test_操作の記録は案内の発行の1件だけ(): void
    {
        $this->issue('combined');

        // マイページ登録の案内の発行は、今も操作の記録を残していない（会員詳細の［マイページ登録の案内を発行］と同じ）
        $this->assertSame(['create_trainee_combined_survey_token'], AccessLog::query()->orderBy('id')->pluck('action')->all());
    }

    // ---- 完了の画面のお誘い ----

    private const INTAKE = 'intaketokenintaketokenintaketoke';

    private const PERSONALITY = 'personalitytokenpersonalitytoken';

    private function surveyTokens(bool $combined = false): void
    {
        $row = ['trainee_id' => $this->trainee->id, 'expires_at' => '2026-10-26 23:59:59', 'created_at' => now(), 'updated_at' => now()];
        $personalityId = DB::table('trainee_personality_diagnosis_tokens')->insertGetId(['token' => self::PERSONALITY, ...$row]);
        DB::table('trainee_intake_survey_tokens')->insert([
            'token' => self::INTAKE, 'trainee_personality_diagnosis_token_id' => $combined ? $personalityId : null, ...$row,
        ]);
    }

    private function resetTokens(): void
    {
        DB::table('trainee_intake_survey_tokens')->delete();
        DB::table('trainee_personality_diagnosis_tokens')->delete();
        DB::table('client_email_registration_tokens')->delete();
    }

    private function answerIntake()
    {
        return $this->post(route('client-portal.intake-survey.store', ['token' => self::INTAKE]), ['name' => 'モモ']);
    }

    private function answerPersonality()
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $answers = array_map(fn ($v) => (string) $v, collect($json['cases'])->firstWhere('name', 'past-1')['answers']);

        return $this->post(route('client-portal.personality-diagnosis.store', ['token' => self::PERSONALITY]), ['answers' => $answers]);
    }

    private function thanksPage(string $route): string
    {
        return $this->get(route($route))->assertOk()->getContent();
    }

    private function assertInvite(string $html, string $token = 'regtokenregtokenregtokenregtoken'): void
    {
        $this->assertStringContainsString('data-mypage-registration-invite', $html);
        // リードの文と、マイページでできることの 3 つの項目をこの順に（2026-10-19 変更：その前は「…トレーニングの記録や愛犬の診断結果を…」の 1 文）
        // 「パソコンやスマートフォンで」は項目 1 からリードに移した（2026-10-19 変更）。リードと各項目は text-wrap: pretty
        $this->assertStringContainsString('<p class="mb-2 c-text-pretty">マイページにご登録いただくと、パソコンやスマートフォンで次のことができます。</p>', $html);
        $this->assertStringNotContainsString('マイページにご登録いただくと、次のことができます。', $html);
        $this->assertStringNotContainsString('パソコンやスマートフォンでいつでも', $html);
        $this->assertSame(1, preg_match('/data-mypage-registration-invite-items>(.*?)<\/ul>/su', $html, $m));
        preg_match_all('/<li><svg class="c-invite-list-icon"[^>]*aria-hidden="true"[^>]*>.*?<\/svg><span>([^<]*)<\/span><\/li>/su', $m[1], $items);
        $this->assertSame([
            '愛犬性格診断の結果を、いつでもご覧いただけます',
            '愛犬の写真を登録できます',
            '愛犬のプロフィールをご覧いただけます',
        ], $items[1]);
        $this->assertStringNotContainsString('トレーニングの記録や', $html);
        $this->assertStringContainsString(
            '<a href="'.route('client-portal.email-registration.show', ['token' => $token]).'" class="btn btn-primary">マイページに登録する</a>',
            $html
        );
    }

    public function test_会員詳細から別に発行した有効な案内があれば単独の案内の完了の画面にお誘いを出す(): void
    {
        // チェックボックスを使わずに発行した案内（結び付けなし）でも、会員に有効なマイページ登録の案内があれば出す
        $this->registrationToken();
        $this->surveyTokens();

        $this->answerIntake()->assertRedirect(route('client-portal.intake-survey.thanks'));
        $this->assertInvite($this->thanksPage('client-portal.intake-survey.thanks'));

        $this->answerPersonality()->assertRedirect(route('client-portal.personality-diagnosis.thanks'));
        $this->assertInvite($this->thanksPage('client-portal.personality-diagnosis.thanks'));
    }

    public function test_チェックで発行した案内に答えるとお誘いのリンクはその会員の有効な案内(): void
    {
        $this->issue('intake');
        $registration = $this->registrationRows()[0]->token;
        $intake = DB::table('trainee_intake_survey_tokens')->value('token');
        auth('web')->logout();

        $this->post(route('client-portal.intake-survey.store', ['token' => $intake]), ['name' => 'モモ'])
            ->assertRedirect(route('client-portal.intake-survey.thanks'));
        $this->assertInvite($this->thanksPage('client-portal.intake-survey.thanks'), $registration);
    }

    public function test_組み合わせは最後の完了の画面だけに出し途中には出さない(): void
    {
        $this->registrationToken();
        $this->surveyTokens(combined: true);

        // 愛犬アンケートのあと：愛犬性格診断へ進む（お誘いは出さない）
        $this->answerIntake()->assertRedirect(route('client-portal.personality-diagnosis.show', ['token' => self::PERSONALITY]));
        $html = $this->get(route('client-portal.personality-diagnosis.show', ['token' => self::PERSONALITY]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-mypage-registration-invite', $html);
        $this->assertStringNotContainsString('次のことができます', $html);

        // 愛犬性格診断のあと：完了の画面にお誘い
        $this->answerPersonality();
        $this->assertInvite($this->thanksPage('client-portal.personality-diagnosis.thanks'));
    }

    public function test_有効な案内がない期限切れ使用済みなら出さない(): void
    {
        $cases = [
            'none' => fn () => null,
            'expired' => fn () => $this->registrationToken(expiresAt: '2026-10-18 23:59:59'),
            'used' => fn () => $this->registrationToken(used: true),
        ];
        foreach ($cases as $case => $make) {
            $this->resetTokens();
            $make();
            $this->surveyTokens();

            $this->answerIntake()->assertRedirect(route('client-portal.intake-survey.thanks'));
            $html = $this->thanksPage('client-portal.intake-survey.thanks');
            $this->assertStringNotContainsString('data-mypage-registration-invite', $html, $case);
            $this->assertStringNotContainsString('次のことができます', $html, $case);
            $this->assertStringNotContainsString('愛犬の写真を登録できます', $html, $case);
        }
    }

    public function test_会員がもう利用中か登録の途中かログインして答えたなら出さない(): void
    {
        foreach (['in_use' => ['email' => 'a@example.com', 'password' => 'x'], 'setup' => ['email' => 'a@example.com', 'password' => null]] as $case => $state) {
            $this->resetTokens();
            DB::table('clients')->where('id', $this->client->id)->update($state);
            $this->registrationToken();
            $this->surveyTokens();

            $this->answerIntake();
            $html = $this->thanksPage('client-portal.intake-survey.thanks');
            $this->assertStringNotContainsString('data-mypage-registration-invite', $html, $case);
            $this->assertStringNotContainsString('次のことができます', $html, $case);
            $this->assertStringNotContainsString('愛犬の写真を登録できます', $html, $case);
        }

        // ログインして答えた（マイページから開いた）：今の［マイページへ戻る］のまま
        DB::table('clients')->where('id', $this->client->id)->update(['email' => null, 'password' => null]);
        $this->resetTokens();
        $this->registrationToken();
        $this->surveyTokens();
        $this->actingAs($this->client->fresh(), 'client');
        $this->answerIntake();
        $html = $this->thanksPage('client-portal.intake-survey.thanks');
        $this->assertStringNotContainsString('data-mypage-registration-invite', $html);
        $this->assertStringNotContainsString('次のことができます', $html);
        $this->assertStringContainsString('マイページへ戻る', $html);
    }

    // ---- ご案内の文面のマイページ登録の段（2026-10-20 追加：完了の画面のお誘いは画面を閉じるともう一度たどり着けないため） ----

    /** ご案内の画面（種類ごと）と、その QR コードの中身（愛犬アンケート・愛犬性格診断のリンク） */
    private const PRINT_PAGES = [
        'intake' => ['route' => 'trainee-intake-survey-tokens.print', 'qr' => ['client-portal.intake-survey.show', self::INTAKE]],
        'personality' => ['route' => 'trainee-personality-diagnosis-tokens.print', 'qr' => ['client-portal.personality-diagnosis.show', self::PERSONALITY]],
        'combined' => ['route' => 'trainee-combined-survey-tokens.print', 'qr' => ['client-portal.intake-survey.show', self::INTAKE]],
    ];

    /** 種類ごとの愛犬アンケート・愛犬性格診断の案内を入れる（組み合わせは 2 つを結び付ける。単独の愛犬性格診断は愛犬アンケートを消す） */
    private function invitationTokens(string $kind): void
    {
        DB::table('trainee_intake_survey_tokens')->delete();
        DB::table('trainee_personality_diagnosis_tokens')->delete();
        $this->surveyTokens($kind === 'combined');
        if ($kind === 'personality') {
            DB::table('trainee_intake_survey_tokens')->delete();
        }
    }

    /**
     * ご案内の画面を開き、画面に出す文面・コピーする文面・QR コードの中身を返す
     *
     * @return array{screen: string, copy: string, qr: string}
     */
    private function invitationPage(string $kind): array
    {
        $this->actingAsTrainer();
        $html = $this->get(route(self::PRINT_PAGES[$kind]['route'], $this->trainee))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/data-copy-text="([^"]*)"/u', $html, $copy));
        $this->assertSame(1, preg_match('/<div class="invitation-message" data-invitation-message>(.*?)<\/div>/su', $html, $screen));
        $this->assertSame(1, preg_match('/data-qr-url="([^"]*)"/u', $html, $qr));

        return [
            'screen' => html_entity_decode($screen[1], ENT_QUOTES),
            'copy' => html_entity_decode($copy[1], ENT_QUOTES),
            'qr' => html_entity_decode($qr[1], ENT_QUOTES),
        ];
    }

    /** マイページ登録の段（有効期限の行から「ご不明な点が…」まで） */
    private function mypageInviteTail(string $token = 'regtokenregtokenregtokenregtoken', string $expires = '2026年10月26日（月）'): string
    {
        return "リンクの有効期限：2026年10月26日（月）まで\n"
            ."\n"
            ."━━━━━━━━━━━━\n"
            ."あわせて、マイページへのご登録もお願いいたします。\n"
            ."マイページにご登録いただくと、パソコンやスマートフォンで、愛犬性格診断の結果をいつでもご覧いただけます。\n"
            ."\n"
            .route('client-portal.email-registration.show', ['token' => $token])."\n"
            ."\n"
            ."リンクの有効期限：{$expires}まで\n"
            ."━━━━━━━━━━━━\n"
            ."\n"
            .'ご不明な点がございましたら、お気軽にお問い合わせください。';
    }

    /** 段がないときの終わり（今の文面のまま：有効期限の行のすぐあとに「ご不明な点が…」） */
    private const PLAIN_TAIL = "リンクの有効期限：2026年10月26日（月）まで\nご不明な点がございましたら、お気軽にお問い合わせください。";

    public function test_有効なマイページ登録の案内があれば3つのご案内の文面に段を足し画面とコピーが同じでQRコードは今のまま(): void
    {
        $this->registrationToken(expiresAt: '2026-10-25 23:59:59');

        foreach (array_keys(self::PRINT_PAGES) as $kind) {
            $this->invitationTokens($kind);
            $page = $this->invitationPage($kind);

            $this->assertSame($page['copy'], $page['screen'], $kind);
            $this->assertStringEndsWith($this->mypageInviteTail(expires: '2026年10月25日（日）'), $page['copy'], $kind);
            $this->assertSame(1, substr_count($page['copy'], '━━━━━━━━━━━━'."\n".'あわせて'), $kind);
            // QR コードの中身は今のまま（愛犬アンケート・愛犬性格診断のリンクだけ）
            [$qrRoute, $qrToken] = self::PRINT_PAGES[$kind]['qr'];
            $this->assertSame(route($qrRoute, ['token' => $qrToken]), $page['qr'], $kind);
        }
    }

    public function test_有効なマイページ登録の案内がないときは今の文面のまま(): void
    {
        $cases = [
            '案内なし' => fn () => null,
            '期限切れ' => fn () => $this->registrationToken(expiresAt: '2026-10-18 23:59:59'),
            '使用済み' => fn () => $this->registrationToken(used: true),
            '取消済み' => fn () => DB::table('client_email_registration_tokens')->where('id', $this->registrationToken())->delete(),
            '利用中' => function () {
                $this->registrationToken();
                DB::table('clients')->where('id', $this->client->id)->update(['email' => 'yamada@example.com', 'password' => 'hashed']);
            },
            '登録待ち（初回設定）' => function () {
                $this->registrationToken();
                DB::table('clients')->where('id', $this->client->id)->update(['email' => 'yamada@example.com']);
            },
        ];
        foreach ($cases as $name => $prepare) {
            DB::table('client_email_registration_tokens')->delete();
            DB::table('clients')->where('id', $this->client->id)->update(['email' => null, 'password' => null]);
            $prepare();
            foreach (array_keys(self::PRINT_PAGES) as $kind) {
                $this->invitationTokens($kind);
                $page = $this->invitationPage($kind);
                $this->assertStringEndsWith(self::PLAIN_TAIL, $page['copy'], $name.' '.$kind);
                $this->assertStringNotContainsString('━', $page['copy'], $name.' '.$kind);
                $this->assertStringNotContainsString('マイページ', $page['copy'], $name.' '.$kind);
                $this->assertSame($page['copy'], $page['screen'], $name.' '.$kind);
            }
        }
    }

    public function test_チェックボックスで一緒に発行したときも会員詳細から別に発行したときも段を足す(): void
    {
        // チェックボックスで一緒に発行
        $this->issue('intake')->assertSessionHasNoErrors();
        $token = $this->registrationRows()->first()->token;
        $this->assertStringEndsWith($this->mypageInviteTail($token), $this->invitationPage('intake')['copy']);

        // 会員詳細の［マイページ登録の案内を発行］で別に発行し、チェックボックスを付けずに案内を発行
        $this->resetTokens();
        $this->actingAsTrainer();
        $this->post(route('client-email-registration-tokens.store', $this->client));
        $this->assertCount(1, $this->registrationRows());
        $this->issue('combined', false)->assertSessionHasNoErrors();
        $this->assertCount(1, $this->registrationRows());
        $token = $this->registrationRows()->first()->token;
        $this->assertStringEndsWith($this->mypageInviteTail($token), $this->invitationPage('combined')['copy']);
    }

    public function test_呼び名が空のときと組み合わせのときも崩れない(): void
    {
        $this->trainee->update(['name' => null]);
        $this->registrationToken();
        $this->invitationTokens('combined');

        $copy = $this->invitationPage('combined')['copy'];

        $company = config('app.client_portal_company');
        $expected = "山田 太郎 様\n\n"
            .(blank($company) ? '' : $company."です。\n")
            ."愛犬アンケートと愛犬性格診断のご案内をお送りします。\n\n"
            ."下のリンクから、続けてご回答をお願いいたします。（愛犬性格診断は全50問です）\n\n"
            .route('client-portal.intake-survey.show', ['token' => self::INTAKE])."\n\n"
            .$this->mypageInviteTail();
        $this->assertSame($expected, $copy);
    }

    public function test_段を足すかの判定は完了の画面のお誘いと同じ(): void
    {
        $cases = [
            '有効' => fn () => $this->registrationToken(),
            '期限切れ' => fn () => $this->registrationToken(expiresAt: '2026-10-18 23:59:59'),
            '使用済み' => fn () => $this->registrationToken(used: true),
            '利用中' => function () {
                $this->registrationToken();
                DB::table('clients')->where('id', $this->client->id)->update(['email' => 'yamada@example.com', 'password' => 'hashed']);
            },
        ];
        foreach ($cases as $name => $prepare) {
            DB::table('client_email_registration_tokens')->delete();
            DB::table('clients')->where('id', $this->client->id)->update(['email' => null, 'password' => null]);
            $prepare();
            $this->invitationTokens('intake');
            $copy = $this->invitationPage('intake')['copy'];
            // 完了の画面のお誘い（会員のログインなし）と、文面の段の有無・リンクがそろう（同じ MypageRegistrationInvite::activeToken）
            auth('web')->logout();
            $inviteUrl = MypageRegistrationInvite::url($this->trainee->id);
            $this->assertSame($inviteUrl !== null, str_contains($copy, '━━━━━━━━━━━━'), $name);
            if ($inviteUrl !== null) {
                $this->assertStringContainsString("\n".$inviteUrl."\n", $copy, $name);
            }
        }
    }
}
