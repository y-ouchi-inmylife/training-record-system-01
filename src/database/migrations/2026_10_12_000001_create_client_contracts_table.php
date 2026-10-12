<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->comment('会員のID（外部キー）');
            $table->date('contracted_on')->comment('契約日');
            $table->unsignedSmallInteger('count')->comment('契約回数（1以上999以下）');
            $table->date('expires_on')->nullable()->comment('有効期限（表示のみ。残り回数の計算・割り当てには使わない）');
            $table->string('note', 255)->nullable()->comment('メモ（「4回コース」など）');
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();

            // インデックス
            // client_id の外部キーもこの複合インデックス（先頭が client_id）を使う
            $table->index(['client_id', 'contracted_on'], 'client_contracts_client_id_contracted_on_idx');
            $table->index('updated_by', 'client_contracts_updated_by_foreign');

            // 外部キー
            // 会員削除時は契約も削除する（アプリ側でも会員削除を拒否しない。D-0900 注記参照）
            $table->foreign('client_id', 'client_contracts_client_id_foreign')
                ->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('updated_by', 'client_contracts_updated_by_foreign')
                ->references('id')->on('trainers')->nullOnDelete();
        });

        // CHECK 制約（Laravel schema builder では表現できないため DB::statement で追加）
        DB::statement('ALTER TABLE client_contracts ADD CONSTRAINT client_contracts_count_check CHECK (count BETWEEN 1 AND 999)');
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contracts');
    }
};
