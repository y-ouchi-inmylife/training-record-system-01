<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 画面をブラウザに残さない（Cache-Control: no-store, private。2026-10-21 追加。App\Http\Middleware\PreventPageCache。
 * 設計書 screen-design.md「ブラウザに画面を残さない（no-store）」）。
 *
 * - web のグループのレスポンス（ログインの要る画面・ログイン画面・回答の画面・ルートの中で出たエラーページ・JSON）には付く。
 *   ログインしていないときのログイン画面へのリダイレクトにも付く。どのルートにも当たらない 404 には付かない（個人情報を出さないため対象外）
 * - ファイルを返すレスポンス（BinaryFileResponse・StreamedResponse）には付かない（音声の再生の Range のリクエストのため）
 * - Cache-Control を意図して付けたレスポンスは上書きしない
 * - ログインの要る画面のレイアウト（layouts.app・layouts.client）には、bfcache で戻ったときに読み込み直す処理がある
 */
class PreventPageCacheTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/__cache/json', fn () => response()->json(['ok' => true]));
        Route::middleware('web')->get('/client-portal/__cache/abort', fn () => abort(404));
        Route::middleware(['web', 'auth:client'])->get('/client-portal/__cache/auth', fn () => view('client.intake-survey.thanks', ['showMypageLink' => false, 'mypageRegistrationUrl' => null]));
        Route::middleware('web')->get('/__cache/file', function () {
            Storage::fake('local');
            Storage::put('cache-test.mp3', 'audio');

            return response()->file(Storage::path('cache-test.mp3'), ['Content-Type' => 'audio/mpeg']);
        });
        Route::middleware('web')->get('/__cache/stream', fn () => response()->streamDownload(fn () => print ('a,b'), 'x.csv'));
        Route::middleware('web')->get('/__cache/explicit', fn () => response('cached')->header('Cache-Control', 'public, max-age=60'));
    }

    private function assertNoStore($response, string $label): void
    {
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'), $label);
    }

    public function test_ログイン画面と回答の画面とエラーページとリダイレクトには付く(): void
    {
        $this->assertNoStore($this->get('/login')->assertOk(), 'トレーナーのログイン画面');
        $this->assertNoStore($this->get('/client-portal/login')->assertOk(), '会員様のログイン画面');
        $this->assertNoStore($this->get('/client-portal/intake-survey/thanks')->assertOk(), '愛犬アンケートの完了の画面');
        $this->assertNoStore($this->get('/client-portal/__cache/abort')->assertNotFound(), 'エラーページ（ルートの中で出たもの）');
        // どのルートにも当たらない 404 は、web のグループが動かないので付かない（個人情報を出さないページのため、対象外）
        $this->assertSame('no-cache, private', $this->get('/client-portal/no-such-page')->assertNotFound()->headers->get('Cache-Control'));
        // ログインしていないときのログイン画面へのリダイレクト（auth のミドルウェアより外側で動かすので付く）
        $this->assertNoStore($this->get('/dashboard')->assertRedirect('/login'), 'ログインの要る画面のリダイレクト');
        $this->assertNoStore($this->get('/client-portal/dashboard')->assertRedirect('/client-portal/login'), '会員様のログインの要る画面のリダイレクト');
        $this->assertNoStore($this->get('/__cache/json')->assertOk(), 'JSON');
    }

    public function test_ログインの要る画面には付く(): void
    {
        $this->createClientsTable();
        $trainer = new Trainer(['name' => '佐藤', 'login_id' => 'sato', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->assertNoStore($this->actingAs($trainer, 'web')->get('/profile')->assertOk(), 'トレーナーのマイプロフィール');

        $client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎', 'email' => 'yamada@example.com']);
        $this->assertNoStore($this->actingAs($client, 'client')->get('/client-portal/__cache/auth')->assertOk(), '会員様のログインの要る画面');
    }

    public function test_ファイルを返すレスポンスと意図して付けたレスポンスには付けない(): void
    {
        $file = $this->get('/__cache/file')->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $file->headers->get('Cache-Control'));

        $stream = $this->get('/__cache/stream')->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $stream->headers->get('Cache-Control'));

        $explicit = $this->get('/__cache/explicit')->assertOk();
        $this->assertSame('max-age=60, public', $explicit->headers->get('Cache-Control'));
    }

    public function test_ログインの要る画面のレイアウトはbfcacheで戻ったときに読み込み直す(): void
    {
        foreach (['layouts/app.blade.php', 'layouts/client.blade.php'] as $layout) {
            $view = file_get_contents(resource_path('views/'.$layout));
            $this->assertMatchesRegularExpression("/window\\.addEventListener\\('pageshow', function \\(event\\) \\{\\s*if \\(event\\.persisted\\) \\{\\s*window\\.location\\.reload\\(\\);/", $view, $layout);
        }
        // 録音実行（独立の HTML）は読み込み直さない（録音がブラウザのメモリにあるため）
        $this->assertStringNotContainsString('pageshow', file_get_contents(resource_path('views/recording-v2/session.blade.php')));
    }
}
