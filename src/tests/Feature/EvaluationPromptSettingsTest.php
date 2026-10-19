<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\Trainer;
use App\Services\EvaluationDraft\PersonalityEvaluationPrompt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 愛犬性格診断のアドバイスのプロンプト（S-0602。2026-10-19 追加）。要約プロンプト（S-0601）と同じく管理者だけが開け、5000 字まで
 */
class EvaluationPromptSettingsTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAccessLogsTable();
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => '佐藤', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    public function test_管理者は開けて既定の指示文と差し込みの印の一覧と区切りの説明が出る(): void
    {
        $this->actingAsRole('admin');

        $html = $this->get(route('settings.evaluation-prompts.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('<h2 class="mb-0">愛犬性格診断のアドバイスのプロンプト</h2>', $html);
        $this->assertStringContainsString('ドッグトレーナーとして、以下のデータから「お客様用レポート」と「スタッフ用指示書」を作成してください。', $html);
        foreach (['{{犬の名前}}', '{{タイプ}}', '{{スコア}}', '{{悩み}}', '{{背景}}', '{{理想}}', '{{健康}}'] as $placeholder) {
            $this->assertStringContainsString('<code>'.$placeholder.'</code>', $html, $placeholder);
        }
        $this->assertStringContainsString('お客様用とスタッフ用は「#####」で分けて出すよう指示してください', $html);
        $this->assertStringContainsString('（「#####」の前がアドバイス、後ろがスタッフ用メモの欄に入ります）', $html);
        // ［AIで自動生成］の指示書であること（2026-10-19 変更：その前は［AIで下書き］・「…の下書きを作る際の…」）
        $this->assertStringContainsString('詳細の［AIで自動生成］で、AI がアドバイスとスタッフ用メモを自動生成する際の「指示書」です。', $html);
        $this->assertStringNotContainsString('下書き', $html);
        // 既定の指示文の呼び名と欄の対応（2026-10-19 追加）
        $this->assertStringContainsString('<p class="mb-2" data-evaluation-prompt-mapping>既定の指示文の「お客様用レポート」がアドバイスの欄、「スタッフ用メモ」がスタッフ用メモの欄に入ります。</p>', $html);
        // 「評価文」とは呼ばない（2026-10-19 変更：アドバイスと呼ぶことにした。画面の題・メニュー・案内とも）
        $this->assertStringNotContainsString('評価文', $html);
        $this->assertStringContainsString('<title>愛犬性格診断のアドバイスのプロンプト', $html);
        // 書式の決まりを読むだけで出す（2026-10-19 追加。フォームの外。入力欄にしない）
        $this->assertStringContainsString('<p class="fw-bold mb-1" id="evaluation-format-rules-heading">書式の決まり</p>', $html);
        $this->assertStringContainsString('<p class="small text-muted mb-2">次の書式の決まりは、指示文とは別に毎回 AI に渡します。ここでは変更できません。</p>', $html);
        $this->assertStringContainsString('data-evaluation-format-rules-text>'.e(PersonalityEvaluationPrompt::FORMAT_RULES).'</div>', $html);
        $form = substr($html, strpos($html, '<form id="evaluationPromptForm"'));
        $form = substr($form, 0, strpos($form, '</form>'));
        $this->assertStringNotContainsString('次の決まりを必ず守ってください。', $form);
        $this->assertSame(1, substr_count($html, '<textarea'));
        $this->assertGreaterThan(strpos($html, '</form>'), strpos($html, 'data-evaluation-format-rules'));
        // メニューの要約プロンプトのすぐ下
        $this->assertMatchesRegularExpression('/>要約プロンプト<\\/a><\\/li>\\s*<li><a class="dropdown-item" href="'.preg_quote(route('settings.evaluation-prompts.edit'), '/').'">愛犬性格診断のアドバイスのプロンプト<\\/a><\\/li>/u', $html);
    }

    public function test_一般のトレーナーとシステム管理者は開けない(): void
    {
        foreach (['staff', 'system_admin'] as $role) {
            $this->actingAsRole($role);
            $this->assertContains($this->get(route('settings.evaluation-prompts.edit'))->getStatusCode(), [302, 403], $role);
            $this->assertContains($this->put(route('settings.evaluation-prompts.update'), ['evaluation_prompt' => 'x'])->getStatusCode(), [302, 403], $role);
        }
        $this->assertSame(0, SystemSetting::query()->count());
    }

    public function test_保存できて5000字まで(): void
    {
        $this->actingAsRole('admin');

        $this->put(route('settings.evaluation-prompts.update'), ['evaluation_prompt' => str_repeat('あ', 5000)])
            ->assertRedirect(route('settings.evaluation-prompts.edit'))
            ->assertSessionHas('success', '愛犬性格診断のアドバイスのプロンプト設定を保存しました。');
        $this->assertSame(5000, mb_strlen(SystemSetting::query()->where('key', 'evaluation_prompt_current')->value('value')));

        $this->put(route('settings.evaluation-prompts.update'), ['evaluation_prompt' => str_repeat('あ', 5001)])->assertSessionHasErrors('evaluation_prompt');
        $this->put(route('settings.evaluation-prompts.update'), ['evaluation_prompt' => ''])->assertSessionHasErrors('evaluation_prompt');
        $this->assertSame(5000, mb_strlen(SystemSetting::query()->where('key', 'evaluation_prompt_current')->value('value')));
    }
}
