<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * トレーニング記録（D-0200）に、結び付けた愛犬性格診断の結果（D-1200）の列を足す（2026-10-18 追加。要件定義書 6-16-16）。
     * 評価文を公開して作った記録だけに入る（ほかの記録は NULL）。既存の create のマイグレーションは書き換えない。
     *
     * - 一意：1 つの結果に記録は 1 件まで（NULL は何件あってもよい）
     * - 結果を削除すると NULL になる（記録は残す）。トレーニーの削除で結果が消えるときも同じ
     *
     * 索引・外部キーの名前は 64 文字に収めるため `pd_result_id` に縮める（db-schema.md D-0200）。
     * 一意の索引があるので、外部キーのための索引は別に作られない
     */
    public function up(): void
    {
        Schema::table('training_records', function (Blueprint $table) {
            $table->unsignedBigInteger('trainee_personality_diagnosis_result_id')->nullable()->after('impression')
                ->comment('結び付けた愛犬性格診断の結果のID（外部キー）。評価文を公開して作った記録だけ');

            $table->unique('trainee_personality_diagnosis_result_id', 'training_records_pd_result_id_unique');
            $table->foreign('trainee_personality_diagnosis_result_id', 'training_records_pd_result_id_foreign')
                ->references('id')->on('trainee_personality_diagnosis_results')->nullOnDelete();
        });
    }

    /**
     * 結び付けの列を消す。記録と結果の結び付きは戻らない（記録そのものは残る）。
     */
    public function down(): void
    {
        Schema::table('training_records', function (Blueprint $table) {
            $table->dropForeign('training_records_pd_result_id_foreign');
            $table->dropUnique('training_records_pd_result_id_unique');
            $table->dropColumn('trainee_personality_diagnosis_result_id');
        });
    }
};
