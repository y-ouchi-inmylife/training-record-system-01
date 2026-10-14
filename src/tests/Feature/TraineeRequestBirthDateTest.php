<?php

namespace Tests\Feature;

use App\Http\Requests\TraineeRequest;
use App\Support\TraineeBirthdayInput;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * トレーニーの誕生日（年・月・日の 3 つの欄）の判定（TraineeRequest の withValidator）。
 *
 * screen-design.md S-0308「バリデーションチェック」の表の上から順に判定し、最初に当てはまった
 * 1 つだけをキー birth_date に載せる。基準日は Carbon::setTestNow で 2026-10-20 に固定する。
 *
 * 空欄は ConvertEmptyStringsToNull で null になって届くため、テストでも null で渡す。
 *
 * 2026-10 追加：全角の数字は半角に直して受け付ける（prepareForValidation）。判定の状態は
 * tests/fixtures/trainee-birthday-input-cases.json の全ケースで、判定の本体（TraineeBirthdayInput）と
 * TraineeRequest の両方を確かめる（入力中の表示の JS も同じ固定データで確かめる）。
 */
class TraineeRequestBirthDateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * birth_date のエラー（なければ空配列）。FormRequest の実際の流れ（prepareForValidation →
     * rules → withValidator）で検証する。
     */
    private function birthDateErrors(mixed $year, mixed $month, mixed $day): array
    {
        return $this->validationErrors(['name' => 'モモ', 'birth_year' => $year, 'birth_month' => $month, 'birth_day' => $day])['birth_date'] ?? [];
    }

    /** 全体のエラー（キー => 文言の配列）。エラーがなければ空配列 */
    private function validationErrors(array $data): array
    {
        $request = TraineeRequest::createFrom(Request::create('/trainees', 'POST', $data));
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);

        try {
            $request->validateResolved();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /** 固定データ（tests/fixtures/trainee-birthday-input-cases.json）の全ケース */
    public static function inputCases(): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/trainee-birthday-input-cases.json'), true, 512, JSON_THROW_ON_ERROR);

        $cases = [];
        foreach ($json['cases'] as $case) {
            $cases[$case['label']] = [$case];
        }

        return $cases;
    }

    /** 状態の記号 → 送信時の文言（lang/ja/validation.php の custom.birth_date）。ok・empty はエラーなし */
    private const STATE_MESSAGES = [
        'not_numeric' => '誕生日は数字で入力してください。',
        'month_out_of_range' => '誕生日の月は1〜12で入力してください。',
        'month_without_year' => '誕生日の月を入れる場合は、年も入力してください。',
        'day_without_year' => '誕生日の日を入れる場合は、年と月も入力してください。',
        'day_without_month' => '誕生日の日を入れる場合は、年と月も入力してください。',
        'invalid_date' => '誕生日の日付を確認してください。',
        'future' => '誕生日が未来になっています。',
        'too_old' => '誕生日の年を確認してください。',
    ];

    #[DataProvider('inputCases')]
    public function test_固定データ_判定の本体は状態の記号を返す(array $case): void
    {
        [$refYear, $refMonth, $refDay] = array_map('intval', explode('-', $case['ref']));

        $this->assertSame(
            $case['state'],
            TraineeBirthdayInput::state($case['year'], $case['month'], $case['day'], $refYear, $refMonth, $refDay)
        );
    }

    #[DataProvider('inputCases')]
    public function test_固定データ_送信時は状態に対応する文言を1つだけ出す(array $case): void
    {
        Carbon::setTestNow($case['ref'].' 10:00:00');

        $expected = in_array($case['state'], ['ok', 'empty'], true) ? [] : [self::STATE_MESSAGES[$case['state']]];
        $this->assertSame($expected, $this->birthDateErrors($case['year'], $case['month'], $case['day']));
    }

    public function test_状態の記号と送信時の文言の対応はlangと一致する(): void
    {
        foreach (self::STATE_MESSAGES as $state => $message) {
            $this->assertSame($message, __('validation.custom.birth_date.'.$state), $state);
        }
    }

    public static function errorCases(): array
    {
        return [
            // 1. 数字であること
            '年が数字でない' => ['20a0', null, null, '誕生日は数字で入力してください。'],
            '月が数字でない' => ['2020', '三', null, '誕生日は数字で入力してください。'],
            '日が負の数' => ['2020', '3', '-1', '誕生日は数字で入力してください。'],
            // 2. 月は 1〜12
            '月が13' => ['2020', '13', null, '誕生日の月は1〜12で入力してください。'],
            '月が0' => ['2020', '0', null, '誕生日の月は1〜12で入力してください。'],
            // 3. 月があるなら年がある
            '月だけ' => [null, '3', null, '誕生日の月を入れる場合は、年も入力してください。'],
            // 4. 日があるなら年・月がある
            '日だけ' => [null, null, '15', '誕生日の日を入れる場合は、年と月も入力してください。'],
            '年と日（月がない）' => ['2020', null, '15', '誕生日の日を入れる場合は、年と月も入力してください。'],
            // 5. 3 つそろったら実在する日付
            '2月30日' => ['2020', '2', '30', '誕生日の日付を確認してください。'],
            'うるう年でない年の2月29日' => ['2021', '2', '29', '誕生日の日付を確認してください。'],
            '日が32' => ['2020', '3', '32', '誕生日の日付を確認してください。'],
            '日が0' => ['2020', '3', '0', '誕生日の日付を確認してください。'],
            // 6. 未来でない
            '年だけで来年' => ['2027', null, null, '誕生日が未来になっています。'],
            '年月で来月' => ['2026', '11', null, '誕生日が未来になっています。'],
            '年月日で明日' => ['2026', '10', '21', '誕生日が未来になっています。'],
            // 7. 年は今年から 30 年前まで
            '31年前' => ['1995', null, null, '誕生日の年を確認してください。'],
            '年が0' => ['0', null, null, '誕生日の年を確認してください。'],
        ];
    }

    #[DataProvider('errorCases')]
    public function test_各段階のエラーが出る(mixed $year, mixed $month, mixed $day, string $message): void
    {
        $this->assertSame([$message], $this->birthDateErrors($year, $month, $day));
    }

    public static function validCases(): array
    {
        return [
            '未入力' => [null, null, null],
            '年だけ' => ['2020', null, null],
            '年月' => ['2020', '3', null],
            '年月日' => ['2020', '3', '15'],
            '先頭が0の月日' => ['2020', '03', '05'],
            'うるう年の2月29日' => ['2024', '2', '29'],
            '今日' => ['2026', '10', '20'],
            '今月（年月）' => ['2026', '10', null],
            '今年（年だけ）' => ['2026', null, null],
            'ちょうど30年前' => ['1996', null, null],
        ];
    }

    #[DataProvider('validCases')]
    public function test_正しい入力ではエラーが出ない(mixed $year, mixed $month, mixed $day): void
    {
        $this->assertSame([], $this->birthDateErrors($year, $month, $day));
    }

    public function test_複数当てはまるときは上の段階の1つだけが出る(): void
    {
        // 月が13（2）かつ年がない（3）かつ日だけ不足（4）→ 2 の月の範囲だけ
        $this->assertSame(['誕生日の月は1〜12で入力してください。'], $this->birthDateErrors(null, '13', '40'));

        // 月があって年がない（3）かつ日に年月がそろっていない（4）→ 3 だけ
        $this->assertSame(['誕生日の月を入れる場合は、年も入力してください。'], $this->birthDateErrors(null, '3', '15'));

        // 実在しない日付（5）かつ未来（6）→ 5 だけ
        $this->assertSame(['誕生日の日付を確認してください。'], $this->birthDateErrors('2027', '2', '30'));

        // 数字でない（1）かつ月の範囲外（2）→ 1 だけ
        $this->assertSame(['誕生日は数字で入力してください。'], $this->birthDateErrors('abc', '13', null));
    }

    public function test_誕生日の項目にはbirth_date以外のキーでエラーを出さない(): void
    {
        $errors = $this->validationErrors(['name' => 'モモ', 'birth_year' => 'x', 'birth_month' => '13', 'birth_day' => '40']);

        $this->assertSame(['birth_date'], array_keys($errors));
    }

    public function test_全角の数字と前後の空白は半角に直してから判定する(): void
    {
        $this->assertSame([], $this->birthDateErrors('２０２０', '　０３ ', ' １５'));
        // 直したあとに数字でなければ誤り
        $this->assertSame(['誕生日は数字で入力してください。'], $this->birthDateErrors('２０２０年', null, null));
    }
}
