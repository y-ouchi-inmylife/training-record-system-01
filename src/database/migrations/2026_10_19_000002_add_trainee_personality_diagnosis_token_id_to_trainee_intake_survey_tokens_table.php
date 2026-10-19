<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 愛犬アンケート用 URL のトークン（DS-1000）に、続けて答える愛犬性格診断の案内（DS-1100）の列を足す
     * （2026-10-19 追加。「愛犬アンケート＆愛犬性格診断」の案内。要件定義書 6-16-8・6-16-11）。
     *
     * ユーザーレビューで、運用上 2 つを同時に送りたいことがあるため、2 つの案内を発行のときに同時に作って結び付け、
     * 1 つのリンク（愛犬アンケートの案内のリンク）で 愛犬アンケート → 愛犬性格診断 の順に続けて答えてもらうことにした。
     * - NULL を許す：単独の愛犬アンケートの案内（今までの案内を含む）は NULL のまま。値は入れない
     * - 相手（愛犬性格診断の案内）が消えたら NULL にする（nullOnDelete。取消・トレーニーの削除で消える）
     * 既存の create_trainee_intake_survey_tokens_table は書き換えず、この追加のマイグレーションで足す。
     */
    public function up(): void
    {
        Schema::table('trainee_intake_survey_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('trainee_personality_diagnosis_token_id')->nullable()->after('trainee_id')
                ->comment('続けて答える愛犬性格診断の案内（愛犬アンケート＆愛犬性格診断の案内のとき）。NULL は単独の愛犬アンケートの案内');
            // 索引・外部キーの名前は短く付ける（自動で付く名前は MySQL の上限 64 文字を超えるため。
            // 愛犬性格診断の結果の表〔2026_10_15_000005〕と同じやり方）
            $table->index('trainee_personality_diagnosis_token_id', 'trainee_intake_survey_tokens_pd_token_id_idx');
            $table->foreign('trainee_personality_diagnosis_token_id', 'trainee_intake_survey_tokens_pd_token_id_foreign')
                ->references('id')->on('trainee_personality_diagnosis_tokens')->nullOnDelete();
        });
    }

    /**
     * 結び付けの列を消す。組み合わせで発行した案内は、2 つの単独の案内として残る（続けて進まなくなる）。
     */
    public function down(): void
    {
        Schema::table('trainee_intake_survey_tokens', function (Blueprint $table) {
            $table->dropForeign('trainee_intake_survey_tokens_pd_token_id_foreign');
            $table->dropIndex('trainee_intake_survey_tokens_pd_token_id_idx');
            $table->dropColumn('trainee_personality_diagnosis_token_id');
        });
    }
};
