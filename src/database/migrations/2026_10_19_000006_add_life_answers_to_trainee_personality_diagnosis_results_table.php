<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 愛犬性格診断の結果（D-1200）に、自由記述の質問 4 問を足す（2026-10-19 追加。要件定義書 6-15-18）。
     *
     * ユーザーレビューで、トレーナーさんの元の仕組み（Google フォーム）では 4 問は性格診断の一部だったので、愛犬アンケートから
     * 愛犬性格診断へ移し、診断のたびに答えてもらうことにした（診断した時点の自由記述の質問を残し、AI の下書きの材料を診断ごとにそろえる）。
     * 診断を送信したときに、その結果に保存する。どれも任意（NULL 可）。今までの結果は NULL のまま。
     * 愛犬アンケートの回答（D-1100）の同じ 4 列は、次のマイグレーション（2026_10_19_000007）で外す。
     */
    public function up(): void
    {
        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->text('wish')->nullable()->after('personality_type')->comment('暮らしの様子「こうなったらいいな」');
            $table->text('recent_events')->nullable()->after('wish')->comment('暮らしの様子「最近の様子」');
            $table->text('ideal_days')->nullable()->after('recent_events')->comment('暮らしの様子「理想の毎日」');
            $table->text('health_concerns')->nullable()->after('ideal_days')->comment('暮らしの様子「体のこと」');
        });
    }

    /**
     * 4 列を消す（入っていた答えは戻らない）。
     */
    public function down(): void
    {
        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->dropColumn(['wish', 'recent_events', 'ideal_days', 'health_concerns']);
        });
    }
};
