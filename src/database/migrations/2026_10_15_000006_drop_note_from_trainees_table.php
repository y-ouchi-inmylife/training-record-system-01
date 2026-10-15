<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * トレーニーの備考（note）を落とす（D-0700。2026-10 変更）。
     *
     * 受付アンケートの「暮らしの様子」で、飼い主さんの言葉で愛犬の様子を残せるようになったため、
     * 備考はなくした。既存の create_trainees_table は書き換えず、この追加のマイグレーションで消す
     * （本番は追加のマイグレーションで反映するため）。note には CHECK 制約もインデックスもない。
     */
    public function up(): void
    {
        Schema::table('trainees', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }

    /**
     * 元の定義（TEXT・NULL 許容・コメント「備考」）と位置（neuter_status の直後、photo_path の前）で note を戻す。
     * 消した中身は戻らない（すべて NULL になる）。
     */
    public function down(): void
    {
        Schema::table('trainees', function (Blueprint $table) {
            $table->text('note')->nullable()->after('neuter_status')->comment('備考');
        });
    }
};
