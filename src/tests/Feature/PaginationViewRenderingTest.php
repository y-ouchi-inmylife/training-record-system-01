<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * 一覧のページ送り（screen-design.md §2-9）の描画を確かめる。
 * resources/views/vendor/pagination/bootstrap-5.blade.php が、標準の Bootstrap 5 用のビューを
 * 上書きして「全N件中 a〜b件」とページ切り替えを中央に並べることを見る。
 *
 * DB は使わない（LengthAwarePaginator に配列を渡して描画する）。
 */
class PaginationViewRenderingTest extends TestCase
{
    private function render(int $total, int $perPage, int $page): string
    {
        $items = array_slice(range(1, $total), ($page - 1) * $perPage, $perPage);
        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => '/audio-records']);

        return (string) $paginator->links();
    }

    public function test_1ページ目で全7件中1から5件が出る(): void
    {
        $html = $this->render(7, 5, 1);
        $this->assertStringContainsString('全7件中 1〜5件', $html);
    }

    public function test_2ページ目で全7件中6から7件が出る(): void
    {
        $html = $this->render(7, 5, 2);
        $this->assertStringContainsString('全7件中 6〜7件', $html);
    }

    public function test_件数に桁区切りが付く(): void
    {
        $html = $this->render(1234, 50, 2);
        $this->assertStringContainsString('全1,234件中 51〜100件', $html);
    }

    public function test_英語の表示が出ない(): void
    {
        $html = $this->render(7, 5, 1);
        $this->assertStringNotContainsString('Showing', $html);
        $this->assertStringNotContainsString('results', $html);
        $this->assertStringNotContainsString('Previous', $html);
        $this->assertStringNotContainsString('Next', $html);
    }

    public function test_まとまりが中央寄せで_前後のボタンの読み上げ名が日本語になる(): void
    {
        $html = $this->render(7, 5, 1);
        $this->assertMatchesRegularExpression('/<nav class="[^"]*\bjustify-content-center\b[^"]*"/', $html);
        $this->assertStringContainsString('aria-label="前のページ"', $html);
        $this->assertStringContainsString('aria-label="次のページ"', $html);
        // スマホの幅だけの簡易の表示（d-sm-none）は使わない
        $this->assertStringNotContainsString('d-sm-none', $html);
    }

    public function test_1ページに収まるときは何も出ない(): void
    {
        $html = $this->render(3, 5, 1);
        $this->assertSame('', trim($html));
    }
}
