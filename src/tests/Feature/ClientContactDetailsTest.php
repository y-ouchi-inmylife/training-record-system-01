<?php

namespace Tests\Feature;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\ClientContract;
use App\Models\ClientUsage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員の住所・電話番号（2026-10-18 変更。要件定義書 6-15-3・6-15-5・6-15-9、設計書 S-1402・S-1403・S-1411）。
 *
 * 2026-10-18 のユーザーレビューで、マイページの登録の時点ではまだ預かるか決まっていない会員もいるので、住所・電話番号を任意にした。
 * 契約か利用がある会員は、トレーニングを実施している、または予定があるので入れてもらう
 * （2026-10-28 変更：その前は契約がある会員だけ。契約を消すと利用は「契約なし」で残るため、契約 0 件・利用ありの会員も対象にした）：
 * - 登録情報の変更（S-1411）：契約も利用もなければ任意、契約か利用があれば必須（必須の印も出す）
 * - マイページ（S-1402）：契約か利用があって住所か電話番号が空なら「ご連絡先の登録のお願い」を出す
 * - トレーナー側の会員の登録・編集（ClientRequest）は今のまま（住所・電話番号はどれも任意）
 * 初回設定（S-1403）は ClientInitialSetupStoreTest で確かめる。
 */
class ClientContactDetailsTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createClientCountTables();
        // ダッシュボードが読む残りのテーブル（列を絞る。中身は空。ClientContractNavRenderingTest と同じ）
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
        });
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();
    }

    private const FULL_CONTACT = [
        'postal_code' => '100-0014', 'address1' => '東京都', 'address2' => '千代田区', 'address3' => '永田町1-1', 'address4' => 'ビル101',
        'phone1' => '03-1234-5678',
    ];

    /** 会員を作る。$contract が true なら契約を 1 件、$usage が true なら「契約なし」の利用を 1 件付ける */
    private function client(array $attributes = [], bool $contract = false, bool $usage = false): Client
    {
        $client = Client::query()->create(array_merge(['internal_id' => 'C-1', 'last_name' => '山田', 'first_name' => '太郎'], $attributes));
        if ($contract) {
            ClientContract::query()->create(['client_id' => $client->id, 'contracted_on' => '2026-09-01', 'count' => 4]);
        }
        if ($usage) {
            ClientUsage::query()->create(['client_id' => $client->id, 'client_contract_id' => null, 'used_on' => '2026-10-01']);
        }

        return $client;
    }

    private function updateProfile(Client $client, array $input)
    {
        return $this->actingAs($client, 'client')
            ->from(route('client-portal.profile.edit'))
            ->put(route('client-portal.profile.update'), array_merge([
                'postal_code' => '', 'address1' => '', 'address2' => '', 'address3' => '', 'address4' => '',
                'phone1' => '', 'phone2' => '', 'emergency_contact_relationship' => '',
            ], $input));
    }

    private function dashboard(Client $client): string
    {
        return $this->actingAs($client, 'client')->get(route('client-portal.dashboard'))->assertOk()->getContent();
    }

    // ---- 登録情報の変更（S-1411） ----

    public function test_契約も利用もなければ住所と電話番号を空で変更できる(): void
    {
        $client = $this->client(self::FULL_CONTACT);

        $this->updateProfile($client, [])
            ->assertRedirect(route('client-portal.profile.edit'))
            ->assertSessionHasNoErrors();

        $fresh = $client->fresh();
        foreach (['postal_code', 'address1', 'address2', 'address3', 'address4', 'phone1'] as $field) {
            $this->assertNull($fresh->{$field}, $field);
        }
    }

    public function test_契約も利用もなくても住所を一部だけ入れるとそろえて入れるエラー(): void
    {
        $client = $this->client();

        $this->updateProfile($client, ['address1' => '東京都'])
            ->assertSessionHasErrors([
                'address2' => '住所を入れるときは、市区町村も入力してください。',
                'address3' => '住所を入れるときは、町名・番地も入力してください。',
            ])
            ->assertSessionDoesntHaveErrors(['address1', 'phone1']);
    }

    public function test_契約があれば住所と電話番号が必須(): void
    {
        $client = $this->client(self::FULL_CONTACT, contract: true);

        $this->updateProfile($client, [])
            ->assertSessionHasErrors([
                'address1' => '都道府県を選択してください。',
                'address2' => '市区町村を入力してください。',
                'address3' => '町名・番地を入力してください。',
                'phone1' => '電話番号を入力してください。',
            ])
            ->assertSessionDoesntHaveErrors(['postal_code', 'address4']);
        $this->assertSame('東京都', $client->fresh()->address1);
    }

    public function test_契約がなく利用だけでも住所と電話番号が必須(): void
    {
        // 2026-10-28 追加：契約を消して利用だけが「契約なし」で残った会員（すでに犬を預かっている）
        $client = $this->client(self::FULL_CONTACT, usage: true);

        $this->updateProfile($client, [])
            ->assertSessionHasErrors([
                'address1' => '都道府県を選択してください。',
                'address2' => '市区町村を入力してください。',
                'address3' => '町名・番地を入力してください。',
                'phone1' => '電話番号を入力してください。',
            ])
            ->assertSessionDoesntHaveErrors(['postal_code', 'address4']);
        $this->assertSame('東京都', $client->fresh()->address1);
    }

    public function test_契約があっても全部入れれば変更できる(): void
    {
        $client = $this->client([], contract: true);

        $this->updateProfile($client, array_merge(self::FULL_CONTACT, ['postal_code' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame('03-1234-5678', $client->fresh()->phone1);
        $this->assertNull($client->fresh()->postal_code);
    }

    public function test_登録情報の変更の必須の印は契約か利用がある会員にだけ出す(): void
    {
        $labels = function (Client $client): array {
            $html = $this->actingAs($client, 'client')->get(route('client-portal.profile.edit'))->assertOk()->getContent();
            preg_match_all('/<label for="(address1|address2|address3|phone1)" class="form-label">([^<]*)(<span class="text-danger">\*<\/span>)?<\/label>/u', $html, $m, PREG_SET_ORDER);

            return array_combine(array_column($m, 1), array_map(fn ($x) => isset($x[3]), $m));
        };
        $requiredAttr = function (Client $client): int {
            $html = $this->actingAs($client, 'client')->get(route('client-portal.profile.edit'))->getContent();

            return preg_match_all('/name="(address1|address2|address3|phone1)" required/u', $html);
        };

        $without = $this->client();
        $this->assertSame(['address1' => false, 'address2' => false, 'address3' => false, 'phone1' => false], $labels($without));
        $this->assertSame(0, $requiredAttr($without));

        $with = $this->client(['internal_id' => 'C-2', 'email' => 'b@example.com'], contract: true);
        $this->assertSame(['address1' => true, 'address2' => true, 'address3' => true, 'phone1' => true], $labels($with));
        $this->assertSame(4, $requiredAttr($with));

        // 契約がなく利用だけの会員にも出す（2026-10-28 追加）
        $usageOnly = $this->client(['internal_id' => 'C-3', 'email' => 'c@example.com'], usage: true);
        $this->assertSame(['address1' => true, 'address2' => true, 'address3' => true, 'phone1' => true], $labels($usageOnly));
        $this->assertSame(4, $requiredAttr($usageOnly));
    }

    // ---- マイページのご連絡先の登録のお願い（S-1402） ----

    public static function noticeCases(): array
    {
        $full = self::FULL_CONTACT;

        return [
            '契約あり・住所が空' => [array_merge($full, ['address1' => null, 'address2' => null, 'address3' => null]), true, 'address', '住所のご登録をお願いします。'],
            '契約あり・町名番地だけ空' => [array_merge($full, ['address3' => null]), true, 'address', '住所のご登録をお願いします。'],
            '契約あり・電話番号が空' => [array_merge($full, ['phone1' => null]), true, 'phone', '電話番号のご登録をお願いします。'],
            '契約あり・両方空' => [[], true, 'address phone', '住所・電話番号のご登録をお願いします。'],
            // 2026-10-28 追加：契約がなく利用だけ（「契約なし」の利用）の会員
            '利用だけ・電話番号が空' => [array_merge($full, ['phone1' => null]), false, 'phone', '電話番号のご登録をお願いします。', true],
            '利用だけ・両方空' => [[], false, 'address phone', '住所・電話番号のご登録をお願いします。', true],
        ];
    }

    #[DataProvider('noticeCases')]
    public function test_契約か利用があって住所か電話番号が空ならマイページにお願いを出す(array $attributes, bool $contract, string $missing, string $text, bool $usage = false): void
    {
        $html = $this->dashboard($this->client($attributes, $contract, $usage));

        $this->assertMatchesRegularExpression(
            '/<div class="c-survey-notice c-survey-notice--contact c-contact-notice" data-client-contact-notice="'.$missing.'">\s*'
            .'<p class="c-survey-notice-text mb-0">\s*<svg[^>]*aria-hidden="true"[^>]*>.*?<\/svg>\s*<span>'.preg_quote($text, '/').'<\/span>\s*<\/p>\s*'
            .'<a href="'.preg_quote(route('client-portal.profile.edit'), '/').'" class="btn btn-primary btn-sm text-nowrap">登録する<\/a>\s*<\/div>/su',
            $html
        );
        // アイコンは注意の印（三角に「！」。2026-10-18 変更：その前は家）。読み上げない
        $this->assertMatchesRegularExpression('/<svg class="c-survey-notice-icon"[^>]*aria-hidden="true"[^>]*data-contact-notice-icon="exclamation-triangle">/u', $html);
        // あいさつのすぐ下（愛犬のカード・最新の記録より上）。閉じるボタンはない
        $this->assertLessThan(strpos($html, 'data-client-contact-notice'), strpos($html, 'こんにちは'));
        $this->assertLessThan(strpos($html, 'id="c-latest-heading"') ?: strpos($html, 'aria-label="空状態"'), strpos($html, 'data-client-contact-notice'));
        $start = strpos($html, 'data-client-contact-notice');
        $this->assertStringNotContainsString('btn-close', substr($html, $start, strpos($html, '</div>', $start) - $start));
    }

    public function test_契約があって住所と電話番号が入っていればお願いを出さない(): void
    {
        $html = $this->dashboard($this->client(array_merge(self::FULL_CONTACT, ['postal_code' => null, 'address4' => null]), contract: true));

        $this->assertStringNotContainsString('data-client-contact-notice', $html);
    }

    public function test_契約も利用もなければ住所と電話番号が空でもお願いを出さない(): void
    {
        $html = $this->dashboard($this->client());

        $this->assertStringNotContainsString('data-client-contact-notice', $html);
        $this->assertStringNotContainsString('ご登録をお願いします', $html);
    }

    public function test_有効期限切れの契約だけでもお願いを出す(): void
    {
        $client = $this->client();
        ClientContract::query()->create(['client_id' => $client->id, 'contracted_on' => '2025-01-01', 'count' => 1, 'expires_on' => '2025-02-28']);

        $this->assertStringContainsString('data-client-contact-notice="address phone"', $this->dashboard($client));
    }

    public function test_ご利用回数と登録情報の画面にはお願いを出さない(): void
    {
        $client = $this->client([], contract: true);

        foreach ([route('client-portal.contracts'), route('client-portal.profile.edit')] as $url) {
            $html = $this->actingAs($client, 'client')->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-client-contact-notice', $html, $url);
        }
    }

    // ---- トレーナー側の会員の登録・編集は今のまま ----

    public function test_トレーナー側の会員の登録編集は住所と電話番号がどれも任意のまま(): void
    {
        $rules = (new ClientRequest)->rules();

        foreach (['postal_code', 'address1', 'address2', 'address3', 'address4', 'phone1', 'phone2'] as $field) {
            $fieldRules = is_array($rules[$field]) ? $rules[$field] : explode('|', $rules[$field]);
            $this->assertContains('nullable', $fieldRules, $field);
            $this->assertEmpty(array_filter($fieldRules, fn ($r) => is_string($r) && str_starts_with($r, 'required')), $field);
        }
        $this->assertTrue(Validator::make(['address1' => '東京都'], array_intersect_key($rules, array_flip(['address1', 'address2', 'address3', 'phone1'])))->passes());
    }
}
