<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 性格診断用 URL のトークン（DS-1100。2026-10 追加）。
     *
     * トレーナーがトレーニーごとに発行し、お客様はこの URL から性格診断に回答する。
     * 受付アンケート用 URL のトークン（DS-1000）と同じ作り。有効期限は持たない（回答の送信で使い切り、
     * 放置されたものはトレーナーが取り消す）。使用済みかどうかは answered_at の有無で判定する（is_used は持たない）。
     * 受付アンケートと違い、前の結果があっても次の案内を発行できる（何回でも受けられる）。
     */
    public function up(): void
    {
        Schema::create('trainee_personality_diagnosis_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique('trainee_personality_diagnosis_tokens_token_unique')
                ->comment('Str::random(32) で生成された 32 文字のランダム英数字（URL に埋め込む）');
            $table->unsignedBigInteger('trainee_id')->comment('性格診断の対象のトレーニーのID（外部キー）');
            $table->timestamp('answered_at')->nullable()->comment('回答日時。NULL は未回答（回答待ち）、値ありは回答済み（使用済み）');
            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable()->comment('トークンを発行したトレーナーのID（外部キー）');

            // インデックス
            $table->index('trainee_id', 'trainee_personality_diagnosis_tokens_trainee_id_idx');
            $table->index('created_by', 'trainee_personality_diagnosis_tokens_created_by_idx');

            // 外部キー
            // トレーニーを削除するとトークンも削除する（特定トレーニー専用のトークンのため）
            $table->foreign('trainee_id', 'trainee_personality_diagnosis_tokens_trainee_id_foreign')
                ->references('id')->on('trainees')->cascadeOnDelete();
            // 発行者のトレーナーを削除したら NULL にする
            $table->foreign('created_by', 'trainee_personality_diagnosis_tokens_created_by_foreign')
                ->references('id')->on('trainers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainee_personality_diagnosis_tokens');
    }
};
