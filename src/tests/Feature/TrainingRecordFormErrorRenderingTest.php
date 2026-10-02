<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainer;
use App\Models\TrainingRecord;
use Tests\TestCase;

/**
 * 段階 3-3 の描画確認用テスト。トレーニング記録の登録・編集のフォーム
 * （S-0401・S-0404）を、エラーバッグで描画し、§2-7 の規約に合うかを
 * 確かめる。3-1 / 3-2 と同じ作り方で、DB は使わない（モデルを new する）。
 *
 * `training-records/_form.blade.php` を直接 render する。$trainers は
 * 空の Collection でも描画できる（<option> が無いだけでフォームは成り立つ）。
 */
class TrainingRecordFormErrorRenderingTest extends TestCase
{
    private function makeClient(): Client
    {
        $c = new Client();
        $c->id = 1;
        $c->internal_id = '0001';
        $c->last_name = '山田';
        $c->first_name = '太郎';
        return $c;
    }

    private function renderCreate(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->blade(
            '@include(\'training-records._form\', ['
            . "'action' => '/x', 'method' => 'POST', 'record' => null,"
            . " 'selectedClient' => \$selectedClient, 'selectedClientId' => \$selectedClient->id,"
            . " 'trainers' => \$trainers, 'mediaInitial' => [] ])",
            [
                'selectedClient' => $this->makeClient(),
                'trainers' => collect(),
            ]
        );
    }

    private function renderEdit(array $errors): \Illuminate\Testing\TestView
    {
        $client = $this->makeClient();
        $record = new TrainingRecord();
        $record->id = 100;
        $record->client_id = $client->id;
        $record->setRelation('client', $client);
        $record->setRelation('mediaRecords', collect());
        // training_date / training_time / trainer1_id / trainer2_id /
        // record_content / impression は空のまま（old() が無ければ空で描画）

        return $this->withViewErrors($errors)->blade(
            '@include(\'training-records._form\', ['
            . "'action' => '/x', 'method' => 'PUT', 'record' => \$record,"
            . " 'trainers' => \$trainers, 'mediaInitial' => [] ])",
            [
                'record' => $record,
                'trainers' => collect(),
            ]
        );
    }

    // ---- 登録画面（S-0401）----

    public function test_登録_formにnovalidateが付く(): void
    {
        $view = $this->renderCreate([]);
        $view->assertSee('id="trainingRecordForm"', false);
        $view->assertSee('novalidate', false);
    }

    public function test_登録_エラーなしでは上部案内も欄下文言も出ない(): void
    {
        $view = $this->renderCreate([]);
        // 上部案内の文言と、欄下の表示（is-invalid・invalid-feedback）が出ないことを確認。
        // alert-danger は要約モーダル内に d-none の空枠（#summaryError）として常時存在するため
        // 単独では評価せず、文言と欄下の表示で判定する。
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('invalid-feedback', false);
        $view->assertDontSee('is-invalid', false);
    }

    public function test_登録_全項目にエラーで上部案内と各欄のエラーが出る(): void
    {
        $errors = [
            'client_id' => 'ERR_client_id',
            'training_date' => 'ERR_training_date',
            'training_time' => 'ERR_training_time',
            'trainer1_id' => 'ERR_trainer1_id',
            'trainer2_id' => 'ERR_trainer2_id',
            'record_content' => 'ERR_record_content',
            'impression' => 'ERR_impression',
        ];
        $view = $this->renderCreate($errors);

        $view->assertSee('入力内容に誤りがあります。');

        // 旧 .validation-error-summary が出ていない
        $view->assertDontSee('validation-error-summary', false);

        foreach ($errors as $message) {
            $view->assertSee($message);
        }
    }

    public function test_登録_JSの入力チェックが残っていない(): void
    {
        $view = $this->renderCreate([]);
        $view->assertDontSee('validation-error-summary', false);
        // JS 直書きの文言が出ていない
        $view->assertDontSee('会員を選択してください。');
        $view->assertDontSee('トレーニング日を入力してください。');
        $view->assertDontSee('担当1を選択してください。');
    }

    // ---- 編集画面（S-0404）----

    public function test_編集_formにnovalidateが付く(): void
    {
        $view = $this->renderEdit([]);
        $view->assertSee('id="trainingRecordForm"', false);
        $view->assertSee('novalidate', false);
    }

    public function test_編集_エラーなしでは上部案内も欄下文言も出ない(): void
    {
        $view = $this->renderEdit([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_編集_全項目にエラーで上部案内と各欄のエラーが出る(): void
    {
        $errors = [
            'client_id' => 'ERR_client_id',
            'training_date' => 'ERR_training_date',
            'training_time' => 'ERR_training_time',
            'trainer1_id' => 'ERR_trainer1_id',
            'trainer2_id' => 'ERR_trainer2_id',
            'record_content' => 'ERR_record_content',
            'impression' => 'ERR_impression',
        ];
        $view = $this->renderEdit($errors);

        $view->assertSee('入力内容に誤りがあります。');
        $view->assertDontSee('validation-error-summary', false);

        foreach ($errors as $message) {
            $view->assertSee($message);
        }

        // 代表的な欄に is-invalid が付く
        $view->assertSee('is-invalid', false);
    }
}
