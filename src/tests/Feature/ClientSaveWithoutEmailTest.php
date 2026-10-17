<?php

namespace Tests\Feature;

use App\Http\Requests\ClientInitialSetupRequest;
use App\Http\Requests\ClientProfileRequest;
use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\Trainer;
use Illuminate\Support\Facades\Validator;
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

    // ---- 緊急連絡先（ご家族等）と続柄（2026-10-17 変更） ----

    public function test_編集で緊急連絡先と続柄を保存でき空にすると空で保存できる(): void
    {
        $client = Client::query()->create(['internal_id' => '1', 'last_name' => '山田', 'initial_consultation_date' => '2026-10-01']);
        $input = ['internal_id' => '1', 'last_name' => '山田', 'initial_consultation_date' => '2026-10-01'];

        $this->put(route('clients.update', $client), $input + [
            'phone2' => '090-1111-2222', 'emergency_contact_relationship' => '母',
        ])->assertRedirect(route('clients.show', $client))->assertSessionHasNoErrors();
        $this->assertSame('090-1111-2222', $client->fresh()->phone2);
        $this->assertSame('母', $client->fresh()->emergency_contact_relationship);

        // 前後の空白はほかの欄と同じく取る（TrimStrings）。空の欄は null（ConvertEmptyStringsToNull）
        $this->put(route('clients.update', $client), $input + [
            'phone2' => '', 'emergency_contact_relationship' => '  ',
        ])->assertSessionHasNoErrors();
        $this->assertNull($client->fresh()->phone2);
        $this->assertNull($client->fresh()->emergency_contact_relationship);
    }

    public function test_編集で続柄が21文字以上だとエラーになり保存しない(): void
    {
        $client = Client::query()->create(['internal_id' => '1', 'last_name' => '山田', 'initial_consultation_date' => '2026-10-01']);

        $this->put(route('clients.update', $client), [
            'internal_id' => '1', 'last_name' => '山田', 'initial_consultation_date' => '2026-10-01',
            'emergency_contact_relationship' => str_repeat('あ', 21),
        ])->assertSessionHasErrors(['emergency_contact_relationship' => '続柄は20文字以内で入力してください。']);
        $this->assertNull($client->fresh()->emergency_contact_relationship);
    }

    public function test_三つの画面の検証で続柄は20文字までよく21文字でエラー_緊急連絡先が空でも続柄だけでよい(): void
    {
        // トレーナー側の登録・編集（ClientRequest）、会員側の初回設定（ClientInitialSetupRequest）・登録情報の変更（ClientProfileRequest）。
        // 登録の保存は内部IDの採番で MySQL だけの SQL を使うため、テストの DB〔SQLite〕では流せない。ルールで確かめる
        foreach ([new ClientRequest, new ClientInitialSetupRequest, new ClientProfileRequest] as $request) {
            $rules = array_intersect_key($request->rules(), array_flip(['phone2', 'emergency_contact_relationship']));

            $this->assertTrue(Validator::make(['phone2' => null, 'emergency_contact_relationship' => str_repeat('あ', 20)], $rules)->passes());
            $this->assertTrue(Validator::make(['phone2' => null, 'emergency_contact_relationship' => null], $rules)->passes());

            $v = Validator::make(['emergency_contact_relationship' => str_repeat('あ', 21)], $rules);
            $this->assertTrue($v->fails());
            $this->assertSame('続柄は20文字以内で入力してください。', $v->errors()->first('emergency_contact_relationship'));

            // 緊急連絡先の文言は「緊急連絡先（ご家族等）」
            $v = Validator::make(['phone2' => 'abc'], $rules);
            $this->assertSame('緊急連絡先（ご家族等）の形式が正しくありません。', $v->errors()->first('phone2'));
        }
    }
}
