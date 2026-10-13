<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 利用は 1 件＝1 回分にし、利用の回数（count）を持たない（D-1000 注記）。
     *
     * 順序：MySQL 8 では、1 つの列だけを参照する CHECK 制約は、その列を落とすと一緒に
     * 削除される（client_usages_count_check は count だけを参照。開発環境で確認済み）。
     * それでも、外す対象をはっきりさせるため、CHECK を先に明示して外してから列を外す。
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE client_usages DROP CHECK client_usages_count_check');

        Schema::table('client_usages', function (Blueprint $table) {
            $table->dropColumn('count');
        });
    }

    /**
     * 元の位置（used_on の直後）・名前で、count と CHECK 制約を戻す。
     */
    public function down(): void
    {
        Schema::table('client_usages', function (Blueprint $table) {
            $table->unsignedSmallInteger('count')->default(1)->after('used_on')
                ->comment('利用回数（1以上999以下）。1件で複数回分を登録できる');
        });

        // CHECK 制約（Laravel schema builder では表現できないため DB::statement で追加）
        DB::statement('ALTER TABLE client_usages ADD CONSTRAINT client_usages_count_check CHECK (count BETWEEN 1 AND 999)');
    }
};
