<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 愛犬性格診断の結果（D-1200）に、スタッフ用メモ（staff_memo）を足す（2026-10-19 追加。要件定義書 6-16-19〔評価文の AI の下書き〕）。
     *
     * ユーザーレビューで、トレーナーさんの今の仕組み（Google スプレッドシート＋GAS＋Gemini）と同じく、AI で「お客様用レポート」と
     * 「スタッフ用メモ」を 1 回で作ることにした。スタッフ用メモはトレーナーだけが見るもので、会員様の画面には一切出さない。
     * NULL を許す（今までの結果は NULL のまま）。結果を削除すると行ごと消える（今の作りのまま）。
     */
    public function up(): void
    {
        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->text('staff_memo')->nullable()->after('evaluation_text')
                ->comment('スタッフ用メモ（トレーナーだけが見る。会員様には出さない）。NULL はなし');
        });
    }

    /**
     * スタッフ用メモの列を消す（入っていたメモは戻らない）。
     */
    public function down(): void
    {
        Schema::table('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->dropColumn('staff_memo');
        });
    }
};
