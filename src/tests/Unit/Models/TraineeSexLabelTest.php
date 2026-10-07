<?php

namespace Tests\Unit\Models;

use App\Models\Trainee;
use PHPUnit\Framework\TestCase;

/**
 * Trainee::sexLabels() と `sex_label` アクセサの対応表のテスト。
 *
 * 性別の表示文言は Trainee::sexLabels() に集約されており、画面はすべてここを参照する。
 * 2026-10 にお客様の要望で「オス／メス」から「男の子／女の子」に変更したため、
 * 対応表の値を直書きで固定し、将来の変更時に気づけるようにする。
 */
class TraineeSexLabelTest extends TestCase
{
    public function test_sexLabels_の対応表(): void
    {
        $this->assertSame([
            'male' => '男の子',
            'female' => '女の子',
            'unknown' => '不明',
        ], Trainee::sexLabels());
    }

    public function test_sex_label_アクセサは対応表に沿った文言を返す(): void
    {
        $t = new Trainee();

        $t->sex = Trainee::SEX_MALE;
        $this->assertSame('男の子', $t->sex_label);

        $t->sex = Trainee::SEX_FEMALE;
        $this->assertSame('女の子', $t->sex_label);

        $t->sex = Trainee::SEX_UNKNOWN;
        $this->assertSame('不明', $t->sex_label);
    }

    public function test_sex_が_null_のとき_sex_label_は_null(): void
    {
        $t = new Trainee();
        $t->sex = null;
        $this->assertNull($t->sex_label);
    }
}
