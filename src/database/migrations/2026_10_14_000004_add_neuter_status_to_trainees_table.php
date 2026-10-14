<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 去勢・避妊（neuter_status）を加える（D-0700・5-21。2026-10 追加）。
     *
     * 位置は db-schema.md D-0700 のカラムの並び（誕生日の 3 列の後ろ）に合わせる。
     */
    public function up(): void
    {
        Schema::table('trainees', function (Blueprint $table) {
            $table->string('neuter_status', 20)->nullable()->after('birth_day')
                ->comment('去勢・避妊（5-21 参照）。NULL は未登録');
        });

        // CHECK 制約（Laravel schema builder では表現できないため DB::statement で追加）
        DB::statement("ALTER TABLE trainees ADD CONSTRAINT trainees_neuter_status_check CHECK (neuter_status IN ('neutered', 'not_neutered', 'unknown'))");
    }

    /**
     * CHECK 制約を外してから列を落とす。
     *
     * 1 つの列だけを参照する CHECK 制約は列と一緒に消えるが、外す対象をはっきりさせるため
     * 先に明示して外す（2026_10_13_000002 と同じ）。
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE trainees DROP CHECK trainees_neuter_status_check');

        Schema::table('trainees', function (Blueprint $table) {
            $table->dropColumn('neuter_status');
        });
    }
};
