<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 値を年・月・日の 3 列に移したあとの birth_date を落とす（D-0700。2026-10 変更）。
     *
     * 3 本に分けたうちの 3 本目。birth_date には CHECK 制約もインデックスもない。
     */
    public function up(): void
    {
        Schema::table('trainees', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }

    /**
     * 元の位置（sex の直後）・コメントで birth_date を戻す。値は 2 本目の down() で戻す。
     */
    public function down(): void
    {
        Schema::table('trainees', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('sex')
                ->comment('誕生日。不明な場合があるため NULL 許容');
        });
    }
};
