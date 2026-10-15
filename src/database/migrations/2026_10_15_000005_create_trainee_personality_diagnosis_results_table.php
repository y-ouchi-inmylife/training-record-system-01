<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 性格診断の結果（D-1200。2026-10 追加）。
     *
     * お客様が性格診断（S-1415）で答えた 50 問の回答と、その採点の結果（7 カテゴリの平均・タイプ）。
     * 1 頭に何件でも持てる（ユニーク制約を付けない）。50 問の回答は JSON の列 1 つに送られてきたとおりに残し
     * （採点の決まりが変わっても計算し直せるように）、一覧と比べる表示によく使う 7 カテゴリの平均とタイプは別の列に持つ。
     * お客様の操作で作られるため、created_by / updated_by は持たない（受付アンケートの回答と同じ）。
     * 「公開」と「AI のレポート」の列は今回は作らない（次の段階で足す。db-schema.md D-1200 の注記）。
     */
    public function up(): void
    {
        Schema::create('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id')->comment('回答の対象のトレーニーのID（外部キー）');
            $table->unsignedBigInteger('trainee_personality_diagnosis_token_id')->nullable()
                ->comment('回答に使った性格診断用 URL のトークンのID（外部キー）');
            $table->timestamp('answered_at')->comment('回答日時。回答の記録を兼ねる（お客様の回答はアクセスログに記録しない）');
            $table->string('question_version', 10)->comment('回答に使った設問の版（今は v1）');
            $table->json('answers')->comment('50 問の回答。キーは Q01〜Q50、値は 0〜4 の整数または未経験を表す "na"');
            $table->decimal('score_people', 3, 2)->comment('対人（Q01〜Q08）の平均。未経験を除き、小数第2位に丸める');
            $table->decimal('score_dogs', 3, 2)->comment('対犬（Q09〜Q16）の平均');
            $table->decimal('score_possessiveness', 3, 2)->comment('所有欲（Q17〜Q21）の平均');
            $table->decimal('score_separation', 3, 2)->comment('分離不安（Q22〜Q28）の平均');
            $table->decimal('score_fear', 3, 2)->comment('恐怖心（Q29〜Q35）の平均');
            $table->decimal('score_trainability', 3, 2)->comment('訓練性能（Q36〜Q42）の平均');
            $table->decimal('score_excitability', 3, 2)->comment('興奮性（Q43〜Q50）の平均');
            $table->string('personality_type', 30)->comment('タイプ（5-24.）。丸める前の平均から判定する');
            $table->timestamps();

            // インデックス（トークンの方は MySQL の識別子の上限 64 文字に収めるため短い名前。db-schema.md D-1200）
            $table->index('trainee_id', 'trainee_personality_diagnosis_results_trainee_id_idx');
            $table->index('trainee_personality_diagnosis_token_id', 'trainee_personality_diagnosis_results_token_id_idx');

            // 外部キー
            // トレーニーを削除すると結果も削除する
            $table->foreign('trainee_id', 'trainee_personality_diagnosis_results_trainee_id_foreign')
                ->references('id')->on('trainees')->cascadeOnDelete();
            // トークンが削除されても結果は残す（回答済みのトークンは取消できないため、通常は消えない）
            $table->foreign('trainee_personality_diagnosis_token_id', 'trainee_personality_diagnosis_results_token_id_foreign')
                ->references('id')->on('trainee_personality_diagnosis_tokens')->nullOnDelete();
        });

        // CHECK 制約（Laravel schema builder では表現できないため DB::statement で追加）
        DB::statement("ALTER TABLE trainee_personality_diagnosis_results ADD CONSTRAINT trainee_personality_diagnosis_results_type_check CHECK (personality_type IN ('guardian', 'affectionate', 'sensitive', 'smart', 'energetic', 'calm'))");
        DB::statement('ALTER TABLE trainee_personality_diagnosis_results ADD CONSTRAINT trainee_personality_diagnosis_results_scores_check CHECK ('
            .'score_people BETWEEN 0 AND 4 AND score_dogs BETWEEN 0 AND 4 AND score_possessiveness BETWEEN 0 AND 4 '
            .'AND score_separation BETWEEN 0 AND 4 AND score_fear BETWEEN 0 AND 4 AND score_trainability BETWEEN 0 AND 4 '
            .'AND score_excitability BETWEEN 0 AND 4)');
    }

    public function down(): void
    {
        Schema::dropIfExists('trainee_personality_diagnosis_results');
    }
};
