<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 性格診断（S-1415）からの送信がセッション切れ（419）になったとき（2026-10 追加。性格診断 段階3。設計書 S-1415 備考
 * 「時間切れ（419）のとき」）。送られてきた回答を入れたまま回答の画面を 200 で出し直す。
 *
 * テストでは Laravel が CSRF トークンの確認を飛ばす（ValidateCsrfToken::runningUnitTests()）ため、受付アンケートの
 * 419 のテスト（ClientIntakeSurveySessionExpiredTest）と同じく、確認を飛ばさないように差し替えた ValidateCsrfToken を
 * コンテナに登録し、CSRF トークンを付けない POST で本物の 419（TokenMismatchException → 419 の HttpException）を起こす。
 */
class ClientPersonalityDiagnosisSessionExpiredTest extends TestCase
{
    use CreatesClientCountTables;

    private const TOKEN = 'personalitydiagnosistoken0000001';

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->createClientsTable();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $client->id, 'name' => 'モモ']);
    }

    private function issueToken(?string $answeredAt = null): void
    {
        DB::table('trainee_personality_diagnosis_tokens')->insert([
            'token' => self::TOKEN, 'trainee_id' => $this->trainee->id, 'answered_at' => $answeredAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function url(): string
    {
        return route('client-portal.personality-diagnosis.store', ['token' => self::TOKEN]);
    }

    /** 固定データの全問 0 の回答 */
    private function allZero(): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);

        return collect($json['cases'])->firstWhere('name', 'all-zero')['answers'];
    }

    public function test_時間切れなら答えを入れたまま回答の画面を200で出し直す(): void
    {
        $this->issueToken();

        // CSRF トークンを付けない送信 → 本物の 419
        $response = $this->post($this->url(), ['answers' => ['Q01' => '2', 'Q02' => 'na', 'Q03' => '4']]);

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('時間がたったため送信できませんでした。答えはそのまま残っています。もう一度［送信する］を押してください。', $html);
        $this->assertSame(50, substr_count($html, '<fieldset class="mb-3" id="q-Q'));
        $this->assertMatchesRegularExpression('/id="answer-Q01-2" value="2" autocomplete="off"\s*checked>/u', $html);
        $this->assertMatchesRegularExpression('/id="answer-Q02-na" value="na" autocomplete="off"\s*checked>/u', $html);
        $this->assertMatchesRegularExpression('/id="answer-Q03-4" value="4" autocomplete="off"\s*checked>/u', $html);
        $this->assertSame(3, substr_count($html, 'checked>'));
        // 未回答のエラーは出さない（時間切れの知らせだけ）
        $this->assertStringNotContainsString('まだ答えていない質問', $html);
        $this->assertStringNotContainsString('invalid-feedback', $html);
        $this->assertStringNotContainsString('alert-danger', $html);
        // 結果は保存されない
        $this->assertSame(0, TraineePersonalityDiagnosisResult::query()->count());
    }

    public function test_正しくない値と設問にないキーは戻さない(): void
    {
        $this->issueToken();

        $html = $this->post($this->url(), ['answers' => [
            'Q01' => '5', 'Q02' => 'abc', 'Q03' => ['2'], 'Q04' => '1', 'Q51' => '2', 'X' => 'na',
        ]])->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'checked>'));
        $this->assertMatchesRegularExpression('/id="answer-Q04-1" value="1" autocomplete="off"\s*checked>/u', $html);
        $this->assertStringNotContainsString('Q51', $html);
        $this->assertStringNotContainsString('abc', $html);
    }

    public function test_出し直した画面の新しいCSRFトークンでそのまま送り直せる(): void
    {
        $this->issueToken();

        $html = $this->post($this->url(), ['answers' => $this->allZero()])->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $html, $m));
        $this->assertSame(session()->token(), $m[1]);

        $this->post($this->url(), ['_token' => $m[1], 'answers' => $this->allZero()])
            ->assertRedirect(route('client-portal.personality-diagnosis.thanks'));
        $this->assertSame(1, TraineePersonalityDiagnosisResult::query()->count());
    }

    public function test_案内が回答済みならすでに回答の画面を出す(): void
    {
        $this->issueToken('2026-10-15 09:00:00');

        $response = $this->post($this->url(), ['answers' => $this->allZero()]);

        $response->assertOk();
        $response->assertSee('この性格診断はすでにご回答いただいています。');
        $response->assertDontSee('時間がたったため送信できませんでした');
        $this->assertSame(0, TraineePersonalityDiagnosisResult::query()->count());
    }

    public function test_案内が取消されていれば使えない画面を出す(): void
    {
        $response = $this->post($this->url(), ['answers' => $this->allZero()]);

        $response->assertOk();
        $response->assertSee('この性格診断はご利用いただけません。お手数ですがトレーナーにお問い合わせください。');
        $response->assertDontSee('時間がたったため送信できませんでした');
    }

    public function test_受付アンケートの419は今までどおり専用の画面(): void
    {
        $response = $this->post('/client-portal/intake-survey/abcdefghijklmnopqrstuvwxyz012345', ['breed' => '柴']);

        $response->assertStatus(419);
        $response->assertSee('時間がたったため送信できませんでした');
        $response->assertSee('はじめからご入力ください。');
    }
}
