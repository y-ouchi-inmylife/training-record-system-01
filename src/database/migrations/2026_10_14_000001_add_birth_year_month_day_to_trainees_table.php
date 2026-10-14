<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 誕生日を年・月・日の 3 列で持つ（D-0700。2026-10 変更）。分かるところだけ入れる。
     *
     * 3 本に分けたうちの 1 本目（列の追加 → 値の移行 → birth_date の削除）。MySQL では構造の
     * 変更が途中で失敗するとそこまでの変更が残るため、段階ごとに分けて戻しやすくする。
     * この時点では birth_date も残っている（値は 2 本目で移す）。
     */
    public function up(): void
    {
        Schema::table('trainees', function (Blueprint $table) {
            $table->unsignedSmallInteger('birth_year')->nullable()->after('birth_date')
                ->comment('誕生日の年。分かるところだけ入れる');
            $table->unsignedTinyInteger('birth_month')->nullable()->after('birth_year')
                ->comment('誕生日の月（1〜12）。入れる場合は年が必須');
            $table->unsignedTinyInteger('birth_day')->nullable()->after('birth_month')
                ->comment('誕生日の日（1〜31）。入れる場合は年・月が必須');
        });

        // CHECK 制約（Laravel schema builder では表現できないため DB::statement で追加）。
        // 「実在する日付」「未来でない」「今年から30年前まで」は CHECK で書けないため、
        // アプリ側（TraineeRequest）で検証する（db-schema.md §3-3 の例外）。
        DB::statement('ALTER TABLE trainees ADD CONSTRAINT trainees_birth_month_check CHECK (birth_month IS NULL OR (birth_year IS NOT NULL AND birth_month BETWEEN 1 AND 12))');
        DB::statement('ALTER TABLE trainees ADD CONSTRAINT trainees_birth_day_check CHECK (birth_day IS NULL OR (birth_month IS NOT NULL AND birth_day BETWEEN 1 AND 31))');
    }

    /**
     * CHECK 制約を外してから 3 列を落とす。
     *
     * 2 つの CHECK 制約は複数の列（birth_year と birth_month、birth_month と birth_day）を
     * 参照するため、列を落とすだけでは消えず、そのままでは列を落とせない。先に明示して外す。
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE trainees DROP CHECK trainees_birth_day_check');
        DB::statement('ALTER TABLE trainees DROP CHECK trainees_birth_month_check');

        Schema::table('trainees', function (Blueprint $table) {
            $table->dropColumn(['birth_year', 'birth_month', 'birth_day']);
        });
    }
};
