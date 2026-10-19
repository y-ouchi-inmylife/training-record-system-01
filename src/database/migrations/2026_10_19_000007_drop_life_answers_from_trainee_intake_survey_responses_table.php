<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 愛犬アンケートの回答（D-1100）から、自由記述の質問 4 列を外す（2026-10-19 変更。要件定義書 6-15-17）。
     *
     * 4 問を愛犬性格診断へ移した（前のマイグレーション 2026_10_19_000006）。愛犬アンケートは基本情報（名前・犬種・性別・誕生日・
     * 去勢・避妊）だけになる。ユーザーの判断で、今ある回答の 4 問の答えは消す（愛犬性格診断の結果には移さない）。
     */
    public function up(): void
    {
        Schema::table('trainee_intake_survey_responses', function (Blueprint $table) {
            $table->dropColumn(['wish', 'recent_events', 'ideal_days', 'health_concerns']);
        });
    }

    /**
     * 4 列を足し直す（2026_10_15_000002 と同じ形。入っていた答えは戻らない〔どの回答も NULL〕）。
     */
    public function down(): void
    {
        Schema::table('trainee_intake_survey_responses', function (Blueprint $table) {
            $table->text('wish')->nullable()->after('neuter_status')->comment('自由記述「こうなったらいいな」');
            $table->text('recent_events')->nullable()->after('wish')->comment('自由記述「最近の様子」');
            $table->text('ideal_days')->nullable()->after('recent_events')->comment('自由記述「理想の毎日」');
            $table->text('health_concerns')->nullable()->after('ideal_days')->comment('自由記述「体のこと」');
        });
    }
};
