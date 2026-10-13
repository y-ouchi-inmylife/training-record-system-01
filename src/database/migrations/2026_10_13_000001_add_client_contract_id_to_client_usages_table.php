<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_usages', function (Blueprint $table) {
            // 割り当てた契約（D-1000）。NULL は「契約なし」（未割り当て）。
            // 利用と同じ会員の契約に限る点は DB の制約としては持たず、アプリ側でチェックする。
            $table->unsignedBigInteger('client_contract_id')->nullable()->after('client_id')
                ->comment('割り当てた契約のID（外部キー）。NULL は「契約なし」（未割り当て）');

            // 外部キー
            // 契約を削除すると、その契約に割り当てられていた利用は「契約なし」（NULL）に戻る。
            // 利用そのものは消さない（D-1000 制約・注記参照）。
            // インデックス client_usages_client_contract_id_foreign は外部キーに伴い自動付与される。
            $table->foreign('client_contract_id', 'client_usages_client_contract_id_foreign')
                ->references('id')->on('client_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_usages', function (Blueprint $table) {
            $table->dropForeign('client_usages_client_contract_id_foreign');
            // MySQL では外部キーに伴って自動付与されたインデックスが残るため、明示して外す
            $table->dropIndex('client_usages_client_contract_id_foreign');
            $table->dropColumn('client_contract_id');
        });
    }
};
