<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 愛犬性格診断の結果（D-1200）に、評価文を公開した日時と公開したトレーナーを足す
     * （2026-10-18 追加。要件定義書 6-16-16）。公開でトレーニング記録を作らず、評価文そのものに「公開済み」の印を付ける。
     *
     * - evaluation_published_at：公開した日時。NULL でなければ公開済み。会員様の記録の一覧では、この日時を評価文の日付として並べる
     * - evaluation_published_by：公開したトレーナー。トレーナーを削除すると NULL（公開は続ける）
     * - 公開をやめると、どちらも NULL に戻す
     *
     * 索引・外部キーの名前は MySQL の識別子の上限（64 文字）に収めるため `pub_by`・`pub_at` に縮める（db-schema.md D-1200）
     */
    public function up(): void
    {
        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->timestamp('evaluation_published_at')->nullable()->after('evaluation_updated_at')
                ->comment('評価文を公開した日時。NULL は公開していない');
            $table->unsignedBigInteger('evaluation_published_by')->nullable()->after('evaluation_published_at')
                ->comment('評価文を公開したトレーナーのID（外部キー）');

            $table->index('evaluation_published_by', 'trainee_personality_diagnosis_results_pub_by_idx');
            // 会員の公開済みの評価文を、公開した日時の順に取るため（会員詳細・マイページ）
            $table->index(['trainee_id', 'evaluation_published_at'], 'trainee_personality_diagnosis_results_pub_at_idx');
            $table->foreign('evaluation_published_by', 'trainee_personality_diagnosis_results_pub_by_foreign')
                ->references('id')->on('trainers')->nullOnDelete();
        });
    }

    /**
     * 公開の列を消す。公開していた評価文は、すべて公開していない（下書き）扱いに戻る。
     */
    public function down(): void
    {
        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->dropForeign('trainee_personality_diagnosis_results_pub_by_foreign');
            $table->dropIndex('trainee_personality_diagnosis_results_pub_by_idx');
            $table->dropIndex('trainee_personality_diagnosis_results_pub_at_idx');
            $table->dropColumn(['evaluation_published_at', 'evaluation_published_by']);
        });
    }
};
