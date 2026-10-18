<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 愛犬アンケート用 URL（DS-1000）と愛犬性格診断用 URL（DS-1100）のトークンに、有効期限（expires_at）を足す
     * （2026-10-19 変更。architecture.md §3-1）。
     *
     * ユーザーレビューで、期限がないとお客様が答えるのを忘れてしまうので、マイページ登録のご案内と同じく 1 週間の
     * 有効期限（発行した日から 7 日後の日の終わり）を付けることにした。
     * NULL を許す：すでに発行した案内（このマイグレーションより前のもの）は期限なし（NULL）のままにする
     * （すでにお客様に「期限なし」で送ったリンクが急に使えなくならないように）。値は入れない。
     * 既存の create_*_tokens_table は書き換えず、この追加のマイグレーションで足す。
     */
    public function up(): void
    {
        Schema::table('trainee_intake_survey_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('trainee_id')
                ->comment('有効期限（発行した日から7日後の日の終わり）。NULL は期限なし（期限を付ける前に発行した案内）');
        });
        Schema::table('trainee_personality_diagnosis_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('trainee_id')
                ->comment('有効期限（発行した日から7日後の日の終わり）。NULL は期限なし（期限を付ける前に発行した案内）');
        });
    }

    /**
     * 有効期限の列を消す。入っていた期限は戻らない（どの案内も期限なしに戻る）。
     */
    public function down(): void
    {
        Schema::table('trainee_intake_survey_tokens', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
        Schema::table('trainee_personality_diagnosis_tokens', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
