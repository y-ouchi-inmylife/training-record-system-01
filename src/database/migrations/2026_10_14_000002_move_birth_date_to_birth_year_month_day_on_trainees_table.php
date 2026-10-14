<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 既存の birth_date の値を、年・月・日の 3 列に移す（D-0700 注記「既存データの移行」）。
     *
     * 3 本に分けたうちの 2 本目。本番の反映は migrate --force で、お客様の確認用のデータが
     * 本番に残っているため、既存の値を確実に移す。birth_date が NULL の行は触らない。
     * MySQL の日付関数を使う（テストの DB〔SQLite〕ではマイグレーションを流さない）。
     */
    public function up(): void
    {
        DB::statement('UPDATE trainees SET birth_year = YEAR(birth_date), birth_month = MONTH(birth_date), birth_day = DAY(birth_date) WHERE birth_date IS NOT NULL');
    }

    /**
     * 3 列がそろっている行だけ birth_date に戻す。年だけ・年月だけの行は日付にできないため
     * birth_date を NULL にする（D-0700 注記）。3 列は 1 本目の down() で列ごと落とすので、
     * ここでは空にしない。
     */
    public function down(): void
    {
        DB::statement("UPDATE trainees SET birth_date = CASE WHEN birth_year IS NOT NULL AND birth_month IS NOT NULL AND birth_day IS NOT NULL THEN STR_TO_DATE(CONCAT(birth_year, '-', birth_month, '-', birth_day), '%Y-%c-%e') ELSE NULL END");
    }
};
