<?php

namespace Tests\Feature;

use App\Http\Requests\TraineeRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * トレーニーの誕生日（年・月・日の 3 つの欄）の判定（TraineeRequest の withValidator）。
 *
 * screen-design.md S-0308「バリデーションチェック」の表の上から順に判定し、最初に当てはまった
 * 1 つだけをキー birth_date に載せる。基準日は Carbon::setTestNow で 2026-10-20 に固定する。
 *
 * 空欄は ConvertEmptyStringsToNull で null になって届くため、テストでも null で渡す。
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

    /** birth_date のエラー（なければ空配列） */
    private function birthDateErrors(mixed $year, mixed $month, mixed $day): array
    {
        $data = ['name' => 'モモ', 'birth_year' => $year, 'birth_month' => $month, 'birth_day' => $day];

        $request = TraineeRequest::create('/trainees', 'POST', $data);
        $request->setContainer($this->app);

        $validator = Validator::make($request->all(), $request->rules(), [], $request->attributes());
        $request->withValidator($validator);
        $validator->passes();

        return $validator->errors()->get('birth_date');
    }

    public static function errorCases(): array
    {
        return [
            // 1. 数字であること
            '年が数字でない' => ['20a0', null, null, '誕生日は数字で入力してください。'],
            '月が数字でない' => ['2020', '３', null, '誕生日は数字で入力してください。'],
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
        $request = TraineeRequest::create('/trainees', 'POST', ['name' => 'モモ', 'birth_year' => 'x', 'birth_month' => '13', 'birth_day' => '40']);
        $request->setContainer($this->app);
        $validator = Validator::make($request->all(), $request->rules(), [], $request->attributes());
        $request->withValidator($validator);
        $validator->passes();

        $this->assertSame(['birth_date'], $validator->errors()->keys());
    }
}
