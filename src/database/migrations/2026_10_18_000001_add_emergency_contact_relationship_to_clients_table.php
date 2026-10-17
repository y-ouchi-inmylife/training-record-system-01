<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 会員に、緊急連絡先の方との続柄（emergency_contact_relationship）を足す（D-0100。2026-10-17 変更）。
     *
     * ユーザーレビューで、2 つ目の電話番号「電話番号（予備）」（phone2）を「緊急連絡先（ご家族等）」にし、
     * その続柄を入れる欄を足した。phone2 は列の名前を変えずにそのまま使う（本番のデータの移し替えと参照の直しを
     * 増やさないため）。既存の create_clients_table は書き換えず、この追加のマイグレーションで足す。
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('emergency_contact_relationship', 20)->nullable()->after('phone2')->comment('緊急連絡先の続柄');
        });
    }

    /**
     * 続柄の列を消す。入っていた続柄は戻らない。
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('emergency_contact_relationship');
        });
    }
};
