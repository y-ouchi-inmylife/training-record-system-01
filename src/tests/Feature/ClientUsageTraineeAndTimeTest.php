<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUsage;
use App\Models\Trainee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 利用（D-1000）のトレーニー・開始時刻・終了時刻（2026-11 追加。設計書 db-schema.md D-1000・requirements.md 6-17-9）。
 *
 * - トレーニーを削除しても利用は消えず、trainee_id が NULL（「トレーニーなし」）になる（外部キーの ON DELETE SET NULL）
 * - 会員の利用の並び順は「利用日 → 開始時刻 → id」
 * - 時刻の入力用（HH:MM）・表示用（8:00〜19:00）の形
 *
 * テスト用 SQLite ではマイグレーションが通らないため、既存のテストと同じく、確かめに必要な列だけでテーブルを作る。
 * 外部キーが効くよう、trainees の表を先に作ってから利用の表を作る（CreatesClientCountTables）。
 */
class ClientUsageTraineeAndTimeTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createClientCountTables();

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
    }

    private function usage(?Trainee $trainee, string $usedOn, string $startTime, string $endTime = '19:00'): ClientUsage
    {
        return ClientUsage::query()->create([
            'client_id' => $this->client->id,
            'trainee_id' => $trainee?->id,
            'used_on' => $usedOn,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    public function test_トレーニーを削除すると利用はトレーニーなしになって残る(): void
    {
        $ai = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'アイ']);
        $mo = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ']);
        $aiUsage = $this->usage($ai, '2026-11-01', '08:00');
        $moUsage = $this->usage($mo, '2026-11-01', '08:00');

        $ai->delete();

        $this->assertSame(2, ClientUsage::query()->count());
        $this->assertNull($aiUsage->fresh()->trainee_id);
        $this->assertSame($mo->id, $moUsage->fresh()->trainee_id);
    }

    public function test_利用はトレーニーを持つ(): void
    {
        $ai = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'アイ']);
        $usage = $this->usage($ai, '2026-11-01', '08:00');

        $this->assertSame('アイ', $usage->fresh()->trainee->name);
        $this->assertSame([$usage->id], $ai->usages()->pluck('id')->all());
    }

    public function test_会員の利用は利用日と開始時刻とidの順に並ぶ(): void
    {
        $late = $this->usage(null, '2026-11-02', '15:00');
        $early = $this->usage(null, '2026-11-02', '09:00');
        $prevDay = $this->usage(null, '2026-11-01', '18:00');
        $sameTime = $this->usage(null, '2026-11-02', '09:00');

        $this->assertSame(
            [$prevDay->id, $early->id, $sameTime->id, $late->id],
            $this->client->usages()->pluck('id')->all()
        );
    }

    public function test_時刻の入力用はhh_mm_表示用は時の先頭の0を付けない(): void
    {
        // DB（MySQL）からは「HH:MM:SS」で返る
        $usage = new ClientUsage(['start_time' => '08:00:00', 'end_time' => '19:05:00']);

        $this->assertSame('08:00', $usage->start_time_input);
        $this->assertSame('19:05', $usage->end_time_input);
        $this->assertSame('8:00〜19:05', $usage->time_range_label);
    }

    public function test_時刻がhh_mmでも同じ形になる(): void
    {
        $usage = new ClientUsage(['start_time' => '09:30', 'end_time' => '10:00']);

        $this->assertSame('09:30', $usage->start_time_input);
        $this->assertSame('9:30〜10:00', $usage->time_range_label);
    }
}
