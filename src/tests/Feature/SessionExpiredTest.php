<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * ページの有効期限切れ（419。CSRF トークンの不一致）で、エラーページを出さずに元の画面へ戻す（2026-10-21 追加。
 * App\Support\SessionExpiredResponder。設計書 screen-design.md「419（ページの有効期限切れ）のときの戻し方」）。
 *
 * - ログアウト：ログアウトしてログイン画面へ
 * - ログイン：同じログイン画面へ。メールアドレス・ログインIDは残り、パスワードは残らない。戻った画面からそのままログインできる
 * - そのほかのフォーム：元の画面へ戻り、案内と入力が残る。ログインが切れていればログイン画面へ（ログインのあと元の画面へ戻る）
 * - JSON の呼び出し：今のまま 419 の JSON
 * - 戻り先がない・外のサイト：送った URL が GET で開ければそこ、なければその側のログイン画面へ
 *
 * テストでは Laravel が CSRF トークンの確認を飛ばすため、飛ばさないものに差し替えて本物の 419 を起こす（ClientIntakeSurveySessionExpiredTest と同じ）。
 * 古いトークンは 'stale' を送る。新しいトークンは session()->token()（テストのセッションはリクエストをまたいで残る）
 */
class SessionExpiredTest extends TestCase
{
    use CreatesClientCountTables;

    private const MSG_LOGIN = '画面を開いてから時間がたったため、ログインできませんでした。もう一度ログインしてください。';

    private const MSG_FORM = '画面を開いてから時間がたったため、送信できませんでした。お手数ですが、もう一度送信してください。';

    private const MSG_LOGIN_REQUIRED = '画面を開いてから時間がたったため、送信できませんでした。お手数ですが、もう一度ログインしてください。';

    private int $clientId;

    private int $trainerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

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

