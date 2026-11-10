<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員詳細（S-0305）のメールアドレス欄の［メールが届かないとき］（2026-11-10 追加。設計書 S-0305「状態と状態別ボタンの対応」）。
 *
 * 「登録待ち（初回設定）」（初回設定待ちで期限内）のときだけ出し、S-0316 を同じタブで開く。期限切れ・ほかの状態では出さない。
 * 判定はモデルの Client::isAwaitingSetupWithinExpiry()（S-0316 のコントローラと同じ）。
 */
class ClientDetailLoginLinkMailButtonTest extends TestCase
{
    use CreatesClientCountTables;

    private const BUTTON = '>メールが届かないとき</a>';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');

        // 会員詳細を描くのに要るテーブル（ClientCountRenderingTest と同じ作り）
        $this->createClientsTable();
        $this->createClientCountTables();
        $this->createAccessLogsTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
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
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->string('neuter_status', 20)->nullable();
        });
        Schema::create('trainee_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->date('measured_date');
            $table->time('measured_time');
        });
        Schema::create('audio_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
        });
        foreach (['client_email_registration_tokens', 'client_login_link_tokens'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('token', 64)->unique();
                $table->unsignedBigInteger('client_id');
                $table->timestamp('expires_at');
                $table->boolean('is_used')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function issueRegistrationToken(string $expiresAt = '2026-11-17 23:59:59', bool $isUsed = false): void
    {
        DB::table('client_email_registration_tokens')->insert([
            'token' => 'email-registration-token-button-00', 'client_id' => $this->client->id,
            'expires_at' => $expiresAt, 'is_used' => $isUsed,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function registerEmail(string $expiresAt = '2026-11-17 23:59:59'): void
    {
        $this->client->update(['email' => 'owner@example.com']);
        DB::table('client_login_link_tokens')->insert([
            'token' => 'login-link-token-for-button-00000', 'client_id' => $this->client->id,
            'expires_at' => $expiresAt, 'is_used' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function detailPage(): string
    {
        return $this->get(route('clients.show', $this->client))->assertOk()->getContent();
    }

    public function test_登録待ち初回設定なら_バッジの右に白抜きの小さいボタンを出し_初回設定メール表示画面を同じタブで開く(): void
    {
        $this->issueRegistrationToken();
        $this->registerEmail();

        $html = $this->detailPage();

        $this->assertStringContainsString('<span class="badge bg-warning text-dark">登録待ち（初回設定）</span>', $html);
        $this->assertSame(1, substr_count($html, self::BUTTON));
        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route('client-login-link-mail.show', $this->client), '/').'"\s+class="btn btn-sm btn-outline-primary text-nowrap">メールが届かないとき<\/a>/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*target="_blank"[^>]*>メールが届かないとき/u', $html);
        // マイページ登録の案内のボタンではないので、ボタンのまとまりに role="group" は付けない
        $this->assertStringNotContainsString('aria-label="マイページ登録の案内"', $html);
    }

    public function test_ボタンはバッジのすぐ右に横並びで置き_右寄せのまとまりには入れない(): void
    {
        $this->issueRegistrationToken();
        $this->registerEmail();

        $html = $this->detailPage();

        // メールアドレス → バッジ → ボタン の順に、同じ行（flex-wrap・align-items-center・gap-2）の中に並ぶ（2026-11-10 変更）。
        // バッジとボタンのあいだにはコメントだけ（Blade のコメントは出力されない）
        $url = preg_quote(route('client-login-link-mail.show', $this->client), '/');
        $this->assertMatchesRegularExpression(
            '/<div class="d-flex align-items-center flex-wrap gap-2" style="min-height: 1.5rem;">\s*'
            .'<span>owner@example\.com<\/span>\s*'
            .'<span class="badge bg-warning text-dark">登録待ち（初回設定）<\/span>\s*'
            .'<a href="'.$url.'"\s+class="btn btn-sm btn-outline-primary text-nowrap">メールが届かないとき<\/a>\s*'
            .'<\/div>/u',
            $html
        );
        // 案内のボタンがないので、右寄せのまとまり（ms-auto）は出さない（空の要素が余白や空の行を作らないように）
        $this->assertStringNotContainsString('<div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto"', $html);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function otherStates(): array
    {
        return [
            '初回設定待ちで期限切れ' => ['setup_expired', '登録待ち（期限切れ）'],
            '利用中' => ['in_use', '利用中'],
            'メールアドレス登録待ち' => ['awaiting_email', '登録待ち'],
            'メールアドレスなし' => ['no_email', '未登録'],
        ];
    }

    #[DataProvider('otherStates')]
    public function test_ほかの状態ではボタンを出さない(string $state, string $badge): void
    {
        match ($state) {
            'setup_expired' => [$this->issueRegistrationToken('2026-11-09 23:59:59'), $this->registerEmail('2026-11-09 23:59:59')],
            'in_use' => [$this->issueRegistrationToken(isUsed: true), $this->registerEmail(), $this->client->update(['password' => 'password-123'])],
            'awaiting_email' => [$this->issueRegistrationToken()],
            'no_email' => [],
        };

        $html = $this->detailPage();

        $this->assertMatchesRegularExpression('/<span class="badge [^"]+">'.preg_quote($badge, '/').'<\/span>/u', $html);
        $this->assertStringNotContainsString(self::BUTTON, $html);
        $this->assertStringNotContainsString(route('client-login-link-mail.show', $this->client), $html);
    }
}
