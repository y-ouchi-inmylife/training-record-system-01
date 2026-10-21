<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Models\Client;
use App\Models\Trainer;
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
 * - トレーナー側：最後に使ってから 12 時間（720 分）、ログインしてからも 12 時間（EnforceAbsoluteSessionLifetime）
 * - 期限切れのセッションの掃除は、長いほう（会員様側）のリクエストでだけ行う
 * - 会員様側：パスワードが変わったら、ほかの端末をログアウトさせる（auth.session。変えた端末・再設定した同じブラウザはログインしたまま）
 *
 * 開発の .env は確かめのために両方 1 分になっているので、既定の値は .env を外して config/session_roles.php を読み直して確かめる
 */
class SessionLifetimeTest extends TestCase
{
    use CreatesClientCountTables;

    private const MSG_ABSOLUTE = 'ログインしてから時間がたったため、ログアウトしました。もう一度ログインしてください。';

    private int $trainerId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');
        // この中の値で確かめる（開発の .env の 1 分に左右されないように）
        config([
            'session_roles.trainer.lifetime' => 720,
            'session_roles.trainer.absolute_lifetime' => 720,
            'session_roles.client.lifetime' => 43200,
        ]);

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('login_id')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_locked')->default(false);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainer_id')->nullable();
            $table->string('login_id_input')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->boolean('success')->default(false);
            $table->timestamps();
        });
        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token');
            $table->unsignedBigInteger('client_id');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });

        $this->trainerId = DB::table('trainers')->insertGetId([
            'name' => '村山', 'login_id' => 'murayama', 'password' => Hash::make('Trainer-secret1'), 'role' => 'admin',
        ]);
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

    private function loginTrainer(): void
    {
        // 行き先はダッシュボードか、ログインが切れる前の画面（url.intended）
        $this->post('/login', ['login_id' => 'murayama', 'password' => 'Trainer-secret1'])->assertRedirect();
        $this->assertAuthenticated('web');
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

    public function test_既定の値は会員様30日とトレーナー12時間とログインしてから12時間(): void
    {
        $keys = ['CLIENT_SESSION_LIFETIME', 'TRAINER_SESSION_LIFETIME', 'TRAINER_SESSION_ABSOLUTE_LIFETIME'];
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
        $this->assertSame(720, $roles['trainer']['lifetime']);
        $this->assertSame(720, $roles['trainer']['absolute_lifetime']);
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

    public function test_トレーナーのCookieは最後に使ってから12時間(): void
    {
        $response = $this->get('/login')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session_roles.trainer.cookie'));
        $this->assertSame(Carbon::parse('2026-10-21 21:00:00')->getTimestamp(), $cookie->getExpiresTime());
        $this->assertSame(720, config('session.lifetime'));
    }

    public function test_期限切れのセッションの掃除は会員様側のリクエストでだけ行う(): void
    {
        $default = config('session.lottery');
        $this->get('/login');
        $this->assertSame([0, 100], config('session.lottery'), 'トレーナー側（短いほう）は掃除しない');
        $this->get('/client-portal/login');
        $this->assertSame($default, config('session.lottery'), '会員様側（長いほう）は今までどおり');
        $this->assertNotSame([0, 100], $default);
    }

    // ---- トレーナー側：ログインしてから 12 時間 ----

    public function test_ログインすると時刻が入り720分までは開ける(): void
    {
        $this->loginTrainer();
        $this->assertSame(Carbon::parse('2026-10-21 09:00:00')->getTimestamp(), session(EnforceAbsoluteSessionLifetime::SESSION_KEY));

        // 使い続けても（最後に使ってからは短い）、ちょうど 720 分までは開ける
        foreach (['2026-10-21 13:00:00', '2026-10-21 17:00:00', '2026-10-21 21:00:00'] as $now) {
            Carbon::setTestNow($now);
            $this->get('/profile')->assertOk();
        }
        $this->assertAuthenticated('web');
    }

    public function test_ログインしてから720分を過ぎるとログアウトしてログイン画面へ移り案内を出しログインのあと元の画面へ戻る(): void
    {
        $this->loginTrainer();
        foreach (['2026-10-21 13:00:00', '2026-10-21 17:00:00', '2026-10-21 21:00:00'] as $now) {
            Carbon::setTestNow($now);
            $this->get('/profile')->assertOk();
        }

        Carbon::setTestNow('2026-10-21 21:00:01');
        $this->get('/profile?tab=1')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => self::MSG_ABSOLUTE]);
        $this->assertGuest('web');
        $this->assertSame(url('/profile?tab=1'), session('url.intended'));

        // ログイン画面に案内が出る
        $this->get('/login')->assertOk()->assertSee(self::MSG_ABSOLUTE);
        // ログインすると元の画面へ戻り、時刻が新しくなる
        $this->post('/login', ['login_id' => 'murayama', 'password' => 'Trainer-secret1'])->assertRedirect(url('/profile?tab=1'));
        $this->assertSame(Carbon::parse('2026-10-21 21:00:01')->getTimestamp(), session(EnforceAbsoluteSessionLifetime::SESSION_KEY));
    }

    public function test_時刻がないセッションは時刻を入れて開ける(): void
    {
        // この仕組みを入れる前からログインしている人（セッションに時刻がない）
        $this->actingAs(Trainer::query()->findOrFail($this->trainerId), 'web');
        $this->assertNull(session(EnforceAbsoluteSessionLifetime::SESSION_KEY));

        $this->get('/profile')->assertOk();
        $this->assertSame(Carbon::parse('2026-10-21 09:00:00')->getTimestamp(), session(EnforceAbsoluteSessionLifetime::SESSION_KEY));

        // そこから 720 分を過ぎると切れる
        Carbon::setTestNow('2026-10-21 21:00:01');
        $this->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_過ぎていたときのフォームの送信は送る前の画面へ戻れるようにしJSONは401(): void
    {
        $this->loginTrainer();
        Carbon::setTestNow('2026-10-21 21:00:01');

        // フォームの送信：保存はしない。ログインのあとは送る前の画面へ戻る（入力は残さない）
        $this->put('/profile', ['name' => '村山 次郎'], ['Referer' => url('/profile')])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => self::MSG_ABSOLUTE]);
        $this->assertSame(url('/profile'), session('url.intended'));
        $this->assertSame('村山', DB::table('trainers')->where('id', $this->trainerId)->value('name'));
        $this->assertGuest('web');

        // JSON の呼び出し（トークンの取り直し・録音の送信）は 401
        $this->loginTrainer();
        Carbon::setTestNow('2026-10-22 09:00:02');
        $this->getJson(route('csrf-token'))->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertGuest('web');

        $this->loginTrainer();
        Carbon::setTestNow('2026-10-22 21:00:03');
        $this->postJson(route('audio-records.recording.store'), ['client_id' => $this->clientId])->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_ほかの端末でログインしても今の端末の時刻は変わらない(): void
    {
        // 端末 A で 9:00 にログイン
        $this->loginTrainer();
        $deviceA = session()->all();

        // 端末 B（別のブラウザ。別のセッション）で 15:00 にログイン
        $this->switchDevice([]);
        Carbon::setTestNow('2026-10-21 15:00:00');
        $this->loginTrainer();
        $deviceB = session()->all();
        $this->assertSame(Carbon::parse('2026-10-21 15:00:00')->getTimestamp(), $deviceB[EnforceAbsoluteSessionLifetime::SESSION_KEY]);
        // 時刻はセッションに入れるので、端末 A の時刻は 9:00 のまま（トレーナーの行などには入れない）
        $this->assertSame(Carbon::parse('2026-10-21 09:00:00')->getTimestamp(), $deviceA[EnforceAbsoluteSessionLifetime::SESSION_KEY]);

        // 21:00 を過ぎると、端末 A は切れ、端末 B は開ける
        Carbon::setTestNow('2026-10-21 21:30:00');
        $this->switchDevice($deviceA);
        $this->get('/profile')->assertRedirect(route('login'));

        $this->switchDevice($deviceB);
        $this->get('/profile')->assertOk();
    }

    public function test_会員様にはログインしてからの長さはない(): void
    {
        $this->actingAs(Client::query()->findOrFail($this->clientId), 'client');
        $this->get(route('client-portal.settings.password.edit'))->assertOk();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->get(route('client-portal.settings.password.edit'))->assertOk();
        $this->assertNull(session(EnforceAbsoluteSessionLifetime::SESSION_KEY));
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
