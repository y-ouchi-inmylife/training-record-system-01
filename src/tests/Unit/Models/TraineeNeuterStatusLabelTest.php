<?php

namespace Tests\Unit\Models;

use App\Models\Trainee;
use PHPUnit\Framework\TestCase;

/**
 * Trainee::neuterStatusLabels() と `neuter_status_label` アクセサの対応表のテスト（2026-10 追加）。
 *
 * 去勢・避妊の表示文言は Trainee::neuterStatusLabels() に集約されており、画面はすべてここを参照する
 *（性別の TraineeSexLabelTest と同じ作り）。値は db-schema.md 5-21。
 */
class TraineeNeuterStatusLabelTest extends TestCase
{
    public function test_neuterStatusLabels_の対応表(): void
    {
        $this->assertSame([
            'neutered' => 'している',
            'not_neutered' => 'していない',
            'unknown' => '不明',
        ], Trainee::neuterStatusLabels());
    }

    public function test_neuter_status_label_アクセサは対応表に沿った文言を返す(): void
    {
        $t = new Trainee;

        $t->neuter_status = Trainee::NEUTER_NEUTERED;
        $this->assertSame('している', $t->neuter_status_label);

        $t->neuter_status = Trainee::NEUTER_NOT_NEUTERED;
        $this->assertSame('していない', $t->neuter_status_label);

        $t->neuter_status = Trainee::NEUTER_UNKNOWN;
        $this->assertSame('不明', $t->neuter_status_label);
    }

    public function test_neuter_status_が_null_のとき_neuter_status_label_は_null(): void
    {
        $t = new Trainee;
        $t->neuter_status = null;

        $this->assertNull($t->neuter_status_label);
    }
}
