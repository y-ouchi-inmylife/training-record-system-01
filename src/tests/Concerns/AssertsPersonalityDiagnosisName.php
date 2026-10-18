<?php

namespace Tests\Concerns;

/**
 * 愛犬性格診断の名前（2026-10-18 変更：正式名称「愛犬性格診断」に決まった。その前は「性格診断」）を確かめる。
 *
 * 画面に「愛犬性格診断」が出て、「性格診断」が単独では出ない（「愛犬性格診断」の一部としてだけ出る）こと。
 * 名前は lang/ja/messages.php の personality_diagnosis_name 1 か所から読む。
 */
trait AssertsPersonalityDiagnosisName
{
    protected function assertOnlyFullPersonalityDiagnosisName(string $html, string $message = ''): void
    {
        $this->assertStringContainsString('愛犬性格診断', $html, $message);
        $this->assertSame(
            substr_count($html, '愛犬性格診断'),
            substr_count($html, '性格診断'),
            '「性格診断」が単独で出ている'.($message !== '' ? '：'.$message : '')
        );
    }

    /** 名前を別の文字に差し替える（名前を 1 か所から読んでいることを確かめるため） */
    protected function overridePersonalityDiagnosisName(string $name): void
    {
        app('translator')->addLines(['messages.personality_diagnosis_name' => $name], 'ja');
    }
}
