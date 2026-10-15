<?php

namespace Tests\Feature;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\Trainer;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員の登録・編集の保存（2026-10 追加）。
 *
 * 会員の登録・編集の画面にはメールアドレスの行を置かず（2026-10 変更）、保存の処理でもメールアドレスを
 * 受け取らない（ClientRequest のルールにないため validated() に含まれない）。メールアドレスは会員自身が
 * メールアドレス登録用 URL から登録する。ここでは、登録・編集の保存が今までどおりでき、メールアドレスが
 * 送られてきても使われないことを確かめる。
 */
class ClientSaveWithoutEmailTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');
    }

    public function test_登録と編集の検証のルールにメールアドレスはない(): void
    {
        // 登録・編集とも ClientRequest で受け取る。ルールにないため validated() に含まれず、保存されない。
        // （登録の保存は内部IDの採番で MySQL だけの SQL を使うため、テストの DB〔SQLite〕では流せない。
        // 受け取らないことはルールで確かめる）
        $this->assertArrayNotHasKey('email', (new ClientRequest)->rules());
    }

    public function test_編集はメールアドレスを送っても登録済みの値を変えずに保存できる(): void
    {
        $client = Client::query()->create(['internal_id' => '1', 'last_name' => '山田', 'initial_consultation_date' => '2026-10-01']);
        $client->forceFill(['email' => 'registered@example.com'])->save();

        $response = $this->put(route('clients.update', $client), [
            'internal_id' => '1', 'last_name' => '山田', 'first_name' => '花子',
            'initial_consultation_date' => '2026-10-01', 'email' => 'other@example.com',
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $response->assertSessionHas('success', '会員情報を更新しました。');
        $fresh = $client->fresh();
        $this->assertSame('花子', $fresh->first_name);
        $this->assertSame('registered@example.com', $fresh->email);
    }
}
