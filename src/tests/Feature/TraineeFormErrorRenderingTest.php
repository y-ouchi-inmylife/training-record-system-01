<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestView;
use Tests\TestCase;

/**
 * 段階 3-2 の描画確認用テスト。トレーニーの登録・編集のフォーム（S-0308・S-0310）を、
 * 全項目にエラーが入った ViewErrorBag で描画し、§2-7 の規約に合うかを確かめる。
 *
 * `trainees/_form.blade.php` を直接 render する（コントローラ・DB を介さない）。
 * $client は Client モデルを new して last_name/first_name を attribute でセット
 * （full_name アクセサが参照する）。Trainee モデルは null（新規）または new で渡す。
 */
class TraineeFormErrorRenderingTest extends TestCase
{
    private function makeClient(): Client
    {
        $c = new Client();
        $c->last_name = '山田';
        $c->first_name = '太郎';
        return $c;
    }

    private function renderForm(array $errors, bool $isEdit = false, ?Trainee $trainee = null): TestView
    {
        if ($isEdit && $trainee === null) {
            $trainee = new Trainee();
            $trainee->name = '';
            $trainee->breed = '';
            $trainee->sex = null;
            $trainee->neuter_status = null;
            $trainee->birth_year = null;
            $trainee->birth_month = null;
            $trainee->birth_day = null;
            $trainee->note = '';
        }

        return $this->withViewErrors($errors)->blade(
            '@include(\'trainees._form\', ['
            . "'trainee' => \$trainee, 'client' => \$client, 'action' => '/x',"
            . " 'method' => \$method, 'submitLabel' => '登録', 'cancelUrl' => '/x',"
            . " 'pageTitle' => 'トレーニー登録' ])",
            [
                'trainee' => $trainee,
                'client' => $this->makeClient(),
                'method' => $isEdit ? 'PUT' : 'POST',
            ]
        );
    }

    /** form に novalidate が付く */
    public function test_formにnovalidateが付く(): void
    {
        $view = $this->renderForm([]);
        $view->assertSee('id="traineeForm"', false);
        $view->assertSee('novalidate', false);
    }

