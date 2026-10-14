<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * トレーニーの名前を任意（NULL 許容）にする（D-0700。2026-10 変更）。
     * 受付アンケートの案内だけを先に発行する運用のため、名前を入れずに登録できるようにする。
     *
     * ->change() は Laravel 12 では元の修飾子（コメントなど）を引き継がないため、コメントも書き直す。
     */
    public function up(): void
    {
        Schema::table('trainees', function (Blueprint $table) {
            $table->string('name', 50)->nullable()->change()
                ->comment('名前。任意（未登録は NULL）。犬の名前はひらがな・カタカナが多く、よみ（かな）は持たない');
        });
    }

    /**
     * 名前が未登録（NULL）の行を空文字で埋めてから NOT NULL に戻す（D-0700 注記「既存データの移行」）。
     * 戻すのは開発中の作業がほとんどで、失敗して止まるより戻せる方が扱いやすいため。
     */
    public function down(): void
    {
        DB::table('trainees')->whereNull('name')->update(['name' => '']);

        Schema::table('trainees', function (Blueprint $table) {
            $table->string('name', 50)->nullable(false)->change()
                ->comment('名前。犬の名前はひらがな・カタカナが多く、よみ（かな）は持たない');
        });
    }
};
