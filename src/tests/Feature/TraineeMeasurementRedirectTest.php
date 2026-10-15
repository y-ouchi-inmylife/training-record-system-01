<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineeMeasurement;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 計測値の登録・更新・削除のあとの戻り先（2026-10 変更。api-design.md「計測値エンドポイントのリダイレクト先」）。
 *
 * 計測値の登録・編集・削除はトレーニー詳細（S-0309）の計測値の欄だけで行うため、成功後は常に S-0309 に戻す。
 * 当初は hidden return_to（client / trainee）で送信元を判別し、return_to=client のときは会員詳細（S-0305）に
 * 戻していた（S-0305 のトレーニーカードの「計測を追加」から登録したとき）。今は送られても使わない。
 *
 * ミドルウェアは外さず、実際の HTTP で確かめる。
 */
class TraineeMeasurementRedirectTest extends TestCase
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
            $table->unsignedBigInteger('updated_by')->nullable();
        });

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');

        $client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田']);
        $this->trainee = Trainee::query()->create(['client_id' => $client->id, 'name' => 'モモ']);
    }

    private function measurement(): TraineeMeasurement
    {
        return TraineeMeasurement::query()->create([
            'trainee_id' => $this->trainee->id, 'measured_date' => '2026-10-01', 'measured_time' => '08:00', 'weight_kg' => 4.5,
        ]);
    }

    public function test_登録のあとはトレーニー詳細に戻る(): void
    {
        $response = $this->post(route('trainee-measurements.store', $this->trainee), [
            'measured_date' => '2026-10-02', 'measured_time' => '08:00', 'weight_kg' => '4.60',
        ]);

        $response->assertRedirect(route('trainees.show', $this->trainee));
        $response->assertSessionHas('success', '計測値を登録しました。');
        $this->assertSame(1, TraineeMeasurement::query()->count());
    }

    public function test_return_toにclientを送っても登録のあとはトレーニー詳細に戻る(): void
    {
        $response = $this->post(route('trainee-measurements.store', $this->trainee), [
            'measured_date' => '2026-10-02', 'measured_time' => '08:00', 'weight_kg' => '4.60', 'return_to' => 'client',
        ]);

        $response->assertRedirect(route('trainees.show', $this->trainee));
    }

    public function test_更新のあともreturn_toにかかわらずトレーニー詳細に戻る(): void
    {
        $m = $this->measurement();

        $response = $this->put(route('trainee-measurements.update', $m), [
            'measured_date' => '2026-10-01', 'measured_time' => '08:00', 'weight_kg' => '4.70', 'return_to' => 'client',
        ]);

        $response->assertRedirect(route('trainees.show', $this->trainee));
        $response->assertSessionHas('success', '計測値を更新しました。');
        $this->assertSame('4.70', (string) $m->fresh()->weight_kg);
    }

    public function test_削除のあともreturn_toにかかわらずトレーニー詳細に戻る(): void
    {
        $m = $this->measurement();

        $response = $this->delete(route('trainee-measurements.destroy', $m), ['return_to' => 'client']);

        $response->assertRedirect(route('trainees.show', $this->trainee));
        $response->assertSessionHas('success', '計測値を削除しました。');
        $this->assertSame(0, TraineeMeasurement::query()->count());
    }
}
