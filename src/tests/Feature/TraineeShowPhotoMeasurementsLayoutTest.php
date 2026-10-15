<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineeMeasurement;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

/**
 * トレーニー詳細（S-0309）の「写真＋体重推移グラフ」と「計測値」の並び（2026-10 変更。設計書 S-0309
 * 「写真とグラフと計測値の並び」）。
 *
 * - xl 以上は横に並べる（左 col-xl-8 が写真とグラフ、右 col-xl-4 が計測値。2026-10 変更：当初は 7：5）。それより狭いときは縦に積む（col-12）
 * - 計測値の一覧はスクロールの枠（.trainee-measurements-scroll）の中。xl 以上は左のカードの高さにそろえ（h-100）、
 *   それより狭いときは最大の高さで中でスクロールする（app.scss）
 * - 見出し（件数と［新規登録］）はスクロールの枠の外
 */
class TraineeShowPhotoMeasurementsLayoutTest extends TestCase
{
    private array $weightChartDatasets = [];

    private function render(int $count): string
    {
        $trainer = new Trainer;
        $trainer->id = 1;
        $trainer->role = 'admin';
        $this->actingAs($trainer);

        $client = new Client;
        $client->id = 1;
        $client->last_name = '山田';
        $client->internal_id = 1;
        $client->exists = true;

        $trainee = new Trainee;
        $trainee->id = 1;
        $trainee->name = 'モモ';
        $trainee->exists = true;
        $trainee->setRelation('client', $client);
        $measurements = [];
        for ($i = 0; $i < $count; $i++) {
            $m = new TraineeMeasurement([
                'measured_date' => sprintf('2026-10-%02d', 20 - $i),
                'measured_time' => '09:00:00',
                'weight_kg' => '5.00',
            ]);
            $m->id = $i + 1;
            $m->exists = true;
            $measurements[] = $m;
        }
        $trainee->setRelation('measurements', new EloquentCollection($measurements));
        $trainee->setRelation('activeIntakeSurveyToken', null);
        $trainee->setRelation('latestIntakeSurveyResponse', null);
        $trainee->setRelation('personalityDiagnosisResults', new EloquentCollection([]));

        return $this->withViewErrors([])->view('trainees.show', [
            'trainee' => $trainee,
            'weightChart' => ['id' => 1, 'callName' => 'モモ', 'photoUrl' => null, 'datasets' => $this->weightChartDatasets],
            'deleteConfirmMessage' => 'このトレーニーを削除しますか？',
            'measurementCount' => $count,
        ])->__toString();
    }

    /** グラフを描く形（datasets あり）で 3 件の画面を出す */
    private function renderWithChart(): string
    {
        $this->weightChartDatasets = [['label' => '体重', 'data' => [['x' => '2026-10-20T09:00:00', 'y' => 5.0]]]];

        return $this->render(3);
    }

    /** 並びの枠（写真とグラフのカードの始まりから、計測値のカードの終わりまで） */
    private function layout(string $html): string
    {
        $start = strpos($html, '<div class="row g-3 mb-3" data-trainee-photo-measurements>');
        $this->assertNotFalse($start);
        $end = strpos($html, 'id="intake-survey"', $start);

        return substr($html, $start, $end - $start);
    }

    public function test_写真とグラフと計測値をxl以上で横に並べる枠に入れる(): void
    {
        $layout = $this->layout($this->render(3));

        // 左：写真とグラフ（xl で 8/12）、右：計測値（xl で 4/12）。xl 未満はどちらも col-12（縦に積む）
        $left = strpos($layout, '<div class="col-12 col-xl-8">');
        $right = strpos($layout, '<div class="col-12 col-xl-4">');
        $this->assertStringNotContainsString('col-xl-7', $layout);
        $this->assertStringNotContainsString('col-xl-5', $layout);
        $this->assertNotFalse($left);
        $this->assertNotFalse($right);
        $this->assertTrue($left < strpos($layout, '写真なし') && strpos($layout, '写真なし') < $right);
        $this->assertGreaterThan($right, strpos($layout, '計測値（3件）'));
        // 計測値のカードは列いっぱい（左のカードの高さにそろえる）
        $this->assertMatchesRegularExpression('/<div class="col-12 col-xl-4">\s*<div class="card h-100">/u', $layout);
        // 前の 60vh のスクロールは使わない
        $this->assertStringNotContainsString('max-height: 60vh', $layout);
    }

    public function test_計測値の一覧はスクロールの枠の中で見出しは枠の外(): void
    {
        $layout = $this->layout($this->render(20));

        $this->assertSame(1, substr_count($layout, 'data-trainee-measurements-scroll'));
        $this->assertStringContainsString('<div class="table-responsive trainee-measurements-scroll" data-trainee-measurements-scroll>', $layout);
        $scroll = strpos($layout, 'data-trainee-measurements-scroll');
        // カードの見出し（件数と［新規登録］）はスクロールの枠より前（枠の外）
        $this->assertLessThan($scroll, strpos($layout, '<div class="card-header d-flex justify-content-between align-items-center">'));
        $this->assertLessThan($scroll, strpos($layout, '計測値（20件）'));
        $this->assertLessThan($scroll, strpos($layout, '>新規登録</button>'));
        // 一覧（列の見出しと 20 行）は枠の中
        $this->assertGreaterThan($scroll, strpos($layout, '<thead>'));
        $this->assertSame(20, substr_count($layout, '>編集</button>'));
    }

    public function test_計測値がないときはスクロールの枠を出さず並びは同じ(): void
    {
        $layout = $this->layout($this->render(0));

        $this->assertStringContainsString('<div class="col-12 col-xl-8">', $layout);
        $this->assertMatchesRegularExpression('/<div class="col-12 col-xl-4">\s*<div class="card h-100">/u', $layout);
        // 計測値が 0 件のときのグラフの案内も、グラフと同じ高さ（280px）
        $this->assertMatchesRegularExpression('/style="height: 280px;">\s*まだ計測値がありません。/u', $layout);
        $this->assertStringNotContainsString('data-trainee-measurements-scroll', $layout);
        $this->assertMatchesRegularExpression('/<div class="card-body">\s*<p class="text-muted mb-0">計測値は登録されていません<\/p>\s*<\/div>/u', $layout);
        $this->assertStringContainsString('計測値（0件）', $layout);
    }

    public function test_グラフの枠の高さは280px(): void
    {
        $layout = $this->layout($this->renderWithChart());

        // 2026-10 変更：当初は会員側と同じ 180px
        $this->assertMatchesRegularExpression('/<div style="position: relative; height: 280px;" data-trainee-weight-chart>\s*<canvas data-measurement-chart=/u', $layout);
        $this->assertStringNotContainsString('height: 180px', $layout);
    }

    public function test_スクロールの枠の大きさと列の見出しの固定はappのscssにある(): void
    {
        $scss = file_get_contents(resource_path('sass/app.scss'));

        $this->assertMatchesRegularExpression(
            '/\.trainee-measurements-scroll \{\s*max-height: 28rem;\s*overflow-y: auto;\s*thead th \{\s*position: sticky;\s*top: 0;\s*z-index: 1;\s*\}\s*'
            .'@include media-breakpoint-up\(xl\) \{\s*flex: 1 1 0;\s*height: 0;\s*max-height: none;\s*\}\s*\}/u',
            $scss
        );
    }
}
