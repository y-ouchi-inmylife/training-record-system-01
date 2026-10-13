<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 契約（client_contracts）・利用（client_usages）のテストで使うテーブルを作る。
 *
 * 本プロジェクトのマイグレーションは MySQL 専用の CHECK 制約を生 SQL で追加するため、
 * テスト用 SQLite では RefreshDatabase が使えない。そこで、テストが読み書きする列だけの
 * テーブルをテストの中で作る（CreatesRecordingTables と同じ事情）。
 */
trait CreatesClientCountTables
{
    protected function createClientsTable(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('internal_id')->default('');
            $table->date('initial_consultation_date')->nullable();
            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name_kana')->nullable();
            $table->string('first_name_kana')->nullable();
            $table->string('phone1')->nullable();
            $table->string('phone2')->nullable();
            $table->string('email')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('address3')->nullable();
            $table->string('address4')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('primary_trainer_id')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    protected function createClientCountTables(): void
    {
        Schema::create('client_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->date('contracted_on');
            $table->unsignedSmallInteger('count');
            $table->date('expires_on')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        Schema::create('client_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            // 割り当てた契約（NULL は「契約なし」）。本番と同じく、契約の削除で NULL に戻る外部キーを付ける。
            // SQLite は接続設定 foreign_key_constraints（config/database.php、既定 true）で外部キーが効く。
            $table->unsignedBigInteger('client_contract_id')->nullable();
            $table->foreign('client_contract_id')->references('id')->on('client_contracts')->nullOnDelete();
            $table->date('used_on');
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
    }

    protected function createAccessLogsTable(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainer_id');
            $table->string('action', 100);
            $table->string('target_type', 50)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
        });
    }
}