    /** エラーがないときは、上部の案内も欄の下の文言も出ない */
    public function test_エラーがないときは上部案内も欄の下の文言も出ない(): void
    {
        $view = $this->renderForm([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('alert-danger', false);
        $view->assertDontSee('invalid-feedback', false);
        $view->assertDontSee('is-invalid', false);
    }

    /**
     * 性別の選択肢は「男の子／女の子／不明」で、旧文言「オス／メス」は出ない
     *（2026-10 変更。文言は Trainee::sexLabels() の 1 か所で決まる）。
     */
    public function test_性別の選択肢は男の子女の子不明で旧文言は出ない(): void
    {
        $view = $this->renderForm([]);

        $view->assertSee('男の子');
        $view->assertSee('女の子');
        $view->assertSee('不明');

        // 旧文言は出ない（<option> の value は male/female/unknown のため、
        // 「オス」「メス」が文字列として一致してはいけない）。
        $view->assertDontSee('オス');
        $view->assertDontSee('メス');
    }

    /** 全項目にエラーが入っているとき：上部に 1 文の案内と各欄のエラーが出る */
    public function test_全項目にエラーが入ったとき上部に1文の案内と各欄のエラーが出る(): void
    {
        $errors = [
            'name' => 'ERR_name',
            'breed' => 'ERR_breed',
            'sex' => 'ERR_sex',
            'neuter_status' => 'ERR_neuter_status',
            'birth_date' => 'ERR_birth_date',
            'note' => 'ERR_note',
        ];
        $view = $this->renderForm($errors, isEdit: true);

        // 上部の 1 文の案内
        $view->assertSee('入力内容に誤りがあります。');

        // 旧 <ul><li>…</li></ul> の一覧が出ない
        $view->assertDontSee('<ul class="mb-0">', false);

        // 各欄の欄下文言
        foreach ($errors as $message) {
            $view->assertSee($message);
        }
        // 代表的な欄に is-invalid が付く
        $view->assertSee('name="name"', false);
        $view->assertSee('is-invalid', false);
    }

    /**
     * 誕生日（2026-10 変更）：年・月・日の 3 つの欄。type="text" inputmode="numeric"、年は 4 桁・
     * 月と日は 2 桁まで。flatpickr（.datepicker）は使わない。説明文を .form-text で出す。
     */
    public function test_誕生日は年月日の3つの数字欄でflatpickrを使わない(): void
    {
        $html = $this->renderForm([])->__toString();

        foreach (['birth_year' => 4, 'birth_month' => 2, 'birth_day' => 2] as $field => $max) {
            $this->assertMatchesRegularExpression(
                '/<input type="text"[^>]*id="'.$field.'" name="'.$field.'" inputmode="numeric" maxlength="'.$max.'"/s',
                $html,
                $field.' の欄'
            );
        }
        $this->assertStringNotContainsString('name="birth_date"', $html);
        $this->assertStringNotContainsString('datepicker', $html);
        $this->assertStringContainsString('※ 年だけ、または年と月だけでも入力できます。', $html);

        // 単位の「年」「月」「日」が欄の右に出る（欄 → 単位の順）
        $this->assertGreaterThan(mb_strpos($html, 'id="birth_year"'), mb_strpos($html, '<span>年</span>'));
        $this->assertGreaterThan(mb_strpos($html, 'id="birth_month"'), mb_strpos($html, '<span>月</span>'));
        $this->assertGreaterThan(mb_strpos($html, 'id="birth_day"'), mb_strpos($html, '<span>日</span>'));
    }

    /**
     * 誕生日のエラーは 3 つの欄のまとまりの下に 1 つだけ出し、3 つの欄すべてを赤枠にする
     *（§2-7 の但し書き）。
     */
    public function test_誕生日のエラーはまとまりの下に1つだけ出て3つの欄が赤枠になる(): void
    {
        $html = $this->renderForm(['birth_date' => '誕生日の日付を確認してください。'])->__toString();

        $this->assertSame(1, substr_count($html, '誕生日の日付を確認してください。'));
        foreach (['birth_year', 'birth_month', 'birth_day'] as $field) {
            $this->assertMatchesRegularExpression(
                '/<input type="text" class="form-control\s+is-invalid\s*"\s+id="'.$field.'"/s',
                $html,
                $field.' の欄が赤枠'
            );
        }
        // まとまりの下（3 つ目の欄より後、説明文より前）に block で出る
        $posError = mb_strpos($html, '誕生日の日付を確認してください。');
        $this->assertGreaterThan(mb_strpos($html, 'id="birth_day"'), $posError);
        $this->assertLessThan(mb_strpos($html, '※ 年だけ、または年と月だけでも入力できます。'), $posError);
        $this->assertStringContainsString('invalid-feedback d-block', $html);
    }

    /** 編集（S-0310）では、登録済みの値が 3 つの欄に入る（年だけ・年月だけでも） */
    public function test_編集では登録済みの誕生日が3つの欄に入る(): void
    {
        $trainee = new Trainee();
        $trainee->name = 'モモ';
        $trainee->birth_year = 2020;
        $trainee->birth_month = 3;
        $trainee->birth_day = null;

        $html = $this->renderForm([], isEdit: true, trainee: $trainee)->__toString();

        $this->assertMatchesRegularExpression('/id="birth_year"[^>]*value="2020"/s', $html);
        $this->assertMatchesRegularExpression('/id="birth_month"[^>]*value="3"/s', $html);
        $this->assertMatchesRegularExpression('/id="birth_day"[^>]*value=""/s', $html);
    }

    /**
     * 誕生日の説明文（2026-10 変更）：年だけ・年月だけでも入力できることが分かる文。登録・編集の両方で出す
     * （受付アンケートの回答画面 S-1413 も同じ文。ClientIntakeSurveyShowTest で確かめる）
     */
    public function test_誕生日の説明文は登録と編集の両方で新しい文(): void
    {
        $trainee = new Trainee();
        $trainee->name = 'モモ';

        foreach ([$this->renderForm([]), $this->renderForm([], isEdit: true, trainee: $trainee)] as $view) {
            $html = $view->__toString();

            $this->assertStringContainsString('<div id="birth_date-help" class="form-text">※ 年だけ、または年と月だけでも入力できます。</div>', $html);
            $this->assertStringNotContainsString('分かるところだけで構いません', $html);
        }
    }

    /** エラーで戻ったときは、入力した値（old）が 3 つの欄に残る */
    public function test_エラーで戻ったときは入力した誕生日が残る(): void
    {
        // old() はリクエストに結び付いたセッションの _old_input を読む
        $this->app['request']->setLaravelSession($this->app['session.store']);
        session()->put('_old_input', ['birth_year' => '2020', 'birth_month' => '2', 'birth_day' => '30']);

        $html = $this->renderForm(['birth_date' => '誕生日の日付を確認してください。'])->__toString();

        $this->assertMatchesRegularExpression('/id="birth_year"[^>]*value="2020"/s', $html);
        $this->assertMatchesRegularExpression('/id="birth_month"[^>]*value="2"/s', $html);
        $this->assertMatchesRegularExpression('/id="birth_day"[^>]*value="30"/s', $html);
    }

    /**
     * 去勢・避妊（2026-10 追加）：性別の右（col-md-4）のプルダウン。選択肢は空・している・していない・不明
     */
    public function test_去勢避妊は性別の右のプルダウンで選択肢は空している_していない_不明(): void
    {
        $html = $this->renderForm([])->__toString();

        $this->assertMatchesRegularExpression('/<select class="form-select\s*"\s+id="neuter_status" name="neuter_status"/s', $html);
        // 性別の欄より後、誕生日の欄より前（3 段目の右の枠）
        $posNeuter = mb_strpos($html, 'id="neuter_status"');
        $this->assertGreaterThan(mb_strpos($html, 'id="sex"'), $posNeuter);
        $this->assertLessThan(mb_strpos($html, 'id="birth_year"'), $posNeuter);
        $this->assertMatchesRegularExpression('/<div class="col-md-4">\s*<div class="row g-2 align-items-center">\s*<label for="neuter_status"/s', $html);

        // 選択肢（空・している・していない・不明）
        $this->assertMatchesRegularExpression(
            '/id="neuter_status".*?<option value=""><\/option>\s*<option value="neutered"\s*>\s*している\s*<\/option>\s*<option value="not_neutered"\s*>\s*していない\s*<\/option>\s*<option value="unknown"\s*>\s*不明\s*<\/option>/s',
            $html
        );
    }

    /** 編集では登録済みの去勢・避妊が選ばれている */
    public function test_編集では登録済みの去勢避妊が選ばれている(): void
    {
        $trainee = new Trainee;
        $trainee->name = 'モモ';
        $trainee->neuter_status = 'not_neutered';

        $html = $this->renderForm([], isEdit: true, trainee: $trainee)->__toString();

        $this->assertMatchesRegularExpression('/<option value="not_neutered" selected>/', $html);
    }

    /** 名前は任意（2026-10 変更）：必須の印（*）と required 属性を付けない */
    public function test_名前に必須の印とrequired属性を付けない(): void
    {
        $html = $this->renderForm([])->__toString();

        $this->assertMatchesRegularExpression('/<label for="name"[^>]*>\s*名前\s*<\/label>/u', $html);
        $this->assertDoesNotMatchRegularExpression('/id="name" name="name"[^>]*required/s', $html);
    }

    /**
     * 誕生日の入力中の年齢表示（2026-10 追加）：欄と表示先を data-birthday-* 属性で見つける。
     * 表示先は「日」の右に置き、aria-live="polite" で読み上げにも伝える。JS は trainee-birthday-input.js
     */
    public function test_誕生日の入力中の表示先と欄にdata属性が付く(): void
    {
        $html = $this->renderForm([])->__toString();

        $this->assertMatchesRegularExpression('/role="group" aria-labelledby="birth_date-label" data-birthday-input>/', $html);
        foreach (['year', 'month', 'day'] as $part) {
            $this->assertMatchesRegularExpression('/id="birth_'.$part.'"[^>]*data-birthday-part="'.$part.'"/s', $html);
        }
        $this->assertStringContainsString('<span class="ms-2" data-birthday-display aria-live="polite"></span>', $html);
        $this->assertGreaterThan(mb_strpos($html, '<span>日</span>'), mb_strpos($html, 'data-birthday-display'));
        $this->assertStringContainsString('trainee-birthday-input', $html);
    }

    // ---- 受付アンケートの案内を発行する（S-0308 だけ。2026-10 追加） ----

    /** 「受付アンケートの案内を発行する」のチェックボックスの <input> を返す（なければ null） */
    private function issueCheckbox(string $html): ?string
    {
        return preg_match('/<input class="form-check-input" type="checkbox" id="issue_intake_survey"[^>]*>/s', $html, $m) ? $m[0] : null;
    }

    public function test_登録で初めて開いたときは受付アンケートのチェックがオン(): void
    {
        $html = $this->renderForm([])->__toString();

        $checkbox = $this->issueCheckbox($html);
        $this->assertNotNull($checkbox);
        $this->assertStringContainsString('name="issue_intake_survey" value="1"', $checkbox);
        $this->assertMatchesRegularExpression('/\schecked>/', $checkbox);
        $this->assertStringContainsString('受付アンケートの案内を発行する', $html);
        $this->assertStringContainsString('お客様にお渡しする案内（QR コード・URL）を発行します。あとから会員詳細でも発行できます。', $html);
    }

    public function test_チェックを外して入力エラーで戻ったときは外した状態を保つ(): void
    {
        $this->app['request']->setLaravelSession($this->app['session.store']);
        // チェックを外すと issue_intake_survey は送られない（old にもない）
        session()->put('_old_input', ['name' => str_repeat('あ', 51)]);

        $html = $this->renderForm(['name' => 'ERR_name'])->__toString();

        $checkbox = $this->issueCheckbox($html);
        $this->assertNotNull($checkbox);
        $this->assertDoesNotMatchRegularExpression('/\schecked>/', $checkbox);
    }

    public function test_チェックしたまま入力エラーで戻ったときはオンのまま(): void
    {
        $this->app['request']->setLaravelSession($this->app['session.store']);
        session()->put('_old_input', ['name' => str_repeat('あ', 51), 'issue_intake_survey' => '1']);

        $html = $this->renderForm(['name' => 'ERR_name'])->__toString();

        $this->assertMatchesRegularExpression('/\schecked>/', $this->issueCheckbox($html));
    }

    public function test_編集では受付アンケートのチェックを出さない(): void
    {
        $html = $this->renderForm([], isEdit: true)->__toString();

        $this->assertNull($this->issueCheckbox($html));
        $this->assertStringNotContainsString('受付アンケートの案内を発行する', $html);
    }
}
