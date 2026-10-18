<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Services\ClientInternalIdService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員の登録でトレーニーを 1 件自動で作る・空のトレーニーの判定（2026-10-18 追加。要件定義書 6-3-1・6-3-5）。
 *
 * - 会員を登録すると、同じトランザクションでその会員のトレーニーを 1 件作る（中身は空）。操作の記録は create_client と create_trainee
 * - 入力エラーのときは会員もトレーニーもできない
 * - 「空のトレーニー」＝トレーニーの項目（名前・犬種・性別・誕生日・去勢避妊・写真）がどれも空で、結び付く 5 つの表
 *   （計測値・受付アンケートの案内と回答・愛犬性格診断の案内と結果）のどれにも行がない（Trainee::scopeNotBlank）
 * 会員の削除（空のトレーニーだけなら一緒に削除）は ClientDeleteConstraintTest で確かめる。
 */
class ClientRegistrationTraineeTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->integer('display_order')->default(0);
        });
        $this->createTraineesWithRelatedTables();

        // 内部 ID の採番は MySQL だけの SQL を使うので、テストでは差し替える
        $this->app->instance(ClientInternalIdService::class, new class extends ClientInternalIdService
        {
            public function generateNext(): int
            {
                return (int) DB::table('clients')->max('id') + 1;
            }
        });

        DB::table('trainers')->insert(['id' => 100, 'name' => '管理者太郎', 'role' => 'admin']);
        $admin = new Trainer(['name' => '管理者太郎', 'role' => 'admin']);
        $admin->id = 100;
        $admin->exists = true;
        $this->actingAs($admin, 'web');
    }

    private function register(array $overrides = [])
    {
        return $this->post(route('clients.store'), array_merge([
            'last_name' => '山田', 'first_name' => '太郎', 'initial_consultation_date' => '2026-10-18',
        ], $overrides));
    }

    // ---- 会員の登録 ----

    public function test_会員を登録するとその会員のトレーニーが1件でき中身は空(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $client = Client::query()->sole();
        $trainee = Trainee::query()->sole();
        $this->assertSame($client->id, (int) $trainee->client_id);
        foreach (Trainee::CONTENT_COLUMNS as $column) {
            $this->assertNull($trainee->{$column}, $column);
        }
        $this->assertSame(100, (int) $trainee->updated_by);
        $this->assertTrue($trainee->isBlank());
        $this->assertFalse($client->hasNonBlankTrainees());
    }

    public function test_登録のあとは会員詳細へ移り完了メッセージは今のまま(): void
    {
        $response = $this->register();

        $response->assertRedirect(route('clients.show', Client::query()->sole()));
        $response->assertSessionHas('success', '会員を登録しました。');
    }

    public function test_入力エラーのときは会員もトレーニーもできない(): void
    {
        $this->register(['last_name' => ''])->assertSessionHasErrors('last_name');

        $this->assertSame(0, Client::query()->count());
        $this->assertSame(0, Trainee::query()->count());
    }

    public function test_会員の保存に失敗したらトレーニーも作られない(): void
    {
        // 会員の表の内部IDの一意を破らせて、会員の保存を失敗させる
        Schema::table('clients', fn (Blueprint $t) => $t->unique('internal_id'));
        Client::query()->create(['internal_id' => '1', 'last_name' => '先客']);
        $this->app->instance(ClientInternalIdService::class, new class extends ClientInternalIdService
        {
            public function generateNext(): int
            {
                return 1;
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->register();
            $this->fail('会員の保存が失敗するはず');
        } catch (QueryException) {
        }

        $this->assertSame(1, Client::query()->count());
        $this->assertSame(0, Trainee::query()->count());
    }

    public function test_操作の記録は会員の登録とトレーニーの登録(): void
    {
        $this->register();

        $trainee = Trainee::query()->sole();
        $logs = AccessLog::query()->orderBy('action')->get(['action', 'target_type', 'target_id', 'trainer_id']);
        $this->assertSame(['create_client', 'create_trainee'], $logs->pluck('action')->all());
        $traineeLog = $logs->firstWhere('action', 'create_trainee');
        $this->assertSame('Trainee', $traineeLog->target_type);
        $this->assertSame($trainee->id, (int) $traineeLog->target_id);
        $this->assertSame(100, (int) $traineeLog->trainer_id);
    }

    public function test_今ある会員にはトレーニーを足さない(): void
    {
        Client::query()->create(['internal_id' => '9', 'last_name' => '既存']);

        $this->register();

        $this->assertSame(1, Trainee::query()->count());
        $this->assertSame(0, Trainee::query()->whereHas('client', fn ($q) => $q->where('last_name', '既存'))->count());
    }

    // ---- 空のトレーニーの判定 ----

    private function blankTrainee(): Trainee
    {
        $client = Client::query()->create(['internal_id' => '1', 'last_name' => '山田']);

        return Trainee::query()->create(['client_id' => $client->id]);
    }

    public function test_項目も結び付く表もなければ空(): void
    {
        $trainee = $this->blankTrainee();
        $trainee->update(['name' => '', 'breed' => '']);

        $this->assertTrue($trainee->isBlank());
        $this->assertFalse($trainee->client->hasNonBlankTrainees());
    }

    public static function contentColumns(): array
    {
        return [
            '名前' => ['name', 'ポチ'],
            '犬種' => ['breed', '柴犬'],
            '性別' => ['sex', 'male'],
            '誕生日の年' => ['birth_year', 2020],
            '誕生日の月' => ['birth_month', 3],
            '誕生日の日' => ['birth_day', 15],
            '去勢避妊' => ['neuter_status', 'neutered'],
            '写真' => ['photo_path', 'trainees/1/photo.jpg'],
        ];
    }

    #[DataProvider('contentColumns')]
    public function test_項目のどれか1つでも入っていれば空でない(string $column, mixed $value): void
    {
        $trainee = $this->blankTrainee();
        DB::table('trainees')->where('id', $trainee->id)->update([$column => $value]);

        $this->assertFalse($trainee->isBlank());
        $this->assertTrue($trainee->client->hasNonBlankTrainees());
    }

    public static function contentTables(): array
    {
        return [
            '計測値' => ['trainee_measurements', ['measured_date' => '2026-10-01', 'measured_time' => '10:00:00', 'weight_kg' => 5.0]],
            '受付アンケートの案内' => ['trainee_intake_survey_tokens', ['token' => str_repeat('a', 32)]],
            '受付アンケートの回答' => ['trainee_intake_survey_responses', ['answered_at' => '2026-10-01 10:00:00']],
            '愛犬性格診断の案内' => ['trainee_personality_diagnosis_tokens', ['token' => str_repeat('b', 32)]],
            '愛犬性格診断の結果' => ['trainee_personality_diagnosis_results', [
                'answered_at' => '2026-10-01 10:00:00', 'question_version' => 'v1', 'answers' => '{}',
            ]],
        ];
    }

    #[DataProvider('contentTables')]
    public function test_結び付く表に1行でもあれば空でない(string $table, array $row): void
    {
        $trainee = $this->blankTrainee();
        DB::table($table)->insert(['trainee_id' => $trainee->id] + $row);

        $this->assertFalse($trainee->isBlank());
        $this->assertTrue($trainee->client->hasNonBlankTrainees());
    }

    public function test_評価文だけの結果も空でない(): void
    {
        $trainee = $this->blankTrainee();
        DB::table('trainee_personality_diagnosis_results')->insert([
            'trainee_id' => $trainee->id, 'answered_at' => '2026-10-01 10:00:00', 'question_version' => 'v1', 'answers' => '{}',
            'evaluation_text' => '評価文',
        ]);

        $this->assertFalse($trainee->isBlank());
    }

    public function test_判定に入れた表はトレーニーに結び付く表のすべて(): void
    {
        // トレーニーに結び付く表を足したら、判定（Trainee::CONTENT_RELATIONS）にも足す
        $this->assertSame([
            'measurements', 'intakeSurveyTokens', 'intakeSurveyResponses', 'personalityDiagnosisTokens', 'personalityDiagnosisResults',
        ], Trainee::CONTENT_RELATIONS);
        $tables = [];
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (preg_match_all("/->on\\('trainees'\\)/", file_get_contents($file)) && preg_match("/Schema::(?:create|table)\\('([a-z_]+)'/", file_get_contents($file), $m)) {
                $tables[] = $m[1];
            }
        }
        sort($tables);
        $related = array_map(fn ($relation) => (new Trainee)->{$relation}()->getRelated()->getTable(), Trainee::CONTENT_RELATIONS);
        sort($related);
        $this->assertSame($tables, $related);
    }
}
