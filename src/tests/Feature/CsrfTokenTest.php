<?php

namespace Tests\Feature;

use App\Models\Trainer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

/**
 * CSRF トークンの取り直しの口（GET /csrf-token。2026-10-21 追加。App\Http\Controllers\CsrfTokenController。設計書 architecture.md 2-7）。
 *
 * - ログイン中：200 で今のセッションのトークンとログイン中のトレーナーの ID を返す
 * - ログインが切れている：JSON の 401（auth のミドルウェア）
 * - Cache-Control: no-store が付く（PreventPageCache）
 */
class CsrfTokenTest extends TestCase
{
    private function trainer(int $id = 2): Trainer
    {
        $trainer = new Trainer(['name' => '村山', 'login_id' => 'murayama', 'role' => 'admin']);
        $trainer->id = $id;
        $trainer->exists = true;

        return $trainer;
    }

    public function test_ログイン中はトークンとトレーナーのIDを返しno_storeが付く(): void
    {
        $response = $this->actingAs($this->trainer(), 'web')->getJson(route('csrf-token'));

        $response->assertOk()->assertExactJson(['token' => session()->token(), 'user_id' => 2]);
        $this->assertNotSame('', session()->token());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_ログインが切れていればJSONの401(): void
    {
        $response = $this->getJson(route('csrf-token'));

        $response->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_取り直したトークンで送ると419にならない(): void
    {
        // CSRF の確認を効かせる（ClientIntakeSurveySessionExpiredTest と同じ）
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->actingAs($this->trainer(), 'web');

        $this->post('/logout', ['_token' => 'stale'], ['Accept' => 'application/json'])->assertStatus(419);
        $token = $this->getJson(route('csrf-token'))->assertOk()->json('token');
        $this->post('/logout', [], ['X-CSRF-TOKEN' => $token])->assertRedirect('/login');
    }
}
