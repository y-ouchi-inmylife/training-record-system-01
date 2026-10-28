<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Vite;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Vite の開発サーバー（npm run dev）の有無で、テストの結果が変わらないようにする（2026-10-28 追加）。
        // 開発サーバーが動いていると public/hot があり、@vite がビルド済みのアセット（/build/assets/…）ではなく
        // 開発サーバーの URL を出すため、ビルド済みのアセットの <script> を確かめるテストが落ちていた。
        // hot ファイルの場所を存在しないパスに向け、テストでは常にビルド済みのマニフェスト（public/build/manifest.json）を使う。
        // public/hot そのものには触れない（開発サーバーはそのまま動かしてよい）
        $this->app->make(Vite::class)->useHotFile(__DIR__.'/vite-hot-file-not-used-in-tests');
    }
}
