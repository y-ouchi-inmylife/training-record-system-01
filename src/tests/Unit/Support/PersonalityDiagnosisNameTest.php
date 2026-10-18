<?php

namespace Tests\Unit\Support;

use App\Support\PersonalityDiagnosisName;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

/**
 * 愛犬性格診断の名前（2026-10-18 追加）。名前は lang/ja/messages.php の 1 か所から作る。
 */
class PersonalityDiagnosisNameTest extends TestCase
{
    public function test_ふつうの名前とラベル用の形は文のファイルの同じところから作る(): void
    {
        $this->assertSame('愛犬性格診断', PersonalityDiagnosisName::name());
        $this->assertInstanceOf(HtmlString::class, PersonalityDiagnosisName::labelHtml());
        $this->assertSame('愛犬<wbr>性格診断', (string) PersonalityDiagnosisName::labelHtml());
    }

    public function test_分けた部分が名前と合わないときは折り返しの印を入れずに名前をそのまま出す(): void
    {
        // 名前だけを差し替えたときなど。分けた部分の古い名前を出さない
        app('translator')->addLines(['messages.personality_diagnosis_name' => 'テスト<診断>'], 'ja');

        $this->assertSame('テスト&lt;診断&gt;', (string) PersonalityDiagnosisName::labelHtml());
    }
}
