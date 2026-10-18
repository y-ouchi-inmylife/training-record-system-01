<?php

namespace Tests\Feature;

use App\Models\Client;
use Tests\TestCase;

/**
 * 契約・利用のモーダル（S-0305-M04 / M05）のエラー表示の描画確認。
 * TraineeMeasurementModalErrorRenderingTest にならう。
 *
 * 2 つのモーダルは入力欄（note）の名前が重なるため、hidden `_form_type`
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
            "@include('clients._contract-modal', ['client' => \$client, 'openModal' => \$openModal, 'defaultExpiresOn' => '2026-10-31'])\n"
            ."@include('clients._usage-modal', ['client' => \$client, 'openModal' => \$openModal,"
            ." 'contractOptions' => \$contractOptions, 'defaultContractId' => \$defaultContractId])\n"
            ."@stack('scripts')",
            [
                'client' => $client,
                'openModal' => $openModal,
                // 利用モーダルの「契約」の選択肢（ContractTicketService::build() の contractOptions と同じ形）
                'contractOptions' => [
                    ['id' => 7, 'label' => '2026/09/03 契約（2回） あと 0回', 'usedUp' => true],
                    ['id' => 8, 'label' => '2026/10/12 契約（4回） あと 3回', 'usedUp' => false],
                ],
                'defaultContractId' => 8,
            ]
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

    /** 利用モーダルの JS（2 つ目の <script>。@vite の <script type="module" …> は数えない） */
    private function usageScript(string $html): string
    {
        $first = strpos($html, '</script>', strpos($html, '<script>'));
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

        // 利用モーダルには回数の欄がなく、メモ（note）は欄の名前が重なるが、利用モーダルには出ない
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
        // メモ（note）は契約・利用の両方のモーダルにある欄。利用モーダルのエラーは利用モーダルにだけ出る
        $html = $this->renderModals('usage', ['note' => 'ERR_note_usage'], [
            '_form_type' => 'usage', 'used_on' => '2026-10-01', 'note' => str_repeat('あ', 256),
        ]);

        $this->assertStringContainsString('ERR_note_usage', $this->usagePart($html));
        $this->assertStringNotContainsString('ERR_note_usage', $this->contractPart($html));
        $this->assertStringNotContainsString('is-invalid', $this->contractPart($html));

        $this->assertStringContainsString('oldUsageId', $this->usageScript($html));
        $this->assertStringNotContainsString('oldContractId', $this->contractScript($html));
    }

    public function test_編集で失敗したときは編集モードで開き直すためのidが渡る(): void
    {
        $html = $this->renderModals('usage', ['used_on' => 'ERR'], [
            '_form_type' => 'usage', '_usage_id' => '42', 'used_on' => 'x',
        ]);

        $this->assertStringContainsString('const oldUsageId = "42";', $this->usageScript($html));
    }

    // ---- 利用モーダルの「契約」の選択（S-0305-M05） ----

    /** 利用モーダルの「契約」の <select> の HTML */
    private function contractSelect(string $html): string
    {
        $start = strpos($html, '<select', strpos($html, 'id="usageModal"'));
        $end = strpos($html, '</select>', $start);

        return preg_replace('/\s+/u', ' ', substr($html, $start, $end - $start));
    }

    // ---- 有効期限の初期値（契約日の月の最終日。2026-10-18 追加。設計書 S-0305-M04「有効期限の初期値」） ----

    public function test_エラーがなければ有効期限の欄に初期値が入り登録で開くときに使う(): void
    {
        $html = $this->renderModals(null, []);

        $this->assertMatchesRegularExpression('/id="expires_on" name="expires_on"\s*value="2026-10-31"/u', $this->contractPart($html));
        $script = $this->contractScript($html);
        $this->assertStringContainsString('const DEFAULT_EXPIRES_ON = "2026-10-31";', $script);
        // 登録で開くときは初期値を入れて契約日に合わせ、編集では合わせない
        $this->assertMatchesRegularExpression('/setExpiresOn\(DEFAULT_EXPIRES_ON\);\s*expiresOnFollows = true;/u', $script);
        $this->assertMatchesRegularExpression('/expiresOnFollows = false;\s*setExpiresOn\(data\.expiresOn \|\| \'\'\);/u', $script);
        // 月の最終日の計算の JS（resources/js/contract-expires-on.js）を読み込む
        $this->assertMatchesRegularExpression('/<script type="module" src="[^"]*\/build\/assets\/contract-expires-on-[^"]+\.js"><\/script>/u', $html);
    }

    public function test_エラーで戻ったときは送った有効期限を出し初期値で上書きしない(): void
    {
        $html = $this->renderModals('contract', ['count' => 'ERR_count'], [
            '_form_type' => 'contract', 'contracted_on' => '2026-11-05', 'count' => '', 'expires_on' => '2027-01-15',
        ]);

        $contract = $this->contractPart($html);
        $this->assertMatchesRegularExpression('/id="expires_on" name="expires_on"\s*value="2027-01-15"/u', $contract);
        $this->assertStringNotContainsString('value="2026-10-31"', $contract);
        // 登録で開き直すときは、送った有効期限が契約日の月の最終日のままかで、合わせ続けるかを決める
        $this->assertStringContainsString('isFollowingContractedOn(contractedOnInput.value, expiresOnInput.value)', $this->contractScript($html));
    }

    public function test_エラーで戻ったとき有効期限を空で送っていれば空のまま(): void
    {
        $html = $this->renderModals('contract', ['count' => 'ERR_count'], [
            '_form_type' => 'contract', 'contracted_on' => '2026-11-05', 'count' => '', 'expires_on' => '',
        ]);

        $contract = $this->contractPart($html);
        $this->assertMatchesRegularExpression('/id="expires_on" name="expires_on"\s*value=""/u', $contract);
        $this->assertStringNotContainsString('value="2026-10-31"', $contract);
    }

    public function test_編集でエラーのときは送った有効期限のまま合わせない(): void
    {
        $html = $this->renderModals('contract', ['count' => 'ERR_count'], [
            '_form_type' => 'contract', '_contract_id' => '7', 'contracted_on' => '2026-09-03', 'count' => '', 'expires_on' => '2026-12-15',
        ]);

        $this->assertMatchesRegularExpression('/id="expires_on" name="expires_on"\s*value="2026-12-15"/u', $this->contractPart($html));
        $this->assertMatchesRegularExpression('/setEditMode\(oldContractId\);\s*expiresOnFollows = false;/u', $this->contractScript($html));
    }

    public function test_契約の選択肢は使い切りが無効で最後に契約なしが来る(): void
    {
        $select = $this->contractSelect($this->renderModals(null, []));

        $this->assertMatchesRegularExpression('/<option value="7" data-used-up="1" disabled\s*>2026\/09\/03 契約（2回） あと 0回<\/option>/u', $select);
        $this->assertMatchesRegularExpression('/<option value="8" data-used-up="0"\s*>2026\/10\/12 契約（4回） あと 3回<\/option>/u', $select);
        $this->assertLessThan(strrpos($select, '契約なし'), strpos($select, 'あと 3回'), '契約なしは最後');
        $this->assertStringNotContainsString('selected', $select, 'エラーなしではサーバー側で選ばない（JS が初期値を選ぶ）');
    }

    public function test_利用モーダルに回数の欄がない(): void
    {
        $html = $this->renderModals(null, []);

        // 利用 1 件は 1 回分。入力項目は契約・利用日・メモの 3 つ
        $usage = $this->usagePart($html);
        $this->assertStringNotContainsString('name="count"', $usage);
        $this->assertStringNotContainsString('回数', $usage);
        $this->assertStringContainsString('name="client_contract_id"', $usage);
        $this->assertStringContainsString('name="used_on"', $usage);
        $this->assertStringContainsString('name="note"', $usage);
        // JS も回数を扱わない（初期値 1・data-count の受け取りがない）
        $this->assertStringNotContainsString('countInput', $this->usageScript($html));
        // 契約モーダルには回数の欄がある（契約回数）
        $this->assertStringContainsString('name="count"', $this->contractPart($html));
    }

    public function test_利用モーダルの項目は利用日_契約_メモの順(): void
    {
        $usage = $this->usagePart($this->renderModals(null, []));

        $usedOn = strpos($usage, 'name="used_on"');
        $contract = strpos($usage, 'name="client_contract_id"');
        $note = strpos($usage, 'name="note"');
        $this->assertNotFalse($usedOn);
        $this->assertLessThan($contract, $usedOn, '利用日が契約より前');
        $this->assertLessThan($note, $contract, '契約がメモより前');
    }

    public function test_登録時の初期値は残りがある一番古い契約として渡る(): void
    {
        $html = $this->renderModals(null, []);

        $this->assertStringContainsString('const DEFAULT_CONTRACT_ID = 8;', $this->usageScript($html));
    }

    public function test_エラーで開き直したとき選んでいた契約が戻る(): void
    {
        $html = $this->renderModals('usage', ['client_contract_id' => 'ERR_contract'], [
            '_form_type' => 'usage', 'client_contract_id' => '8', 'used_on' => '2026-10-01',
        ]);

        $select = $this->contractSelect($html);
        $this->assertMatchesRegularExpression('/<option value="8"[^>]*selected/u', $select);
        $this->assertDoesNotMatchRegularExpression('/<option value=""[^>]*selected/u', $select);
        // 契約の欄の下に文言、欄に赤枠（利用モーダルの中だけ）
        $this->assertStringContainsString('ERR_contract', $this->usagePart($html));
        $this->assertStringContainsString('is-invalid', $select);
    }

    public function test_エラーで開き直したとき契約なしを選んでいた場合は契約なしが戻る(): void
    {
        // 「契約なし」は value が空。ConvertEmptyStringsToNull で null になって old() に入る
        $html = $this->renderModals('usage', ['used_on' => 'ERR'], [
            '_form_type' => 'usage', 'client_contract_id' => null, 'used_on' => 'x',
        ]);

        $select = $this->contractSelect($html);
        $this->assertMatchesRegularExpression('/<option value="" data-used-up="0"\s*selected/u', $select);
        $this->assertDoesNotMatchRegularExpression('/<option value="8"[^>]*selected/u', $select);
    }

    public function test_編集でエラーのときは今の契約の使い切りを選べるようにする処理が入る(): void
    {
        $html = $this->renderModals('usage', ['used_on' => 'ERR'], [
            '_form_type' => 'usage', '_usage_id' => '42', 'client_contract_id' => '7', 'used_on' => 'x',
        ]);

        $script = $this->usageScript($html);
        // 編集モードでは、マスの data-client-contract-id から今の契約を読んで、その契約だけ無効を外す
        $this->assertStringContainsString('applyDisabled(currentContractOf(oldUsageId));', $script);
        $this->assertMatchesRegularExpression('/<option value="7"[^>]*selected/u', $this->contractSelect($html));
    }
}
