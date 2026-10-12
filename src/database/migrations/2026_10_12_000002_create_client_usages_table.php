<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->comment('会員のID（外部キー）');
            $table->date('used_on')->comment('利用日');
            $table->unsignedSmallInteger('count')->default(1)->comment('利用回数（1以上999以下）。1件で複数回分を登録できる');
            $table->string('note', 255)->nullable()->comment('メモ（「当日キャンセル」など）');
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();

            // インデックス
            // client_id の外部キーもこの複合インデックス（先頭が client_id）を使う
            $table->index(['client_id', 'used_on'], 'client_usages_client_id_used_on_idx');
            $table->index('updated_by', 'client_usages_updated_by_foreign');

            // 外部キー
            // 会員削除時は利用も削除する（アプリ側でも会員削除を拒否しない。D-0900 注記参照）
            $table->foreign('client_id', 'client_usages_client_id_foreign')
                ->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('updated_by', 'client_usages_updated_by_foreign')
                ->references('id')->on('trainers')->nullOnDelete();
        });

        // CHECK 制約（Laravel schema builder では表現できないため DB::statement で追加）
        DB::statement('ALTER TABLE client_usages ADD CONSTRAINT client_usages_count_check CHECK (count BETWEEN 1 AND 999)');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_usages');
    }
};
