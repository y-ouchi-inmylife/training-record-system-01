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
            $table->string('emergency_contact_relationship')->nullable();
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

    /**
     * 受付アンケート用 URL のトークン（DS-1000。2026-10 追加）。trainees の表は各テストで作る
     */
    protected function createTraineeIntakeSurveyTokensTable(): void
    {
        Schema::create('trainee_intake_survey_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('trainee_id');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
        });
    }

    /**
     * 受付アンケートの回答（D-1100。2026-10 追加）。trainees の表は各テストで作る
     */
    protected function createTraineeIntakeSurveyResponsesTable(): void
    {
        Schema::create('trainee_intake_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->unsignedBigInteger('trainee_intake_survey_token_id')->nullable();
            $table->timestamp('answered_at');
            $table->string('name', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->string('neuter_status', 20)->nullable();
            $table->text('wish')->nullable();
            $table->text('recent_events')->nullable();
            $table->text('ideal_days')->nullable();
            $table->text('health_concerns')->nullable();
            $table->timestamps();
        });
    }

    /**
     * 性格診断用 URL のトークン（DS-1100。2026-10 追加）。trainees の表は各テストで作る。
     * トレーニーを消したらトークンも消えることを確かめられるよう、本番と同じ外部キーを付ける
     * （SQLite は接続設定 foreign_key_constraints〔既定 true〕で外部キーが効く）
     */
    protected function createTraineePersonalityDiagnosisTokensTable(): void
    {
        Schema::create('trainee_personality_diagnosis_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('trainee_id');
            $table->foreign('trainee_id')->references('id')->on('trainees')->cascadeOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
        });
    }

    /**
     * 性格診断の結果（D-1200。2026-10 追加）。trainees の表は各テストで作る。外部キーは本番と同じ
     * （トレーニーを消したら結果も消える、トークンを消したら結果のトークンの列は NULL）
     */
    protected function createTraineePersonalityDiagnosisResultsTable(): void
    {
        Schema::create('trainee_personality_diagnosis_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->foreign('trainee_id')->references('id')->on('trainees')->cascadeOnDelete();
            $table->unsignedBigInteger('trainee_personality_diagnosis_token_id')->nullable();
            $table->foreign('trainee_personality_diagnosis_token_id')
                ->references('id')->on('trainee_personality_diagnosis_tokens')->nullOnDelete();
            $table->timestamp('answered_at');
            $table->string('question_version', 10);
            $table->json('answers');
            // 点数なし（全問「未経験」）・判定なしは NULL（2026-10-18 変更。2026_10_18_000002_make_personality_diagnosis_result_scores_nullable）
            foreach (['people', 'dogs', 'possessiveness', 'separation', 'fear', 'trainability', 'excitability'] as $category) {
                $table->decimal('score_'.$category, 3, 2)->nullable();
            }
            $table->string('personality_type', 30)->nullable();
            $table->timestamps();
        });
    }
}
