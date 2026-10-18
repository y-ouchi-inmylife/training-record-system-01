<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 点数の列（列名 → コメント。元の create_trainee_personality_diagnosis_results_table と同じ） */
    private const SCORE_COLUMNS = [
        'score_people' => '対人（Q01〜Q08）の平均。未経験を除き、小数第2位に丸める',
        'score_dogs' => '対犬（Q09〜Q16）の平均',
        'score_possessiveness' => '所有欲（Q17〜Q21）の平均',
        'score_separation' => '分離不安（Q22〜Q28）の平均',
        'score_fear' => '恐怖心（Q29〜Q35）の平均',
        'score_trainability' => '訓練性能（Q36〜Q42）の平均',
        'score_excitability' => '興奮性（Q43〜Q50）の平均',
    ];

    /**
     * 愛犬性格診断の結果（D-1200）の点数の 7 列とタイプを NULL 可にする（2026-10-18 変更）。
     *
     * 全問「未経験」のカテゴリは点数なし（NULL）、全カテゴリが点数なしならタイプも判定なし（NULL）にした
     * （要件定義書 6-16「愛犬性格診断の採点とタイプの判定」）。既存の create_trainee_personality_diagnosis_results_table は
     * 書き換えず、この追加のマイグレーションで変える。CHECK 制約（タイプの値・点数の範囲）は NULL を違反にしないので、そのまま。
     * 保存済みの結果の計算し直しは、このあとで php artisan personality-diagnosis:rescore を流して行う（このマイグレーションでは値を変えない）
     */
    public function up(): void
    {
        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            foreach (self::SCORE_COLUMNS as $column => $comment) {
                $table->decimal($column, 3, 2)->nullable()->comment($comment.'。全問「未経験」なら NULL（点数なし）')->change();
            }
            $table->string('personality_type', 30)->nullable()->comment('タイプ（5-24.）。丸める前の平均から判定する。全カテゴリが点数なしなら NULL（判定なし）')->change();
        });
    }

    /**
     * NOT NULL に戻す。戻す前に、NULL を元の決まり（GAS と同じ）の値にする：点数なしは 0.00、判定なしは calm。
     * 元の決まりでも、全問「未経験」のカテゴリは 0、全問「未経験」のタイプは calm で、ほかの値は新しい決まりと同じなので、
     * これで元の決まりの値に戻る
     */
    public function down(): void
    {
        foreach (array_keys(self::SCORE_COLUMNS) as $column) {
            DB::table('trainee_personality_diagnosis_results')->whereNull($column)->update([$column => '0.00']);
        }
        DB::table('trainee_personality_diagnosis_results')->whereNull('personality_type')->update(['personality_type' => 'calm']);

        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            foreach (self::SCORE_COLUMNS as $column => $comment) {
                $table->decimal($column, 3, 2)->nullable(false)->comment($comment)->change();
            }
            $table->string('personality_type', 30)->nullable(false)->comment('タイプ（5-24.）。丸める前の平均から判定する')->change();
        });
    }
};
