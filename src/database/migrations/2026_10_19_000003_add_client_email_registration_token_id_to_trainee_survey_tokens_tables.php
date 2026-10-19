<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 愛犬アンケート用 URL のトークン（DS-1000）と愛犬性格診断用 URL のトークン（DS-1100）に、回答を終えた完了の画面で
     * 誘導するマイページ登録の案内（DS-0700 client_email_registration_tokens）の列を足す（2026-10-19 追加。要件定義書 6-16-18）。
     *
     * ユーザーレビューで、回答のついでにマイページの登録にも進んでもらえるよう、案内の発行のときにマイページ登録の案内も
     * 一緒に用意できるようにした。お客様が最後に答える案内に持たせる（単独の案内はその案内、愛犬アンケート＆愛犬性格診断は
     * 愛犬性格診断の案内）。
     * - NULL を許す：マイページ登録の案内を付けずに発行した案内（今までの案内を含む）は NULL のまま
     * - 相手（マイページ登録の案内）が消えたら NULL にする（nullOnDelete。発行し直し・取消・会員の削除で消える）
     * - 索引・外部キーの名前は短く付ける（自動で付く名前は MySQL の上限 64 文字を超えるため）
     */
    public function up(): void
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

    /**
     * 結び付けの列を消す。マイページ登録の案内そのものは残る（完了の画面で誘導しなくなるだけ）。
     */
    public function down(): void
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
};
