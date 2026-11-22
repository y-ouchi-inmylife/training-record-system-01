<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUsage;
use App\Services\GoogleCalendarLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 「Googleカレンダーに追加」の URL を作る処理（2026-11 追加。requirements.md「Google カレンダーに追加」）。
 *
 * - 予定の名前は「トレーニー（会員名）」（トレーニーなしは会員名だけ、名前未登録は「名前未登録（会員名）」）
 * - 日時は Z なしの YYYYMMDDTHHMMSS、タイムゾーンは ctz=Asia/Tokyo
 * - 説明欄は会員詳細の絶対 URL だけ（メモは入らない）
 * - 利用日が今日以降の利用だけ。過去は null
 */
class GoogleCalendarLinkServiceTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 10:00:00');

        $this->createClientsTable();
        $this->createTraineesTableForUsage();
        $this->createClientCountTables();

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '大内', 'first_name' => '佳和']);
        DB::table('trainees')->insert(['id' => 10, 'client_id' => $this->client->id, 'name' => 'アイ']);
        DB::table('trainees')->insert(['id' => 11, 'client_id' => $this->client->id, 'name' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usage(array $overrides = []): ClientUsage
    {
        return ClientUsage::query()->create(array_merge([
            'client_id' => $this->client->id, 'trainee_id' => 10,
            'used_on' => '2026-11-26', 'start_time' => '08:00:00', 'end_time' => '19:05:00',
        ], $overrides));
    }

    private function url(array $overrides = []): string
    {
        $url = app(GoogleCalendarLinkService::class)->urlFor($this->usage($overrides));
        $this->assertNotNull($url);

        return $url;
    }

    /** @return array<string, string> URL の項目（デコードした値） */
    private function query(array $overrides = []): array
    {
        parse_str((string) parse_url($this->url($overrides), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_URL全体_項目はこの5つだけ(): void
    {
        $this->assertStringStartsWith('https://calendar.google.com/calendar/render?', $this->url());
        $this->assertSame([
            'action' => 'TEMPLATE',
            'text' => 'アイ（大内 佳和）',
            'dates' => '20261126T080000/20261126T190500',
            'ctz' => 'Asia/Tokyo',
            'details' => route('clients.show', $this->client),
        ], $this->query());
    }

    public function test_名前_トレーニーなしは会員名だけ_名前未登録と名のない会員(): void
    {
        $this->assertSame('大内 佳和', $this->query(['trainee_id' => null])['text']);
        $this->assertSame('名前未登録（大内 佳和）', $this->query(['trainee_id' => 11])['text']);

        // 名が空の会員でも、末尾に空白を残さない
        $this->client->update(['first_name' => null]);
        $this->assertSame('アイ（大内）', $this->query()['text']);
        $this->assertSame('大内', $this->query(['trainee_id' => null])['text']);
    }

    public function test_日時はZなしで_秒のない時刻でも同じ形(): void
    {
        $dates = $this->query(['start_time' => '08:00', 'end_time' => '09:30'])['dates'];
        $this->assertSame('20261126T080000/20261126T093000', $dates);
        $this->assertStringNotContainsString('Z', $dates);
    }

    public function test_説明欄は会員詳細の絶対URLだけで_メモは入らない(): void
    {
        $url = $this->url(['note' => 'ひみつのメモ']);

        $this->assertStringNotContainsString('ひみつのメモ', rawurldecode($url));
        $details = $this->query(['note' => 'ひみつのメモ'])['details'];
        $this->assertMatchesRegularExpression('#\Ahttps?://[^/]+/clients/'.$this->client->id.'\z#', $details);
    }

    public function test_日本語_かっこ_空白_記号をエンコードする(): void
    {
        DB::table('trainees')->where('id', 10)->update(['name' => 'A&B+C #1']);

        $url = $this->url();

        $this->assertMatchesRegularExpression('/\A[\x21-\x7e]+\z/', $url, 'ASCII の印字できる文字だけ（空白もない）');
        $this->assertStringContainsString('text=A%26B%2BC%20%231%EF%BC%88%E5%A4%A7%E5%86%85%20%E4%BD%B3%E5%92%8C%EF%BC%89&', $url);
        $this->assertSame('A&B+C #1（大内 佳和）', $this->query()['text']);
        $this->assertStringNotContainsString('+', $url);
    }

    public function test_今日以降だけで_過去はnull(): void
    {
        $service = app(GoogleCalendarLinkService::class);

        $this->assertNull($service->urlFor($this->usage(['used_on' => '2026-11-20'])));
        // 今日は、もう終わった時刻でも出す（時刻は判定に使わない）
        $this->assertNotNull($service->urlFor($this->usage(['used_on' => '2026-11-21', 'start_time' => '08:00:00', 'end_time' => '09:00:00'])));
        $this->assertNotNull($service->urlFor($this->usage(['used_on' => '2026-11-22'])));

        // 日が変わったら出さない
        Carbon::setTestNow('2026-11-22 00:00:00');
        $this->assertNull($service->urlFor($this->usage(['used_on' => '2026-11-21'])));
    }
}
