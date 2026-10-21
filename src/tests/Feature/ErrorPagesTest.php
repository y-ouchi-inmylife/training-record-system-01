<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainer;
use App\Support\ErrorPageSide;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * エラーページ（resources/views/errors/。S-0103・S-0104 ほか。2026-10-21 変更。設計書 screen-design.md「エラーページの共通の作り」）。
 *
 * - 見出し・本文は日本語、カードの下の端に「エラー番号：{番号}」。英語の見出し（419 Page Expired など）は出さない
 * - 会員様側の URL では会員様側の見た目（client.scss・会員様側のヘッダー帯）と会員様のログイン画面へのボタン、
 *   トレーナー側の URL ではトレーナー側の見た目（app.scss・紺のヘッダー帯）とトレーナーのログイン画面へのボタン。
 *   どちらとも決められないときは会員様側（ErrorPageSide）
 * - ログインの状態を読まない（ログイン中でもログインなしでも同じページ）。データベースにつながらないときも 500 のページが出る
 * - 503 はボタンを出さない
 *
 * エラーはテスト用のルート（abort）で起こす。トレーナー側は /__error/{番号}、会員様側は /client-portal/__error/{番号}
 */
class ErrorPagesTest extends TestCase
{
    /** 番号ごとの見出しと本文 */
    private const PAGES = [
        403 => ['このページは表示できません', 'このページを表示する権限がありません。'],
        404 => ['ページが見つかりません', 'お探しのページは、移動したか、なくなった可能性があります。'],
        419 => ['ページの有効期限が切れました', '画面を開いてから時間がたったため、ページの有効期限が切れました。お手数ですが、もう一度ログインしてください。'],
        429 => ['しばらくお待ちください', '短い時間に操作が続いたため、一時的に受け付けを止めています。少し時間をおいてから、もう一度お試しください。'],
        500 => ['エラーが発生しました', '申し訳ございません。一時的なエラーが発生しました。時間をおいてから、もう一度お試しください。'],
        503 => ['ただいまメンテナンス中です', 'ご不便をおかけして申し訳ございません。しばらくしてから、もう一度お試しください。'],
        405 => ['このページは表示できません', 'お手数ですが、はじめからやり直してください。'],
        502 => ['エラーが発生しました', '申し訳ございません。一時的なエラーが発生しました。時間をおいてから、もう一度お試しください。'],
    ];

    /** 会員様側・トレーナー側の CSS（ビルド済みのファイル。Vite の開発サーバーが動いているときはもとのファイルの名前） */
    private const CLIENT_CSS = '#(/build/assets/client-[^"]+\.css|resources/sass/client\.scss)"#';

    private const TRAINER_CSS = '#(/build/assets/app-[^"]+\.css|resources/sass/app\.scss)"#';

    /** 前の作りで出していた英語の見出し・帯（出さない） */
    private const OLD_TEXTS = ['Page Expired', 'Forbidden', 'Not Found', 'Server Error', 'Service Unavailable', 'Too Many Requests', 'bg-warning', 'bg-danger', 'card-header'];

    protected function setUp(): void
    {
        parent::setUp();
        // 500 のページも、デバッグの画面ではなくエラーページで確かめる
        config(['app.debug' => false]);

        Route::middleware('web')->get('/__error/{status}', fn (string $status) => abort((int) $status, request('message', '')));
        Route::middleware('web')->get('/client-portal/__error/{status}', fn (string $status) => abort((int) $status, request('message', '')));
        Route::middleware('web')->get('/__error-db', fn () => DB::table('clients')->count());
        Route::middleware('web')->get('/client-portal/__error-db', fn () => DB::table('clients')->count());
    }

