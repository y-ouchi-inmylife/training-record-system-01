<?php

namespace Tests\Feature;

use App\Models\Trainee;
use Tests\TestCase;

/**
 * 段階 3-2 の描画確認用テスト。計測値モーダル `_measurement-modal.blade.php` を、
 * 「2 つ以上のトレーニーが並ぶ状態」で ViewErrorBag と old('_trainee_id') を
 * 使って描画し、§2-7「モーダルの中のフォーム」の規約に合うかを確かめる。
 *
 * - エラーで戻ったときに、対象のモーダル（$shouldReopen）にだけ 1 文の案内と
 *   各欄の is-invalid・<x-form-error> が出る
 * - 他のモーダルには何も出ない
 * - エラーなしでは、どのモーダルにも何も出ない
 *
 * DB・コントローラは介さず、Trainee を new して id だけセットする。
 * ルート `trainee-measurements.store` が存在する必要があるため、
 * $storeUrl 生成の route() 呼び出しは実行環境のルート定義に依存する。
 */
class TraineeMeasurementModalErrorRenderingTest extends TestCase
{
    /** 2 つ以上のモーダルを 1 画面に並べて描画する（S-0305 の会員詳細の擬似） */
    private function renderTwoModals(int $errorTraineeId, array $errors): string
    {
        $trainee1 = new Trainee();
        $trainee1->id = 1;
        $trainee2 = new Trainee();
        $trainee2->id = 2;

        // old() は Request::hasSession() のとき Request::session()->getOldInput() を参照する。
        // CLI テストでは request に session が bind されていないため、session ストアを
        // 作って _old_input を置き、request に手動で紐づける。
        $session = $this->app['session.store'];
        $session->put('_old_input', [
            '_trainee_id' => (string) $errorTraineeId,
            'measured_date' => '2026-01-01',
            'measured_time' => '09:00',
            'weight_kg' => '0',
        ]);
        request()->setLaravelSession($session);

        $view = $this->withViewErrors($errors)->blade(
            "@include('trainees._measurement-modal', ['trainee' => \$trainee1])\n"
            . "@include('trainees._measurement-modal', ['trainee' => \$trainee2])",
            [
                'trainee1' => $trainee1,
                'trainee2' => $trainee2,
            ]
        );

        return $view->__toString();
    }

    /** エラーなしでは、どのモーダルにも案内も is-invalid も出ない */
    public function test_エラーなしでは2つのモーダルどちらにも案内が出ない(): void
    {
        $html = $this->renderTwoModals(1, []);

        $this->assertStringNotContainsString('入力内容に誤りがあります', $html);
        $this->assertStringNotContainsString('alert-danger', $html);
        $this->assertStringNotContainsString('is-invalid', $html);
    }

    /** 2 つ以上並んでいて片方（trainee=1）のエラーで戻ったとき：対象だけに案内と欄下が出る */
    public function test_対象のモーダルにだけ案内と欄下の文言が出る(): void
    {
        $errors = [
            'measured_date' => 'ERR_measured_date',
            'measured_time' => 'ERR_measured_time',
            'weight_kg' => 'ERR_weight_kg',
        ];
        $html = $this->renderTwoModals(1, $errors);

        // 対象のモーダル（trainee=1）
        $t1 = $this->sliceModal($html, 1);
        $this->assertStringContainsString('入力内容に誤りがあります。赤字の項目を確認してください。', $t1);
        $this->assertStringContainsString('ERR_measured_date', $t1);
        $this->assertStringContainsString('ERR_measured_time', $t1);
        $this->assertStringContainsString('ERR_weight_kg', $t1);
        $this->assertStringContainsString('is-invalid', $t1);

        // 対象外のモーダル（trainee=2）には案内も欄下も is-invalid も出ない
        $t2 = $this->sliceModal($html, 2);
        $this->assertStringNotContainsString('入力内容に誤りがあります', $t2);
        $this->assertStringNotContainsString('ERR_measured_date', $t2);
        $this->assertStringNotContainsString('ERR_measured_time', $t2);
        $this->assertStringNotContainsString('ERR_weight_kg', $t2);
        $this->assertStringNotContainsString('is-invalid', $t2);
    }

    /** 対象を trainee=2 にしたとき：対象（trainee=2）だけに出て trainee=1 には出ない */
    public function test_対象が別のトレーニーのときも出し分けできる(): void
    {
        $errors = ['weight_kg' => 'ERR_weight_kg_only'];
        $html = $this->renderTwoModals(2, $errors);

        $t1 = $this->sliceModal($html, 1);
        $t2 = $this->sliceModal($html, 2);

        $this->assertStringNotContainsString('ERR_weight_kg_only', $t1);
        $this->assertStringContainsString('ERR_weight_kg_only', $t2);
    }

    /** モーダル 1 件分の HTML を切り出す。
     *  measurementModal-{id} の <div> から次のモーダル開始・end-of-string までを取る。 */
    private function sliceModal(string $html, int $traineeId): string
    {
        $start = strpos($html, 'id="measurementModal-' . $traineeId . '"');
        if ($start === false) {
            $this->fail("modal for trainee={$traineeId} not found");
        }
        // 次のモーダル開始位置、または文末
        $nextTraineeId = $traineeId + 1;
        $end = strpos($html, 'id="measurementModal-' . $nextTraineeId . '"', $start);
        return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
    }
}
