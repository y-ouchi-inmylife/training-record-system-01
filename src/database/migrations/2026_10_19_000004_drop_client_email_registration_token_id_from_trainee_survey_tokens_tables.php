<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 愛犬アンケート用 URL のトークン（DS-1000）と愛犬性格診断用 URL のトークン（DS-1100）から、マイページ登録の案内の結び付け
     * （client_email_registration_token_id。2026_10_19_000003 で足した）を外す（2026-10-19 変更。要件定義書 6-16-18）。
     *
     * ユーザーレビューで、完了の画面のマイページ登録へのお誘いは、案内に結び付けたマイページ登録の案内ではなく、答えた会員に
     * 有効なマイページ登録の案内があるかで出すことにしたため、結び付けは要らなくなった。000003 は書き換えず、この追加のマイグレーションで外す
     * （本番では 000003〔足す〕→ この 000004〔外す〕の順に流れる）。外部キー → 索引 → 列の順に外す。
     */
    public function up(): void
    {
        Schema::table('trainee_intake_survey_tokens', function (Blueprint $table) {
            $table->dropForeign('trainee_intake_survey_tokens_email_reg_token_id_foreign');
            $table->dropIndex('trainee_intake_survey_tokens_email_reg_token_id_idx');
            $table->dropColumn('client_email_registration_token_id');
        });
        Schema::table('trainee_personality_diagnosis_tokens', function (Blueprint $table) {
            $table->dropForeign('trainee_pd_tokens_email_reg_token_id_foreign');
            $table->dropIndex('trainee_pd_tokens_email_reg_token_id_idx');
            $table->dropColumn('client_email_registration_token_id');
        });
    }

    /**
     * 000003 の形に戻す（列・索引・外部キー。値は戻らない〔どの案内も結び付けなしの NULL〕）。
     */
    public function down(): void
    {
        Schema::table('trainee_intake_survey_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('client_email_registration_token_id')->nullable()->after('trainee_personality_diagnosis_token_id')
                ->comment('回答を終えた完了の画面で誘導するマイページ登録の案内。NULL は誘導しない');
            $table->index('client_email_registration_token_id', 'trainee_intake_survey_tokens_email_reg_token_id_idx');
            $table->foreign('client_email_registration_token_id', 'trainee_intake_survey_tokens_email_reg_token_id_foreign')
                ->references('id')->on('client_email_registration_tokens')->nullOnDelete();
        });
        Schema::table('trainee_personality_diagnosis_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('client_email_registration_token_id')->nullable()->after('trainee_id')
                ->comment('回答を終えた完了の画面で誘導するマイページ登録の案内。NULL は誘導しない');
            $table->index('client_email_registration_token_id', 'trainee_pd_tokens_email_reg_token_id_idx');
            $table->foreign('client_email_registration_token_id', 'trainee_pd_tokens_email_reg_token_id_foreign')
                ->references('id')->on('client_email_registration_tokens')->nullOnDelete();
        });
    }
};
