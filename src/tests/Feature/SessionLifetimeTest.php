<?php

namespace Tests\Feature;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * セッションの長さ（2026-10-21 変更。設計書 requirements.md 11-2-3・architecture.md 2-7）。
 *
 * - 会員様側：最後に使ってから 30 日（43200 分）。使うたびに Cookie の期限が延びる
 * - トレーナー側：最後に使ってから 8 時間（480 分。2026-10-21 に「ログインしてから 12 時間」でも切る仕組みを足したが、取りやめた）
 * - 期限切れのセッションの掃除は、長いほう（会員様側）のリクエストでだけ行う
 * - 会員様側：パスワードが変わったら、ほかの端末をログアウトさせる（auth.session。変えた端末・再設定した同じブラウザはログインしたまま）
 *
 * 開発の .env は確かめのために両方 1 分になっているので、既定の値は .env を外して config/session_roles.php を読み直して確かめる
 */
class SessionLifetimeTest extends TestCase
{
    use CreatesClientCountTables;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');
        // この中の値で確かめる（開発の .env の 1 分に左右されないように）
        config([
            'session_roles.trainer.lifetime' => 480,
            'session_roles.client.lifetime' => 43200,
        ]);

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token');
            $table->unsignedBigInteger('client_id');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });

        $this->clientId = DB::table('clients')->insertGetId([
            'internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎',
            'email' => 'yamada@example.com', 'password' => Hash::make('Client-secret1'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 会員様のログイン（本当にログインして、セッションにログインの印を入れる。actingAs はセッションに書かないため） */
    private function loginClient(): void
    {
        $this->post('/client-portal/login', ['email' => 'yamada@example.com', 'password' => 'Client-secret1'])->assertRedirect();
        $this->assertAuthenticated('client');
    }

    /**
     * 端末を切り替える（テストのセッションの中身をその端末のものに入れ替え、ログインの状態を覚えた guard を作り直す。
     * ログインの状態はセッションから読み直される）
     */
    private function switchDevice(array $sessionData): void
    {
        session()->flush();
        session()->put($sessionData);
        $this->app['auth']->forgetGuards();
    }

    // ---- 既定の値 ----

    public function test_既定の値は会員様30日とトレーナー8時間(): void
    {
        $keys = ['CLIENT_SESSION_LIFETIME', 'TRAINER_SESSION_LIFETIME'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = Env::get($key);
            Env::getRepository()->clear($key);
        }
        try {
            $roles = require config_path('session_roles.php');
        } finally {
            foreach ($saved as $key => $value) {
                if ($value !== null) {
                    Env::getRepository()->set($key, (string) $value);
                }
            }
        }

        $this->assertSame(43200, $roles['client']['lifetime']);
        $this->assertSame(480, $roles['trainer']['lifetime']);
        // ログインしてからの長さ（absolute_lifetime）は取りやめた（2026-10-21）
        $this->assertArrayNotHasKey('absolute_lifetime', $roles['trainer']);
    }

    // ---- 会員様側：最後に使ってから 30 日 ----

    public function test_会員様のCookieは30日で使うたびに延びる(): void
    {
        $first = $this->get('/client-portal/login')->assertOk();
        $cookie = collect($first->headers->getCookies())->first(fn ($c) => $c->getName() === config('session_roles.client.cookie'));
        $this->assertNotNull($cookie);
        // Max-Age は Symfony が実際の時計から計算するので、期限の日時で確かめる（期限＝その時刻＋30 日）
        $this->assertSame(Carbon::parse('2026-11-20 09:00:00')->getTimestamp(), $cookie->getExpiresTime());

        // 10 日たってから使うと、そこから 30 日に延びる
        Carbon::setTestNow('2026-10-31 09:00:00');
        $second = $this->get('/client-portal/login')->assertOk();
        $cookie = collect($second->headers->getCookies())->first(fn ($c) => $c->getName() === config('session_roles.client.cookie'));
        $this->assertSame(Carbon::parse('2026-11-30 09:00:00')->getTimestamp(), $cookie->getExpiresTime());
        $this->assertSame(43200, config('session.lifetime'));
    }

    public function test_トレーナーのCookieは最後に使ってから8時間(): void
    {
        $response = $this->get('/login')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session_roles.trainer.cookie'));
        $this->assertSame(Carbon::parse('2026-10-21 17:00:00')->getTimestamp(), $cookie->getExpiresTime());
        $this->assertSame(480, config('session.lifetime'));

        // 使うたびに延びる（最後に使ってから 8 時間）
        Carbon::setTestNow('2026-10-21 16:00:00');
        $response = $this->get('/login')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session_roles.trainer.cookie'));
        $this->assertSame(Carbon::parse('2026-10-22 00:00:00')->getTimestamp(), $cookie->getExpiresTime());
    }

    public function test_期限切れのセッションの掃除は会員様側のリクエストでだけ行う(): void
    {
        // 会員様 30 日（43200 分）とトレーナー 8 時間（480 分）。短いトレーナー側で掃除すると、8 時間使っていない会員様のセッションが消える
        $this->assertSame(43200, config('session_roles.client.lifetime'));
        $this->assertSame(480, config('session_roles.trainer.lifetime'));
        $default = config('session.lottery');
        $this->get('/login');
        $this->assertSame([0, 100], config('session.lottery'), 'トレーナー側（短いほう）は掃除しない');
        $this->get('/client-portal/login');
        $this->assertSame($default, config('session.lottery'), '会員様側（長いほう）は今までどおり');
        $this->assertNotSame([0, 100], $default);

        // 決めているのは値の大小なので、トレーナー側のほうが長ければ、トレーナー側で掃除し会員様側では掃除しない
        config(['session_roles.trainer.lifetime' => 50000]);
        $this->get('/login');
        $this->assertSame($default, config('session.lottery'));
        $this->get('/client-portal/login');
        $this->assertSame([0, 100], config('session.lottery'));
    }

    // ---- 会員様側：ほかの端末をログアウトさせる ----

    public function test_パスワードを変えるとほかの端末はログアウトし変えた端末はログインしたまま(): void
    {
        Mail::fake();
        $this->loginClient();
        // 開くと、セッションにパスワードの印が入る
        $this->get(route('client-portal.settings.password.edit'))->assertOk();
        $this->assertNotNull(session('password_hash_client'));
        $otherDevice = session()->all();

        // この端末でパスワードを変える（今のパスワードを聞く）
        $this->put(route('client-portal.settings.password.update'), [
            'current_password' => 'Client-secret1', 'new_password' => 'Client-new-pass2', 'new_password_confirmation' => 'Client-new-pass2',
        ])->assertRedirect(route('client-portal.settings.password.edit'));
        $this->get(route('client-portal.settings.password.edit'))->assertOk();
        $this->assertAuthenticated('client');
        $thisDevice = session()->all();

        // ほかの端末（古いパスワードの印を持つセッション）で開くと、ログアウトしてログイン画面へ
        $this->switchDevice($otherDevice);
        $this->get(route('client-portal.settings.password.edit'))->assertRedirect('/client-portal/login');
        $this->assertGuest('client');

        // 変えた端末はログインしたまま
        $this->switchDevice($thisDevice);
        $this->get(route('client-portal.settings.password.edit'))->assertOk();
        $this->assertAuthenticated('client');
    }

    public function test_今のパスワードが違えば変えられない(): void
    {
        $this->actingAs(Client::query()->findOrFail($this->clientId), 'client');
        $this->put(route('client-portal.settings.password.update'), [
            'current_password' => 'wrong', 'new_password' => 'Client-new-pass2', 'new_password_confirmation' => 'Client-new-pass2',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('Client-secret1', DB::table('clients')->where('id', $this->clientId)->value('password')));
    }

    public function test_パスワードを再設定するとほかの端末はログアウトし同じブラウザでログインしていればそのまま(): void
    {
        $this->loginClient();
        $this->get(route('client-portal.settings.password.edit'))->assertOk();
        $otherDevice = session()->all();

        // 同じブラウザ（ログイン中）で再設定のリンクを開いて再設定する
        DB::table('client_password_reset_tokens')->insert([
            'token' => 'resettokenresettokenresettoken12', 'client_id' => $this->clientId, 'expires_at' => '2026-10-22 09:00:00', 'is_used' => false,
        ]);
        $this->post(route('client-portal.password-reset.reset.save', 'resettokenresettokenresettoken12'), [
            'new_password' => 'Client-new-pass3', 'new_password_confirmation' => 'Client-new-pass3',
        ])->assertRedirect(route('client-portal.login'));

        // 同じブラウザはログインしたまま
        $this->get(route('client-portal.settings.password.edit'))->assertOk();
        $this->assertAuthenticated('client');

        // ほかの端末はログアウト
        $this->switchDevice($otherDevice);
        $this->get(route('client-portal.settings.password.edit'))->assertRedirect('/client-portal/login');
        $this->assertGuest('client');
    }
}
