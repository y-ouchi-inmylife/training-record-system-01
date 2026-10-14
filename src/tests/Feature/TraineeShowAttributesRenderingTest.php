<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * トレーニー詳細（S-0309）の属性の並びと表示（2026-10 変更）。
 *
 * - 1 段目に犬種・性別・誕生日・去勢・避妊の 4 つ（col-6 col-md-3）、2 段目に備考を全幅（col-12）
 * - 誕生日は要件定義書 6-16「年齢の表示規則」の形（Trainee::birthday_with_age_label）
 * - 去勢・避妊は Trainee::neuter_status_label。未登録は空欄（§2-4）
 */
class TraineeShowAttributesRenderingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function renderTraineeShow(Trainee $trainee): string
    {
        $trainer = new Trainer;
        $trainer->id = 1;
        $trainer->role = 'admin';
        $this->actingAs($trainer);

        $client = new Client;
        $client->id = 1;
        $client->last_name = '山田';
        $client->first_name = '太郎';
        $client->internal_id = 1;
        $client->exists = true;

        $trainee->id = 1;
        $trainee->exists = true;
        $trainee->setRelation('client', $client);
        $trainee->setRelation('measurements', new EloquentCollection([]));
        // 受付アンケートの欄の状態（2026-10 追加）。呼び出し側が渡さなければ未発行
        if (! $trainee->relationLoaded('activeIntakeSurveyToken')) {
            $trainee->setRelation('activeIntakeSurveyToken', null);
        }

        return $this->withViewErrors([])->view('trainees.show', [
            'trainee' => $trainee,
            'weightChart' => ['id' => 1, 'callName' => $trainee->client_call_name, 'photoUrl' => null, 'datasets' => []],
            'deleteConfirmMessage' => 'このトレーニーを削除しますか？',
            'measurementCount' => 0,
        ])->__toString();
    }

    public function test_1段目に犬種性別誕生日去勢避妊_2段目に備考を全幅で並べる(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        $trainee = new Trainee;
        $trainee->name = 'モモ';
        $trainee->breed = 'トイ・プードル';
        $trainee->sex = 'female';
        $trainee->birth_year = 2022;
        $trainee->birth_month = 3;
        $trainee->birth_day = null;
        $trainee->neuter_status = 'neutered';
        $trainee->note = '保護犬';

        $html = $this->renderTraineeShow($trainee);

        // 4 つの属性は col-6 col-md-3 のマスで、この順に並ぶ
        $positions = [];
        foreach (['犬種', '性別', '誕生日', '去勢・避妊'] as $label) {
            $this->assertMatchesRegularExpression(
                '/<div class="col-6 col-md-3">\s*<div class="text-muted small mb-1">'.$label.'<\/div>/u',
                $html,
                $label.' のマス'
            );
            $positions[] = mb_strpos($html, '<div class="text-muted small mb-1">'.$label.'</div>');
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, '犬種・性別・誕生日・去勢・避妊の順');

        // 備考は 2 段目の全幅（col-12）で、4 つの属性の後
        $this->assertMatchesRegularExpression('/<div class="col-12">\s*<div class="text-muted small mb-1">備考<\/div>/u', $html);
        $this->assertGreaterThan(end($positions), mb_strpos($html, '<div class="text-muted small mb-1">備考</div>'));

        // 値
        $this->assertStringContainsString('2022年3月生まれ（4歳7ヶ月）', $html);
        $this->assertStringContainsString('している', $html);
    }

    public function test_誕生日と去勢避妊が未登録なら空欄(): void
    {
        $trainee = new Trainee;
        $trainee->name = 'モモ';

        $html = $this->renderTraineeShow($trainee);

        $this->assertMatchesRegularExpression('/誕生日<\/div>\s*<div style="min-height: 1.5rem;">\s*<\/div>/u', $html);
        $this->assertMatchesRegularExpression('/去勢・避妊<\/div>\s*<div style="min-height: 1.5rem;">\s*<\/div>/u', $html);
        $this->assertStringNotContainsString('生まれ', $html);
    }

    /** 名前が未登録なら、見出しに「名前未登録」を薄い文字で出し、写真の代替テキストも同じ名前にする */
    public function test_名前が未登録なら見出しに名前未登録を薄い文字で出す(): void
    {
        $trainee = new Trainee;
        $trainee->name = null;

        $html = $this->renderTraineeShow($trainee);

        $this->assertMatchesRegularExpression('/<h2 class="mb-0 text-muted">名前未登録<\/h2>/u', $html);
    }

    public function test_名前があるなら見出しは通常の文字(): void
    {
        $trainee = new Trainee;
        $trainee->name = 'モモ';

        $html = $this->renderTraineeShow($trainee);

        $this->assertMatchesRegularExpression('/<h2 class="mb-0">モモ<\/h2>/u', $html);
    }
}
