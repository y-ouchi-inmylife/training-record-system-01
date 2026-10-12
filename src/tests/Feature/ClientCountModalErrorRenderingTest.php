<?php

namespace Tests\Feature;

use App\Models\Client;
use Tests\TestCase;

/**
 * 契約・利用のモーダル（S-0305-M04 / M05）のエラー表示の描画確認。
 * TraineeMeasurementModalErrorRenderingTest にならう。
 *
 * 2 つのモーダルは入力欄（count / note）の名前が重なるため、hidden `_form_type`
 * （'contract' / 'usage'）で送信元を見分ける（ClientController::show が old('_form_type') から
 * $openModal を決めて渡す）。設計書「S-0305-M04・M05 に共通の決まり」のとおり：
 *
 * - 送信元のモーダルにだけ、各欄の is-invalid・欄の下の文言が出る
 * - 送信元のモーダルだけが自動で開き直す（開き直しの JS が送信元の側にだけ入る）
 * - 編集で失敗したときは編集モードで開き直す（old('_contract_id') / old('_usage_id')）
 * - モーダルの中では画面上部の案内（「入力内容に誤りがあります。」）を出さない（§2-7）
 *
 * DB・コントローラは介さず、Client を new して id だけセットする。
 */
class ClientCountModalErrorRenderingTest extends TestCase
{
    private function renderModals(?string $openModal, array $errors, array $oldInput = []): string
    {
        $client = new Client;
        $client->id = 1;

        // old() は request に bind された session を参照するため、手動で紐づける
        // （TraineeMeasurementModalErrorRenderingTest と同じ）
        $session = $this->app['session.store'];
        $session->put('_old_input', $oldInput);
        request()->setLaravelSession($session);

        $view = $this->withViewErrors($errors)->blade(
            "@include('clients._contract-modal', ['client' => \$client, 'openModal' => \$openModal])\n"
            ."@include('clients._usage-modal', ['client' => \$client, 'openModal' => \$openModal])\n"
            ."@stack('scripts')",
            ['client' => $client, 'openModal' => $openModal]
        );

        return $view->__toString();
    }

    /** 契約モーダルの HTML（usageModal の開始まで） */
    private function contractPart(string $html): string
    {
        $start = strpos($html, 'id="contractModal"');
        $end = strpos($html, 'id="usageModal"');

        return substr($html, $start, $end - $start);
    }

    /** 利用モーダルの HTML（usageModal の開始から scripts の手前まで） */
    private function usagePart(string $html): string
    {
        $start = strpos($html, 'id="usageModal"');
        $end = strpos($html, '<script>', $start);

        return substr($html, $start, $end - $start);
    }

    /** 契約モーダルの JS（1 つ目の <script>） */
    private function contractScript(string $html): string
    {
        $start = strpos($html, '<script>');
        $end = strpos($html, '</script>', $start);

        return substr($html, $start, $end - $start);
    }

    /** 利用モーダルの JS（2 つ目の <script>） */
    private function usageScript(string $html): string
    {
        $first = strpos($html, '</script>');
        $start = strpos($html, '<script>', $first);
        $end = strpos($html, '</script>', $start);

        return substr($html, $start, $end - $start);
    }

    public function test_エラーなしではどちらのモーダルにも何も出ず開き直さない(): void
    {
        $html = $this->renderModals(null, []);

        $this->assertStringNotContainsString('is-invalid', $html);
        $this->assertStringNotContainsString('入力内容に誤りがあります', $html);
        $this->assertStringNotContainsString('oldContractId', $html);
        $this->assertStringNotContainsString('oldUsageId', $html);
    }

    public function test_契約モーダルのエラーは契約モーダルにだけ出て契約モーダルだけ開き直す(): void
    {
        $errors = [
            'contracted_on' => 'ERR_contracted_on',
            'count' => 'ERR_count',
            'note' => 'ERR_note',
        ];
        $html = $this->renderModals('contract', $errors, [
            '_form_type' => 'contract', 'contracted_on' => 'x', 'count' => '1000', 'note' => 'メモ',
        ]);

        $this->assertStringNotContainsString('入力内容に誤りがあります', $html);

        $contract = $this->contractPart($html);
        $this->assertStringContainsString('ERR_contracted_on', $contract);
        $this->assertStringContainsString('ERR_count', $contract);
        $this->assertStringContainsString('ERR_note', $contract);
        $this->assertStringContainsString('is-invalid', $contract);
        $this->assertStringContainsString('value="1000"', $contract, '入力値が戻る');

        // 欄の名前（count / note）が重なる利用モーダルには出ない
        $usage = $this->usagePart($html);
        $this->assertStringNotContainsString('ERR_count', $usage);
        $this->assertStringNotContainsString('ERR_note', $usage);
        $this->assertStringNotContainsString('is-invalid', $usage);
        $this->assertStringNotContainsString('value="1000"', $usage);

        // 開き直しの JS は契約モーダルの側にだけ入る
        $this->assertStringContainsString('oldContractId', $this->contractScript($html));
        $this->assertStringNotContainsString('oldUsageId', $this->usageScript($html));
    }

    public function test_利用モーダルのエラーは利用モーダルにだけ出て利用モーダルだけ開き直す(): void
    {
        $html = $this->renderModals('usage', ['count' => 'ERR_count_usage'], [
            '_form_type' => 'usage', 'used_on' => '2026-10-01', 'count' => '0',
        ]);

        $this->assertStringContainsString('ERR_count_usage', $this->usagePart($html));
        $this->assertStringNotContainsString('ERR_count_usage', $this->contractPart($html));
        $this->assertStringNotContainsString('is-invalid', $this->contractPart($html));

        $this->assertStringContainsString('oldUsageId', $this->usageScript($html));
        $this->assertStringNotContainsString('oldContractId', $this->contractScript($html));
    }

    public function test_編集で失敗したときは編集モードで開き直すためのidが渡る(): void
    {
        $html = $this->renderModals('usage', ['count' => 'ERR'], [
            '_form_type' => 'usage', '_usage_id' => '42', 'used_on' => '2026-10-01', 'count' => '0',
        ]);

        $this->assertStringContainsString('const oldUsageId = "42";', $this->usageScript($html));
    }
}
