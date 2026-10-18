<?php

namespace Tests\Concerns;

/**
 * 愛犬アンケートの名前（2026-10-19 変更：ユーザーレビューで「受付アンケート」から「愛犬アンケート」にした）を確かめる。
 *
 * 名前は lang/ja/messages.php の intake_survey_name 1 か所から読む（愛犬性格診断の名前 AssertsPersonalityDiagnosisName と同じ作り）。
 */
trait AssertsIntakeSurveyName
{
    /** 画面に「愛犬アンケート」が出て、前の名前「受付アンケート」が出ない */
    protected function assertIntakeSurveyNameOnly(string $html, string $message = ''): void
    {
        $this->assertStringContainsString('愛犬アンケート', $html, $message);
        $this->assertStringNotContainsString('受付アンケート', $html, $message);
    }

    /** 名前を別の文字に差し替える（名前を 1 か所から読んでいることを確かめるため） */
    protected function overrideIntakeSurveyName(string $name): void
    {
        // 先に文のファイル（messages）を読み込んでおく。読み込む前に addLines すると、ファイルのほかの文が読まれなくなる
        app('translator')->load('*', 'messages', 'ja');
        app('translator')->addLines(['messages.intake_survey_name' => $name], 'ja');
    }
}
