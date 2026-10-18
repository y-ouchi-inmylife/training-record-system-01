<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * トレーニング記録（D-0200）から、結び付けた愛犬性格診断の結果の列（2026_10_18_000004 で足したもの）を外す
     * （2026-10-18 変更。要件定義書 6-16-16）。評価文の公開でトレーニング記録を作らなくなり、使わなくなったため。
     * 000004 は書き換えない決まりなので、このマイグレーションで外す（外部キー・一意の索引ごと）。
     */
    public function up(): void
    {
        Schema::table('training_records', function (Blueprint $table) {
            $table->dropForeign('training_records_pd_result_id_foreign');
            $table->dropUnique('training_records_pd_result_id_unique');
            $table->dropColumn('trainee_personality_diagnosis_result_id');
        });
    }

    /**
     * 000004 の形（列・一意の索引・外部キー）に戻す。結び付きの値は戻らない（すべて NULL）。
     */
    public function down(): void
    {
        Schema::table('training_records', function (Blueprint $table) {
            $table->unsignedBigInteger('trainee_personality_diagnosis_result_id')->nullable()->after('impression')
                ->comment('結び付けた愛犬性格診断の結果のID（外部キー）。評価文を公開して作った記録だけ');

            $table->unique('trainee_personality_diagnosis_result_id', 'training_records_pd_result_id_unique');
            $table->foreign('trainee_personality_diagnosis_result_id', 'training_records_pd_result_id_foreign')
                ->references('id')->on('trainee_personality_diagnosis_results')->nullOnDelete();
        });
    }
};
