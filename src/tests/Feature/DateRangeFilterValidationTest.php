<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 日付フィルタ相関チェック（開始日 ≦ 終了日）の挙動確認。
 *
 * 対象3画面（S-0304 クライアント一覧 / S-0402 トレーニング記録一覧 / S-0805 操作履歴）が
 * 同一の方式・文言で相関チェックするかを検証する。
 *
 * 本プロジェクトのマイグレーションはMySQL専用のCHECK制約を生SQLで追加するため、
 * テスト用SQLiteでは RefreshDatabase が利用できない。
 * そこで DB に依存しない形で検証する:
 *  - 失敗パス（開始>終了・不正形式）は絞り込みクエリの前に validate() で redirect されるため、
 *    DB へアクセスせず実HTTPで検証できる（認証・認可は対象外のためミドルウェアをバイパス）。
 *  - 正常系のルール通過（開始≦終了・片側のみ・未指定）は、コントローラと同一のルール定義を
 *    Validator ファサードで直接評価して検証する。
 */
class DateRangeFilterValidationTest extends TestCase
{
    /** 検証対象の3ルート */
    private const ROUTES = [
        'S-0304 クライアント一覧' => 'clients.index',
        'S-0402 トレーニング記録一覧' => 'training-records.index',
        'S-0805 操作履歴'         => 'access-logs.index',
    ];

    private const EXPECTED_MESSAGE = '終了日は開始日以降の日付で入力してください。';

    /** コントローラ index() と同一のルール定義。文言は lang/ja/validation.php の
     * custom.date_to.after_or_equal に集約（設計書 §2-8）。 */
    private const RULES = [
        'date_from' => 'nullable|date',
        'date_to'   => 'nullable|date|after_or_equal:date_from',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // 認証・認可はこのテストの対象外（バリデーション挙動の検証に集中する）
        $this->withoutMiddleware();
    }

    /**
     * シナリオ1: 開始 > 終了 → 設計文言でエラーになり、入力値が old() 用にフラッシュされる。
     * （失敗パスは絞り込みクエリの前に redirect されるため DB 非依存）
     */
    public function test_開始日が終了日より後なら設計文言でエラーになる(): void
    {
        foreach (self::ROUTES as $label => $route) {
            $response = $this->get(route($route, [
                'date_from' => '2026-05-01',
                'date_to'   => '2026-04-01',
            ]));

            $response->assertRedirect();
            // 文言が設計どおりであること
            $response->assertSessionHasErrors(['date_to' => self::EXPECTED_MESSAGE], null, 'default', $label);
            // 入力値が old() で復元できるようフラッシュされていること
            $this->assertSame('2026-05-01', session('_old_input.date_from'), $label);
            $this->assertSame('2026-04-01', session('_old_input.date_to'), $label);
        }
    }

    /**
     * シナリオ（参考）: 不正な日付形式 → date ルールで弾かれる（失敗パス・DB非依存）
     */
    public function test_不正な日付形式はエラーになる(): void
    {
        foreach (self::ROUTES as $label => $route) {
            $this->get(route($route, ['date_from' => 'not-a-date']))
                ->assertRedirect()
                ->assertSessionHasErrors(['date_from'], null, 'default', $label);
        }
    }

    /** シナリオ2: 開始 ≦ 終了（正常範囲）→ ルールを通過する */
    public function test_正常な日付範囲はルールを通過する(): void
    {
        $v = Validator::make(
            ['date_from' => '2026-04-01', 'date_to' => '2026-05-01'],
            self::RULES
        );
        $this->assertFalse($v->fails(), '正常な日付範囲でエラーになってはいけない');
    }

    /** シナリオ3: 片方のみ入力 → ルールを通過する（after_or_equal は date_from が無ければ評価されない） */
    public function test_片方のみ入力はルールを通過する(): void
    {
        $fromOnly = Validator::make(['date_from' => '2026-04-01'], self::RULES);
        $this->assertFalse($fromOnly->fails(), '開始のみでエラーになってはいけない');

        $toOnly = Validator::make(['date_to' => '2026-04-01'], self::RULES);
        $this->assertFalse($toOnly->fails(), '終了のみでエラーになってはいけない');
    }

    /** シナリオ4: 両方空 → ルールを通過する（nullable） */
    public function test_日付未指定はルールを通過する(): void
    {
        $v = Validator::make([], self::RULES);
        $this->assertFalse($v->fails(), '日付未指定でエラーになってはいけない');
    }

    /** 反転日付はルール評価でも設計文言で失敗する（ルール定義そのものの確認） */
    public function test_反転日付はルール評価で設計文言で失敗する(): void
    {
        $v = Validator::make(
            ['date_from' => '2026-05-01', 'date_to' => '2026-04-01'],
            self::RULES
        );
        $this->assertTrue($v->fails());
        $this->assertSame(self::EXPECTED_MESSAGE, $v->errors()->first('date_to'));
    }
}
