<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 愛犬性格診断の結果（D-1200）に、評価文（会員様向けの文章）と、最後に評価文を保存したトレーナー・日時を足す
     * （2026-10-18 追加。要件定義書 6-16-15）。既存の create のマイグレーションは書き換えず、この追加のマイグレーションで足す。
     *
     * - evaluation_text：評価文。空で保存したときは NULL（未作成）
     * - evaluation_updated_by：最後に評価文を保存したトレーナー。トレーナーを削除すると NULL（評価文は残す）
     * - evaluation_updated_at：最後に評価文を保存した日時（updated_at は採点の計算し直しでも変わるため別に持つ）
     *
     * 索引・外部キーの名前は MySQL の識別子の上限（64 文字）に収めるため `eval_by` に縮める（db-schema.md D-1200）
     */
    public function up(): void
    {
        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->text('evaluation_text')->nullable()->after('personality_type')
                ->comment('評価文（会員様向けの文章）。NULL は未作成');
            $table->unsignedBigInteger('evaluation_updated_by')->nullable()->after('evaluation_text')
                ->comment('最後に評価文を保存したトレーナーのID（外部キー）');
            $table->timestamp('evaluation_updated_at')->nullable()->after('evaluation_updated_by')
                ->comment('最後に評価文を保存した日時');

            $table->index('evaluation_updated_by', 'trainee_personality_diagnosis_results_eval_by_idx');
            $table->foreign('evaluation_updated_by', 'trainee_personality_diagnosis_results_eval_by_foreign')
                ->references('id')->on('trainers')->nullOnDelete();
        });
    }

    /**
     * 評価文の列を消す。書いてあった評価文は戻らない。
     */
    public function down(): void
    {
        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->dropForeign('trainee_personality_diagnosis_results_eval_by_foreign');
            $table->dropIndex('trainee_personality_diagnosis_results_eval_by_idx');
            $table->dropColumn(['evaluation_text', 'evaluation_updated_by', 'evaluation_updated_at']);
        });
    }
};
