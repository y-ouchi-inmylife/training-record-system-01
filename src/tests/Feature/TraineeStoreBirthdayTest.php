<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーニーの登録（POST /clients/{client}/trainees、S-0308）で、誕生日の全角の数字が
 * 半角の整数として保存されること（TraineeRequest::prepareForValidation。2026-10 追加）。
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 * テスト用 SQLite では RefreshDatabase が使えないため、必要な列だけのテーブルを作る。
 */
class TraineeStoreBirthdayTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');

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
            $table->text('note')->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_全角の数字と前後の空白は半角の整数として保存される(): void
    {
        $response = $this->post(route('trainees.store', $this->client), [
            'name' => 'モモ',
            'birth_year' => '　２０２０ ',
            'birth_month' => '０３',
            'birth_day' => '5',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('clients.show', $this->client));

        $row = (array) DB::table('trainees')->sole();
        $this->assertSame(2020, (int) $row['birth_year']);
        $this->assertSame(3, (int) $row['birth_month']);
        $this->assertSame(5, (int) $row['birth_day']);
        // DB に入った値そのものも、全角や空白を含まない数字
        $this->assertMatchesRegularExpression('/\A\d+\z/', (string) $row['birth_year']);

        $trainee = Trainee::query()->sole();
        $this->assertSame('2020/03/05', $trainee->birthday_label);
    }

    public function test_空白だけの欄は未入力として保存される(): void
    {
        $this->post(route('trainees.store', $this->client), [
            'name' => 'モモ',
            'birth_year' => '２０２０',
            'birth_month' => '　',
            'birth_day' => ' ',
        ])->assertSessionHasNoErrors();

        $row = (array) DB::table('trainees')->sole();
        $this->assertSame(2020, (int) $row['birth_year']);
        $this->assertNull($row['birth_month']);
        $this->assertNull($row['birth_day']);
    }
}
