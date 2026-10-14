<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 受付アンケートの回答（D-1100。2026-10 追加）。
     *
     * お客様が受付アンケートで答えた内容を、トレーニーの属性とは別に「答えたとおり」に残す
     * （トレーニーには空欄の項目だけ反映する。要件定義書 6-15-17）。1 頭に複数件持てる
     * （ユニーク制約を付けない。第1段階では 1 件）。お客様の操作で作られるため、
     * created_by / updated_by は持たない。
     */
    public function up(): void
    {
        Schema::create('trainee_intake_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id')->comment('回答の対象のトレーニーのID（外部キー）');
            $table->unsignedBigInteger('trainee_intake_survey_token_id')->nullable()
                ->comment('回答に使った受付アンケート用 URL のトークンのID（外部キー）');
            $table->timestamp('answered_at')->comment('回答日時。回答の記録を兼ねる（お客様の回答はアクセスログに記録しない）');
            $table->string('name', 50)->nullable()->comment('名前。登録済みのときは回答時点のトレーニーの名前');
            $table->string('breed', 100)->nullable()->comment('犬種（答えたとおり）');
            $table->string('sex', 20)->nullable()->comment('性別（male / female のみ）');
            $table->unsignedSmallInteger('birth_year')->nullable()->comment('誕生日の年');
            $table->unsignedTinyInteger('birth_month')->nullable()->comment('誕生日の月（1〜12）。入れる場合は年が必須');
            $table->unsignedTinyInteger('birth_day')->nullable()->comment('誕生日の日（1〜31）。入れる場合は年・月が必須');
            $table->string('neuter_status', 20)->nullable()->comment('去勢・避妊（5-22 参照。分からないは dont_know）');
            $table->text('wish')->nullable()->comment('自由記述「こうなったらいいな」');
            $table->text('recent_events')->nullable()->comment('自由記述「最近の様子」');
            $table->text('ideal_days')->nullable()->comment('自由記述「理想の毎日」');
            $table->text('health_concerns')->nullable()->comment('自由記述「体のこと」');
            $table->timestamps();

            // インデックス（トークンの方は MySQL の識別子の上限 64 文字に収めるため短い名前。db-schema.md D-1100）
            $table->index('trainee_id', 'trainee_intake_survey_responses_trainee_id_idx');
            $table->index('trainee_intake_survey_token_id', 'trainee_intake_survey_responses_token_id_idx');

            // 外部キー
            // トレーニーを削除すると回答も削除する
            $table->foreign('trainee_id', 'trainee_intake_survey_responses_trainee_id_foreign')
                ->references('id')->on('trainees')->cascadeOnDelete();
            // トークンが削除されても回答は残す（回答済みのトークンは取消できないため、通常は消えない）
            $table->foreign('trainee_intake_survey_token_id', 'trainee_intake_survey_responses_token_id_foreign')
                ->references('id')->on('trainee_intake_survey_tokens')->nullOnDelete();
        });

        // CHECK 制約（Laravel schema builder では表現できないため DB::statement で追加）
        DB::statement("ALTER TABLE trainee_intake_survey_responses ADD CONSTRAINT trainee_intake_survey_responses_sex_check CHECK (sex IN ('male', 'female'))");
        DB::statement('ALTER TABLE trainee_intake_survey_responses ADD CONSTRAINT trainee_intake_survey_responses_birth_month_check CHECK (birth_month IS NULL OR (birth_year IS NOT NULL AND birth_month BETWEEN 1 AND 12))');
        DB::statement('ALTER TABLE trainee_intake_survey_responses ADD CONSTRAINT trainee_intake_survey_responses_birth_day_check CHECK (birth_day IS NULL OR (birth_month IS NOT NULL AND birth_day BETWEEN 1 AND 31))');
        DB::statement("ALTER TABLE trainee_intake_survey_responses ADD CONSTRAINT trainee_intake_survey_responses_neuter_status_check CHECK (neuter_status IN ('neutered', 'not_neutered', 'dont_know'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('trainee_intake_survey_responses');
    }
};
