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
    /**
     * @param  array<int, array{id: int, label: string}>  $traineeOptions  利用モーダルの「トレーニー」の選択肢（2026-11 追加。空ならその欄を出さない）
     */
    private function renderModals(?string $openModal, array $errors, array $oldInput = [], ?array $traineeOptions = null, ?int $defaultTraineeId = 21): string
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
            ." 'contractOptions' => \$contractOptions, 'defaultContractId' => \$defaultContractId,"
            ." 'traineeOptions' => \$traineeOptions, 'defaultTraineeId' => \$defaultTraineeId,"
            ." 'defaultStartTime' => \$defaultStartTime, 'defaultEndTime' => \$defaultEndTime])\n"
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
                // トレーニーの選択肢・初期値と、時刻の初期値（2026-11 追加。ClientController::show の $usageForm と同じ形）
                'traineeOptions' => $traineeOptions ?? [
                    ['id' => 21, 'label' => 'アイ'],
                    ['id' => 22, 'label' => '名前未登録'],
                ],
                'defaultTraineeId' => $defaultTraineeId,
                'defaultStartTime' => '09:30',
                'defaultEndTime' => '18:00',
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
        // 2026-11 変更：利用モーダルの最初の select はトレーニーになったため、契約の select（id="client_contract_id"）を探す
        $usageStart = strpos($html, 'id="usageModal"');
        $start = strrpos(substr($html, 0, strpos($html, 'id="client_contract_id"', $usageStart)), '<select');
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

    public function test_利用モーダルの項目は利用日_開始時刻_終了時刻_トレーニー_契約_メモの順(): void
    {
        // 2026-11 変更：時刻とトレーニーを足した（設計書 S-0305-M05「利用日 → 開始時刻・終了時刻（横並び） → トレーニー → 契約 → メモ」）
        $usage = $this->usagePart($this->renderModals(null, []));

        $positions = array_map(fn ($name) => strpos($usage, 'name="'.$name.'"'), ['used_on', 'start_time', 'end_time', 'trainee_id', 'client_contract_id', 'note']);
        foreach ($positions as $position) {
            $this->assertNotFalse($position);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    // ---- 利用モーダルの「トレーニー」と開始時刻・終了時刻（2026-11 追加。S-0305-M05） ----

    /** 利用モーダルの「トレーニー」の select */
    private function traineeSelect(string $html): string
    {
        $usage = $this->usagePart($html);
        $start = strpos($usage, '<select', strpos($usage, 'id="usage_trainee_id"') - 200);
        $end = strpos($usage, '</select>', $start);

        return substr($usage, $start, $end - $start);
    }

    public function test_トレーニーの選択肢はその会員のトレーニーで先頭に未選択の選択肢がある(): void
    {
        $select = $this->traineeSelect($this->renderModals(null, []));

        $this->assertMatchesRegularExpression('/^<select[^>]*name="trainee_id"[^>]*required>\s*<option value="" ><\/option>\s*<option value="21"\s*>アイ<\/option>\s*<option value="22"\s*>名前未登録<\/option>\s*$/u', $select);
    }

    public function test_トレーニーがいない会員ではトレーニーの欄を出さない(): void
    {
        $html = $this->renderModals(null, [], [], [], null);

        $this->assertStringNotContainsString('name="trainee_id"', $this->usagePart($html));
        $this->assertStringContainsString('const DEFAULT_TRAINEE_ID = null;', $this->usageScript($html));
    }

    public function test_登録時のトレーニーと時刻の初期値がサーバーから渡る(): void
    {
        $script = $this->usageScript($this->renderModals(null, []));

        $this->assertStringContainsString('const DEFAULT_TRAINEE_ID = 21;', $script);
        $this->assertStringContainsString('const DEFAULT_START_TIME = "09:30";', $script);
        $this->assertStringContainsString('const DEFAULT_END_TIME = "18:00";', $script);
        // 登録で開くときに初期値を入れ、編集で開くときはマスの値を入れる
        $this->assertStringContainsString('startTimeInput.value = DEFAULT_START_TIME;', $script);
        $this->assertStringContainsString('setTrainee(DEFAULT_TRAINEE_ID);', $script);
        $this->assertStringContainsString('startTimeInput.value = data.startTime || \'\';', $script);
        $this->assertStringContainsString('setTrainee(data.traineeId || null);', $script);
    }

    public function test_時刻はinput_typeがtimeで横に並べる(): void
    {
        $usage = $this->usagePart($this->renderModals(null, []));

        $this->assertMatchesRegularExpression('/<input type="time"[^>]*id="usage_start_time" name="start_time"/u', $usage);
        $this->assertMatchesRegularExpression('/<input type="time"[^>]*id="usage_end_time" name="end_time"/u', $usage);
        $range = substr($usage, strpos($usage, 'data-usage-time-range'));
        $this->assertLessThan(strpos($range, 'name="end_time"'), strpos($range, 'name="start_time"'));
        $this->assertStringNotContainsString('datepicker', substr($range, 0, strpos($range, 'name="end_time"')));
    }

    public function test_時刻のエラーは範囲のまとまりの下に出し入力した値を戻す(): void
    {
        $html = $this->renderModals('usage', ['end_time' => 'ERR_end_time'], [
            '_form_type' => 'usage', 'used_on' => '2026-10-01', 'start_time' => '12:00', 'end_time' => '11:00', 'trainee_id' => '22',
        ]);
        $usage = $this->usagePart($html);

        $this->assertMatchesRegularExpression('/name="start_time"\s+value="12:00"/u', $usage);
        $this->assertMatchesRegularExpression('/name="end_time"\s+value="11:00"/u', $usage);
        $this->assertStringContainsString('<div class="invalid-feedback d-block">ERR_end_time</div>', $usage);
        // 文言は時刻のまとまり（data-usage-time-range）の外の下、トレーニーの欄より前
        $this->assertGreaterThan(strpos($usage, 'name="end_time"'), strpos($usage, 'ERR_end_time'));
        $this->assertLessThan(strpos($usage, 'name="trainee_id"'), strpos($usage, 'ERR_end_time'));
        // 選んでいたトレーニーが戻る
        $this->assertMatchesRegularExpression('/<option value="22"\s*selected/u', $this->traineeSelect($html));
        // モーダルでは上部の案内を出さない
        $this->assertStringNotContainsString('入力内容に誤りがあります', $usage);
    }

    public function test_トレーニーが未選択でエラーのときは未選択のまま戻り欄の下に文言が出る(): void
    {
        $html = $this->renderModals('usage', ['trainee_id' => 'ERR_trainee'], [
            '_form_type' => 'usage', 'used_on' => '2026-10-01', 'trainee_id' => null,
        ]);

        $select = $this->traineeSelect($html);
        $this->assertMatchesRegularExpression('/<option value=""\s*selected/u', $select);
        $this->assertStringContainsString('is-invalid', $select);
        $this->assertStringContainsString('<div class="invalid-feedback">ERR_trainee</div>', $this->usagePart($html));
        // 開き直したときに、未選択の選択肢を出すかを合わせる
        $this->assertStringContainsString('applyTraineeBlank();', $this->usageScript($html));
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
