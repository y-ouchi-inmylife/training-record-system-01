<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * お客様側の受付アンケートの仮のルート（GET /client-portal/intake-survey/{token}。2026-10、段階3b）。
 *
 * S-0311 受付アンケート案内画面に載せる URL を route() で作るため、ルートだけを先に定義し、
 * 中身は仮の画面にしている（回答の画面は段階4）。トークンは照合しない。
 * テストでは会員側のホスト（CLIENT_HOST）が空なので、ホストではなくパスで確かめる。
 */
class ClientIntakeSurveyPlaceholderTest extends TestCase
{
    public function test_認証なしでパスから開けて準備中の画面を出す(): void
    {
        $this->assertGuest('web');
        $this->assertGuest('client');

        $response = $this->get('/client-portal/intake-survey/any-token-0000000000000000000000');

        $response->assertOk();
        $response->assertSee('受付アンケート');
        $response->assertSee('このページは準備中です。');
    }

    public function test_ルート名からパスを作れる(): void
    {
        $this->assertSame(
            '/client-portal/intake-survey/abc',
            parse_url(route('client-portal.intake-survey.show', ['token' => 'abc']), PHP_URL_PATH)
        );
    }

    public function test_トークンは照合しないのでどのトークンでも同じ画面(): void
    {
        $this->get('/client-portal/intake-survey/x')->assertOk()->assertSee('このページは準備中です。');
    }
}
