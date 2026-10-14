<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 受付アンケートの回答（D-1100）の性別・去勢・避妊の値を、トレーニー（5-20.・5-21.）にそろえる（2026-10）。
     *
     * お客様の回答でトレーニーを上書きするようになったため、選択肢と値をトレーナー側にそろえる
     * （要件定義書 6-15-17 備考、db-schema.md D-1100 の注記「性別・去勢・避妊の値をトレーニーと同じにする」）。
     * - 性別：male / female に unknown（不明）を加える
     * - 去勢・避妊：dont_know（分からない）をやめ、unknown（不明）にする。dont_know の行は unknown に移す
     *
     * 本テーブルは本番には出ていないが開発環境では作ってあるため、既存の
     * 2026_10_15_000002_create_trainee_intake_survey_responses_table は書き換えず、追加のマイグレーションで直す
     * （db-schema.md D-0700 の注記「既存データの移行」と同じ考え方）。
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE trainee_intake_survey_responses DROP CHECK trainee_intake_survey_responses_sex_check');
        DB::statement('ALTER TABLE trainee_intake_survey_responses DROP CHECK trainee_intake_survey_responses_neuter_status_check');

        DB::table('trainee_intake_survey_responses')->where('neuter_status', 'dont_know')->update(['neuter_status' => 'unknown']);

        Schema::table('trainee_intake_survey_responses', function (Blueprint $table) {
            $table->string('sex', 20)->nullable()->comment('性別（5-20 参照）')->change();
            $table->string('neuter_status', 20)->nullable()->comment('去勢・避妊（5-21 参照）')->change();
        });

        DB::statement("ALTER TABLE trainee_intake_survey_responses ADD CONSTRAINT trainee_intake_survey_responses_sex_check CHECK (sex IN ('male', 'female', 'unknown'))");
        DB::statement("ALTER TABLE trainee_intake_survey_responses ADD CONSTRAINT trainee_intake_survey_responses_neuter_status_check CHECK (neuter_status IN ('neutered', 'not_neutered', 'unknown'))");
    }

    /**
     * 元の値の制限に戻す。unknown の行は、性別は NULL（元の制限に「不明」がないため未回答として扱う）に、
     * 去勢・避妊は dont_know に戻してから、元の CHECK 制約を付け直す。
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE trainee_intake_survey_responses DROP CHECK trainee_intake_survey_responses_sex_check');
        DB::statement('ALTER TABLE trainee_intake_survey_responses DROP CHECK trainee_intake_survey_responses_neuter_status_check');

        DB::table('trainee_intake_survey_responses')->where('sex', 'unknown')->update(['sex' => null]);
        DB::table('trainee_intake_survey_responses')->where('neuter_status', 'unknown')->update(['neuter_status' => 'dont_know']);

        Schema::table('trainee_intake_survey_responses', function (Blueprint $table) {
            $table->string('sex', 20)->nullable()->comment('性別（male / female のみ）')->change();
            $table->string('neuter_status', 20)->nullable()->comment('去勢・避妊（5-22 参照。分からないは dont_know）')->change();
        });

        DB::statement("ALTER TABLE trainee_intake_survey_responses ADD CONSTRAINT trainee_intake_survey_responses_sex_check CHECK (sex IN ('male', 'female'))");
        DB::statement("ALTER TABLE trainee_intake_survey_responses ADD CONSTRAINT trainee_intake_survey_responses_neuter_status_check CHECK (neuter_status IN ('neutered', 'not_neutered', 'dont_know'))");
    }
};
