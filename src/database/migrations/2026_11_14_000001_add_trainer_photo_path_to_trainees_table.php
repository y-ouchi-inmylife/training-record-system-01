<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * トレーナー用の写真の保存キー（trainer_photo_path）を足す（D-0700。2026-11 追加。要件定義書 6-16-20）。
     *
     * トレーナーがメディア一覧から選んだ写真をコピーして作った、長辺 400px の JPEG 1 枚のキー
     * （trainee-trainer-photos/YYYYMM/{uuid}.jpg）。未設定は NULL。元のメディアの ID は持たない。
     * 既存の create_trainees_table は書き換えず、この追加のマイグレーションで足す
     * （本番は migrate --force で反映するため。設計書 D-0700「カラムの追加」）。
     */
    public function up(): void
    {
        Schema::table('trainees', function (Blueprint $table) {
            $table->string('trainer_photo_path', 500)->nullable()->after('photo_path')
                ->comment('トレーナー用の写真の保存パス（キー）。未設定は NULL。リサイズ済み JPEG 1 枚のみ。会員側には出さない');
        });
    }

    /**
     * 列を消す。設定済みのトレーナー用の写真のキーは失われる（ストレージのファイルは残る）。
     */
    public function down(): void
    {
        Schema::table('trainees', function (Blueprint $table) {
            $table->dropColumn('trainer_photo_path');
        });
    }
};
