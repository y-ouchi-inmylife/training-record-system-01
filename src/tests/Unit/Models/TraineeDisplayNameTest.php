<?php

namespace Tests\Unit\Models;

use App\Models\Client;
use App\Models\Trainee;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\TestCase;

/**
 * トレーニーの名前の表示（2026-10 追加。screen-design.md §2-4 の例外・要件定義書 6-16）。
 *
 * 名前は任意で、未登録でも空欄にしない。トレーナー側は「名前未登録」、会員側は「愛犬」と出し、
 * 会員側で名前のあとに「ちゃん」を付ける箇所は、未登録なら「ちゃん」も付けない。
 * 空文字（マイグレーションの down() で NULL を埋めた行）も未登録として扱う。
 */
class TraineeDisplayNameTest extends TestCase
{
    private function trainee(?string $name): Trainee
    {
        $t = new Trainee;
        $t->name = $name;

        return $t;
    }

    public function test_名前があるとき(): void
    {
        $t = $this->trainee('モモ');

        $this->assertTrue($t->has_name);
        $this->assertSame('モモ', $t->display_name);
        $this->assertSame('モモ', $t->client_display_name);
        $this->assertSame('モモちゃん', $t->client_call_name);
    }

    public function test_名前が未登録のとき(): void
    {
        foreach ([null, ''] as $name) {
            $t = $this->trainee($name);

            $this->assertFalse($t->has_name, var_export($name, true));
            $this->assertSame('名前未登録', $t->display_name);
            $this->assertSame('愛犬', $t->client_display_name);
            // 「ちゃん」を付けない
            $this->assertSame('愛犬', $t->client_call_name);
        }
    }

    public function test_グラフ用データの呼び名(): void
    {
        $named = $this->trainee('モモ');
        $named->id = 1;
        $named->setRelation('measurements', new EloquentCollection([]));
        $this->assertSame('モモちゃん', $named->weight_chart_data['callName']);

        $unnamed = $this->trainee(null);
        $unnamed->id = 2;
        $unnamed->setRelation('measurements', new EloquentCollection([]));
        $this->assertSame('愛犬', $unnamed->weight_chart_data['callName']);
        $this->assertArrayNotHasKey('name', $unnamed->weight_chart_data);
    }

    public function test_一覧のトレーニー列は名前未登録で括弧を重ねない(): void
    {
        $withAttrs = $this->trainee(null);
        $withAttrs->breed = 'トイプードル';
        $withAttrs->sex = Trainee::SEX_MALE;

        $nameOnly = $this->trainee(null);

        $named = $this->trainee('モモ');

        $client = new Client;
        $client->setRelation('trainees', new EloquentCollection([$withAttrs, $nameOnly, $named]));

        $this->assertSame('名前未登録（トイプードル、男の子）／名前未登録／モモ', $client->trainees_label);
    }
}