        $this->trainerId = DB::table('trainers')->insertGetId([
            'name' => '佐藤', 'login_id' => 'sato', 'password' => Hash::make('trainer-secret'), 'role' => 'staff',
        ]);
        $this->clientId = DB::table('clients')->insertGetId([
            'internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎',
            'email' => 'yamada@example.com', 'password' => Hash::make('client-secret'),
        ]);
    }

    private function loginAsTrainer(): void
    {
        $this->actingAs(Trainer::query()->findOrFail($this->trainerId), 'web');
    }

    private function loginAsClient(): void
    {
        $this->actingAs(Client::query()->findOrFail($this->clientId), 'client');
    }

    // ---- ログアウト ----

    public function test_会員様のログアウトは419にならずログアウトしてログイン画面へ(): void
    {
        $this->loginAsClient();

        $this->post('/client-portal/logout', ['_token' => 'stale'])->assertRedirect(route('client-portal.login'));

        $this->assertGuest('client');
    }

    public function test_トレーナーのログアウトは419にならずログアウトしてログイン画面へ(): void
    {
        $this->loginAsTrainer();

        $this->post('/logout', ['_token' => 'stale'])->assertRedirect('/login');

        $this->assertGuest('web');
        // ふだんのログアウトと同じ処理（操作の記録も残る）
        $this->assertSame(1, DB::table('access_logs')->where('action', 'logout')->where('trainer_id', $this->trainerId)->count());
    }

    // ---- ログイン ----

    public function test_会員様のログインは同じログイン画面へ戻りメールアドレスだけ残してそのままログインできる(): void
    {
        $this->post('/client-portal/login', ['_token' => 'stale', 'email' => 'yamada@example.com', 'password' => 'client-secret'])
            ->assertRedirect(route('client-portal.login'))
            ->assertSessionHasErrors(['login' => self::MSG_LOGIN]);
        $this->assertSame('yamada@example.com', session()->getOldInput('email'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertGuest('client');

        // 戻った画面：案内がフォームの上に出て、メールアドレスが入り、パスワードは入らない。エラーページではない
        $html = $this->get(route('client-portal.login'))->assertOk()->getContent();
        $this->assertStringContainsString('<div class="alert alert-danger" role="alert">'.self::MSG_LOGIN.'</div>', $html);
        $this->assertMatchesRegularExpression('/id="email"\s+name="email"\s+value="yamada@example.com"/', $html);
        $this->assertStringNotContainsString('client-secret', $html);
        $this->assertStringNotContainsString('data-error-page', $html);

        // 戻った画面の新しいトークンで、そのままログインできる
        $this->post('/client-portal/login', ['_token' => session()->token(), 'email' => 'yamada@example.com', 'password' => 'client-secret'])
            ->assertRedirect(route('client-portal.dashboard'));
        $this->assertAuthenticated('client');
    }

    public function test_トレーナーのログインは同じログイン画面へ戻りログインIDだけ残してそのままログインできる(): void
    {
        $this->post('/login', ['_token' => 'stale', 'login_id' => 'sato', 'password' => 'trainer-secret'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => self::MSG_LOGIN]);
        $this->assertSame('sato', session()->getOldInput('login_id'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertGuest('web');

        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('<div class="alert alert-danger" role="alert">'.self::MSG_LOGIN.'</div>', $html);
        $this->assertMatchesRegularExpression('/name="login_id"\s+value="sato"/', $html);
        $this->assertStringNotContainsString('trainer-secret', $html);

        $this->post('/login', ['_token' => session()->token(), 'login_id' => 'sato', 'password' => 'trainer-secret'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticated('web');
    }

    // ---- 419 が起きる場面 1：タブを 2 つ開いていた ----

    public function test_タブを2つ開いてAでログインしたあとBでログインしても419にならない(): void
    {
        // タブ A・B の両方でログイン画面を開く（同じセッションなので、どちらの画面にも同じトークンが入る）
        $this->get(route('client-portal.login'))->assertOk();
        $token = session()->token();

        // A でログイン（ログインでトークンが新しくなる）
        $this->post('/client-portal/login', ['_token' => $token, 'email' => 'yamada@example.com', 'password' => 'client-secret'])
            ->assertRedirect(route('client-portal.dashboard'));
        $this->assertNotSame($token, session()->token());

        // B でログイン：古いトークンなので CSRF の不一致。エラーページではなくログイン画面へ戻り、もうログインしているのでトップへ移る
        $this->post('/client-portal/login', ['_token' => $token, 'email' => 'yamada@example.com', 'password' => 'client-secret'])
            ->assertRedirect(route('client-portal.login'));
        $this->get(route('client-portal.login'))->assertRedirect('/client-portal/dashboard');
        $this->assertAuthenticated('client');
    }

    public function test_タブを2つ開いてAでログアウトしたあとBで送るとログイン画面へ戻りログインのあと元の画面へ戻る(): void
    {
        $this->get(route('client-portal.login'))->assertOk();
        $this->post('/client-portal/login', ['_token' => session()->token(), 'email' => 'yamada@example.com', 'password' => 'client-secret']);
        $oldToken = session()->token();

        // A でログアウト（セッションが作り直され、トークンが新しくなる）
        $this->post('/client-portal/logout', ['_token' => $oldToken])->assertRedirect(route('client-portal.login'));
        $this->assertGuest('client');

        // B（登録情報の変更の画面を開いたまま）で送る：ログインが切れているので、ログイン画面へ
        $edit = route('client-portal.profile.edit');
        $this->put('/client-portal/profile', ['_token' => $oldToken, 'last_name' => '山本'], ['Referer' => $edit])
            ->assertRedirect(route('client-portal.login'))
            ->assertSessionHasErrors(['login' => self::MSG_LOGIN_REQUIRED]);
        $this->assertSame($edit, session('url.intended'));

        // ログインすると元の画面へ戻る
        $this->get(route('client-portal.login'))->assertOk()->assertSee(self::MSG_LOGIN_REQUIRED);
        $this->post('/client-portal/login', ['_token' => session()->token(), 'email' => 'yamada@example.com', 'password' => 'client-secret'])
            ->assertRedirect($edit);
        $this->assertSame('山田', DB::table('clients')->where('id', $this->clientId)->value('last_name'));
    }

    // ---- そのほかのフォーム ----

    public function test_トレーナーの保存は元の画面へ戻り案内と入力が残りパスワードは残らない(): void
    {
        $this->loginAsTrainer();

        $this->put('/profile', ['_token' => 'stale', 'name' => '佐藤 次郎', 'current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y'], ['Referer' => url('/profile')])
            ->assertRedirect(url('/profile'))
            ->assertSessionHas('error', self::MSG_FORM);
        $this->assertSame('佐藤 次郎', session()->getOldInput('name'));
        foreach (['current_password', 'password', 'password_confirmation', '_token'] as $key) {
            $this->assertNull(session()->getOldInput($key), $key);
        }
        // 保存はしていない・ログインしたまま
        $this->assertSame('佐藤', DB::table('trainers')->where('id', $this->trainerId)->value('name'));
        $this->assertAuthenticated('web');
    }

    public function test_会員様の画面の保存は元の画面へ戻り案内と入力が残る(): void
    {
        $this->loginAsClient();
        $edit = route('client-portal.profile.edit');

        $this->put('/client-portal/profile', ['_token' => 'stale', 'last_name' => '山本', 'phone1' => '090-0000-0000'], ['Referer' => $edit])
            ->assertRedirect($edit)
            ->assertSessionHas('error', self::MSG_FORM);
        $this->assertSame('山本', session()->getOldInput('last_name'));
        $this->assertSame('090-0000-0000', session()->getOldInput('phone1'));
        $this->assertSame('山田', DB::table('clients')->where('id', $this->clientId)->value('last_name'));
        $this->assertAuthenticated('client');
    }

    public function test_戻った画面でもう一度送れば419にならない(): void
    {
        $this->loginAsTrainer();
        $this->put('/profile', ['_token' => 'stale', 'name' => '佐藤 次郎'], ['Referer' => url('/profile')])->assertRedirect(url('/profile'));

        // 戻った画面のトークン（今のセッションのトークン）で送れば、CSRF の確認を通る（419 にならない）
        $response = $this->put('/profile', ['_token' => session()->token(), 'name' => '佐藤 次郎'], ['Referer' => url('/profile')]);
        $this->assertNotSame(419, $response->getStatusCode());
        $this->assertNotSame(self::MSG_FORM, session('error'));
    }

    // ---- JSON ----

    public function test_JSONの呼び出しは今のまま419のJSON(): void
    {
        $this->loginAsTrainer();

        $this->putJson('/profile', ['_token' => 'stale', 'name' => '佐藤 次郎'])
            ->assertStatus(419)
            ->assertJson(['message' => 'CSRF token mismatch.']);
        $this->postJson('/client-portal/logout', ['_token' => 'stale'])->assertStatus(419);
    }

    // ---- 戻り先がない・外のサイト ----

    public function test_戻り先がないときや外のサイトのときは送ったURLかその側のログイン画面へ(): void
    {
        $this->loginAsTrainer();
        // 外のサイト：そこへは戻さない。送った URL（/profile）は GET で開けるので、そこへ
        $this->put('/profile', ['_token' => 'stale', 'name' => '佐藤 次郎'], ['Referer' => 'https://evil.example.com/profile'])
            ->assertRedirect(url('/profile'))
            ->assertSessionHas('error', self::MSG_FORM);
        // GET で開けない URL から送り、送る前の画面もない：トレーナーのログイン画面へ（ログイン中なので、ふだんの仕組みでトップへ移る）
        $this->post('/trainees/1/measurements', ['_token' => 'stale'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => self::MSG_LOGIN_REQUIRED]);

        // 会員様側：会員様のログイン画面へ
        $this->post('/client-portal/trainees/1/photo', ['_token' => 'stale'], ['Referer' => 'https://evil.example.com/'])
            ->assertRedirect(route('client-portal.login'))
            ->assertSessionHasErrors(['login' => self::MSG_LOGIN_REQUIRED]);
        // 送る前の画面が GET で開けない（ない URL）
        $this->post('/client-portal/trainees/1/photo', ['_token' => 'stale'], ['Referer' => url('/client-portal/no-such-page')])
            ->assertRedirect(route('client-portal.login'));
    }

    // ---- 今の専用の扱い ----

    public function test_愛犬アンケートの送信の419は今までどおり専用のページ(): void
    {
        // 愛犬性格診断の送信（回答を入れたまま出し直す）は ClientPersonalityDiagnosisSessionExpiredTest で確かめる
        $this->post('/client-portal/intake-survey/abcdefghijklmnopqrstuvwxyz012345', ['_token' => 'stale', 'breed' => '柴'],
            ['Referer' => url('/client-portal/intake-survey/abcdefghijklmnopqrstuvwxyz012345')])
            ->assertStatus(419)
            ->assertSee('時間がたったため送信できませんでした');
    }
}