    private function trainer(): Trainer
    {
        $trainer = new Trainer(['name' => '佐藤', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;

        return $trainer;
    }

    private function client(): Client
    {
        $client = new Client(['last_name' => '山田', 'first_name' => '太郎']);
        $client->id = 300;
        $client->exists = true;

        return $client;
    }

    /** ページの見出し・本文・エラー番号が出て、英語の見出しが出ないことを確かめる */
    private function assertErrorPage(string $html, int $status, string $side): void
    {
        [$title, $body] = self::PAGES[$status];
        $this->assertStringContainsString('<h1 class="h5 fw-bold mb-3" data-error-heading>'.$title.'</h1>', $html, $status.' '.$side);
        $this->assertStringContainsString('<p class="mb-0" data-error-body>'.$body.'</p>', $html, $status.' '.$side);
        $this->assertStringContainsString('data-error-code>エラー番号：'.$status.'</p>', $html, $status.' '.$side);
        $this->assertStringContainsString('<title>'.$title.' - ', $html, $status.' '.$side);
        $this->assertStringContainsString('<body data-error-page="'.$status.'" data-error-side="'.$side.'">', $html, $status.' '.$side);
        foreach (self::OLD_TEXTS as $old) {
            $this->assertStringNotContainsString($old, $html, $status.' '.$side.' '.$old);
        }
        // ログインの状態を読むもの・フォームは出さない
        $this->assertStringNotContainsString('csrf-token', $html, $status.' '.$side);
        $this->assertStringNotContainsString('<form', $html, $status.' '.$side);
        $this->assertStringNotContainsString('ログアウト', $html, $status.' '.$side);
    }

    /** 会員様側の見た目（client.scss・会員様側のヘッダー帯・ログイン画面と同じ幅のカード） */
    private function assertClientLook(string $html, int $status): void
    {
        $this->assertMatchesRegularExpression(self::CLIENT_CSS, $html);
        $this->assertDoesNotMatchRegularExpression(self::TRAINER_CSS, $html);
        $this->assertStringContainsString('<nav class="navbar navbar-expand-lg c-nav">', $html);
        $this->assertStringContainsString('<div class="c-login">', $html);
        $this->assertStringContainsString('favicon-client', $html);
        $this->assertStringNotContainsString('bg-trainer-nav', $html);
        if ($status === 503) {
            $this->assertStringNotContainsString('data-error-login', $html);
        } else {
            $this->assertStringContainsString('<a href="'.route('client-portal.login').'" class="btn btn-primary" data-error-login>ログイン画面へ</a>', $html, (string) $status);
            $this->assertSame(1, substr_count($html, 'class="btn '));
        }
    }

    /** トレーナー側の見た目（app.scss・紺のヘッダー帯にシステム名だけ） */
    private function assertTrainerLook(string $html, int $status): void
    {
        $this->assertMatchesRegularExpression(self::TRAINER_CSS, $html);
        $this->assertDoesNotMatchRegularExpression(self::CLIENT_CSS, $html);
        $this->assertStringContainsString('<nav class="navbar navbar-dark bg-trainer-nav">', $html);
        $this->assertStringContainsString('<span class="navbar-brand mb-0">'.config('app.trainer_portal_name').'</span>', $html);
        $this->assertStringContainsString('favicon-trainer', $html);
        $this->assertStringNotContainsString('c-nav', $html);
        $this->assertStringNotContainsString('c-login', $html);
        if ($status === 503) {
            $this->assertStringNotContainsString('data-error-login', $html);
        } else {
            $this->assertStringContainsString('<a href="'.route('login').'" class="btn btn-primary" data-error-login>ログイン画面へ</a>', $html, (string) $status);
            $this->assertSame(1, substr_count($html, 'class="btn '));
        }
    }

    public function test_会員様側のURLでは会員様側の見た目と会員様のログイン画面へのボタン(): void
    {
        foreach (array_keys(self::PAGES) as $status) {
            $html = $this->get('/client-portal/__error/'.$status)->assertStatus($status)->getContent();
            $this->assertErrorPage($html, $status, 'client');
            $this->assertClientLook($html, $status);
        }
    }

    public function test_トレーナー側のURLではトレーナー側の見た目とトレーナーのログイン画面へのボタン(): void
    {
        foreach (array_keys(self::PAGES) as $status) {
            $html = $this->get('/__error/'.$status)->assertStatus($status)->getContent();
            $this->assertErrorPage($html, $status, 'trainer');
            $this->assertTrainerLook($html, $status);
        }
    }

    public function test_本物の419も新しいページ(): void
    {
        // テストでは CSRF の確認が飛ばされるため、飛ばさないものに差し替えてトークンなしで送る
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $trainer = $this->post('/login', ['login_id' => 'x', 'password' => 'y'])->assertStatus(419)->getContent();
        $this->assertErrorPage($trainer, 419, 'trainer');
        $this->assertTrainerLook($trainer, 419);

        $client = $this->post('/client-portal/login', ['email' => 'a@example.com', 'password' => 'y'])->assertStatus(419)->getContent();
        $this->assertErrorPage($client, 419, 'client');
        $this->assertClientLook($client, 419);
    }

    public function test_どのルートにも当たらない404は会員様側で会員様側のURLの404も会員様側(): void
    {
        foreach (['/no-such-page', '/client-portal/no-such-page'] as $url) {
            $html = $this->get($url)->assertStatus(404)->getContent();
            $this->assertErrorPage($html, 404, 'client');
            $this->assertClientLook($html, 404);
        }
    }

    public function test_ログイン中でもログインなしでも同じページ(): void
    {
        foreach ([403, 404, 419, 500, 503] as $status) {
            $guest = $this->get('/client-portal/__error/'.$status)->getContent();
            $asClient = $this->actingAs($this->client(), 'client')->get('/client-portal/__error/'.$status)->getContent();
            $this->assertSame($guest, $asClient, 'client '.$status);
            $this->assertStringNotContainsString('山田', $asClient);

            auth('client')->logout();
            $guest = $this->get('/__error/'.$status)->getContent();
            $asTrainer = $this->actingAs($this->trainer(), 'web')->get('/__error/'.$status)->getContent();
            $this->assertSame($guest, $asTrainer, 'trainer '.$status);
            $this->assertStringNotContainsString('佐藤', $asTrainer);
            auth('web')->logout();
        }
    }

    public function test_ログイン中にログイン画面へを押すとトップへ移る(): void
    {
        // ボタンはログインの状態を読まないので、ログイン中でもログイン画面へのリンク。今の作り（guest のミドルウェア）でトップへ移る
        $this->actingAs($this->client(), 'client')->get(route('client-portal.login'))->assertRedirect('/client-portal/dashboard');
        $this->actingAs($this->trainer(), 'web')->get(route('login'))->assertRedirect('/dashboard');
    }

    public function test_データベースにつながらないときも500のページが出る(): void
    {
        // テストの接続（sqlite）を、ないファイルにして、つながらないようにする
        $this->assertSame('sqlite', config('database.default'));
        config(['database.connections.sqlite.database' => '/nonexistent/dir/database.sqlite']);
        DB::purge('sqlite');
        try {
            DB::connection()->getPdo();
            $this->fail('データベースにつながってしまった');
        } catch (QueryException|\PDOException|\InvalidArgumentException $e) {
            // つながらないことを確かめた
        }

        foreach (['/client-portal/__error-db' => 'client', '/__error-db' => 'trainer'] as $url => $side) {
            $html = $this->get($url)->assertStatus(500)->getContent();
            $this->assertErrorPage($html, 500, $side);
            $side === 'client' ? $this->assertClientLook($html, 500) : $this->assertTrainerLook($html, 500);
        }
    }

    public function test_トレーナー側の403は理由の文があれば本文の下に出し会員様側には出さない(): void
    {
        $html = $this->get('/__error/403?message='.urlencode('管理者のみ削除できます。'))->assertStatus(403)->getContent();
        $this->assertErrorPage($html, 403, 'trainer');
        $this->assertStringContainsString('<p class="mt-2 mb-0" data-error-reason>管理者のみ削除できます。</p>', $html);

        // 英語の既定の文は出さない
        $html = $this->get('/__error/403?message='.urlencode('This action is unauthorized.'))->getContent();
        $this->assertStringNotContainsString('data-error-reason', $html);
        $this->assertStringNotContainsString('unauthorized', $html);

        // 会員様側には出さない
        $html = $this->get('/client-portal/__error/403?message='.urlencode('管理者のみ削除できます。'))->getContent();
        $this->assertStringNotContainsString('data-error-reason', $html);
        $this->assertStringNotContainsString('管理者のみ', $html);

        // 403 のほかの番号には出さない
        $html = $this->get('/__error/404?message='.urlencode('見つかりません。'))->getContent();
        $this->assertStringNotContainsString('data-error-reason', $html);
    }

    public function test_見分け方はホストが分かれているときはホストで決める(): void
    {
        config(['subdomain.trainer_host' => 'staff.example.com', 'subdomain.client_host' => 'my.example.com']);

        $this->assertSame(ErrorPageSide::TRAINER, ErrorPageSide::resolve(Request::create('https://staff.example.com/no-such-page')));
        $this->assertSame(ErrorPageSide::CLIENT, ErrorPageSide::resolve(Request::create('https://my.example.com/no-such-page')));
        $this->assertSame(ErrorPageSide::CLIENT, ErrorPageSide::resolve(Request::create('https://staff.example.com/client-portal/x')));
        // どちらのホストでもない（決められない）ときは会員様側
        $this->assertSame(ErrorPageSide::CLIENT, ErrorPageSide::resolve(Request::create('https://other.example.com/dashboard')));
    }

    public function test_見分け方はホストが分かれていないときはルートに当たったかで決める(): void
    {
        config(['subdomain.trainer_host' => null, 'subdomain.client_host' => null]);

        // ルートに当たらない（404・ルートを決める前の 503 など）は会員様側
        $this->assertSame(ErrorPageSide::CLIENT, ErrorPageSide::resolve(Request::create('http://localhost/dashboard')));
        $this->assertSame(ErrorPageSide::CLIENT, ErrorPageSide::resolve(Request::create('http://localhost/client-portal/dashboard')));

        // トレーナー側の画面のルートに当たっていればトレーナー側
        $request = Request::create('http://localhost/dashboard');
        $request->setRouteResolver(fn () => Route::getRoutes()->match($request));
        $this->assertSame(ErrorPageSide::TRAINER, ErrorPageSide::resolve($request));
    }
}
