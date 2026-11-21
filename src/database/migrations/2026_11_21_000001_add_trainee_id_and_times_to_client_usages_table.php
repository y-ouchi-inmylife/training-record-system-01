<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 利用（D-1000）に、トレーニー（trainee_id）・開始時刻（start_time）・終了時刻（end_time）を足し、
 * 既存の利用を移し替える（2026-11 追加。設計書 db-schema.md D-1000 注記「既存データの移し替え」・
 * requirements.md 6-17-9）。
 *
 * 順序：
 *   1. 3 つの列を NULL 可で足す
 *   2. 既存の行に start_time = 08:00:00、end_time = 19:00:00 を入れる（固定値。予定の設定の値は読まない）
 *   3. trainee_id を設計書の規則で入れる
 *   4. start_time・end_time を NOT NULL に変える（DB のデフォルト値は持たせない）
 *   5. trainee_id に外部キー（trainees(id) ON DELETE SET NULL）を付ける
 *
 * 「空のトレーニー」の判定は、モデル（Trainee::scopeNotBlank）を呼ばず、この中でクエリビルダで同じ条件を書く
 * （あとでモデルの判定が変わっても、このマイグレーションの結果が変わらないようにするため）。
 */
return new class extends Migration
{
    /**
     * 空でないトレーニーの判定に使う列（2026-11 時点の Trainee::CONTENT_COLUMNS の写し）。
     * 文字の列は NULL でも空文字でもなければ「入っている」、誕生日の数の列は NULL でなければ「入っている」
     */
    private const TEXT_COLUMNS = ['name', 'breed', 'sex', 'neuter_status', 'photo_path', 'trainer_photo_path'];

    private const NUMBER_COLUMNS = ['birth_year', 'birth_month', 'birth_day'];

    /**
     * 空でないトレーニーの判定に使う、トレーニーに結び付く表（2026-11 時点の Trainee::CONTENT_RELATIONS の写し）。
     * どれかに trainee_id の行が 1 行でもあれば「空でない」
     */
    private const RELATED_TABLES = [
        'trainee_measurements',
        'trainee_intake_survey_tokens',
        'trainee_intake_survey_responses',
        'trainee_personality_diagnosis_tokens',
        'trainee_personality_diagnosis_results',
    ];

    public function up(): void
    {
        // 1. NULL 可で足す（既存の行があるため、値を入れてから NOT NULL にする）
        Schema::table('client_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('trainee_id')->nullable()->after('client_contract_id')
                ->comment('どのトレーニーの分か（トレーニーのID。外部キー）。NULL は「トレーニーなし」');
            $table->time('start_time')->nullable()->after('used_on')->comment('開始時刻');
            $table->time('end_time')->nullable()->after('start_time')->comment('終了時刻');
        });

        // 2. 既存の行の時刻（固定値）
        DB::table('client_usages')->update([
            'start_time' => '08:00:00',
            'end_time' => '19:00:00',
        ]);

        // 3. 既存の行のトレーニー（会員ごと）
        $clientIds = DB::table('client_usages')->distinct()->orderBy('client_id')->pluck('client_id');
        foreach ($clientIds as $clientId) {
            $traineeId = $this->traineeIdFor((int) $clientId);
            if ($traineeId !== null) {
                DB::table('client_usages')->where('client_id', $clientId)->update(['trainee_id' => $traineeId]);
            }
        }

        // 4. 時刻を NOT NULL に（DB のデフォルト値は持たせない）。change() では列の定義を言い直す
        Schema::table('client_usages', function (Blueprint $table) {
            $table->time('start_time')->nullable(false)->comment('開始時刻')->change();
            $table->time('end_time')->nullable(false)->comment('終了時刻')->change();
        });

        // 5. 外部キー。トレーニーを削除しても利用は消さず「トレーニーなし」（NULL）にする（残り回数を変えないため）。
        // インデックス client_usages_trainee_id_foreign は外部キーに伴い自動付与される
        Schema::table('client_usages', function (Blueprint $table) {
            $table->foreign('trainee_id', 'client_usages_trainee_id_foreign')
                ->references('id')->on('trainees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_usages', function (Blueprint $table) {
            $table->dropForeign('client_usages_trainee_id_foreign');
            // MySQL では外部キーに伴って自動付与されたインデックスが残るため、明示して外す
            $table->dropIndex('client_usages_trainee_id_foreign');
            $table->dropColumn(['trainee_id', 'start_time', 'end_time']);
        });
    }

    /**
     * その会員の利用に入れるトレーニー（設計書 D-1000 注記の規則）。
     *
     * - 空でないトレーニーが 1 頭 → その 1 頭
     * - 空でないトレーニーが 0 頭で、トレーニーが全体で 1 頭 → その 1 頭
     * - それ以外 → null（トレーニーなし）
     */
    private function traineeIdFor(int $clientId): ?int
    {
        $nonBlankIds = DB::table('trainees')
            ->where('client_id', $clientId)
            ->where(fn (Builder $q) => $this->whereNotBlank($q))
            ->orderBy('id')
            ->pluck('id');
        if ($nonBlankIds->count() === 1) {
            return (int) $nonBlankIds->first();
        }
        if ($nonBlankIds->count() === 0) {
            $allIds = DB::table('trainees')->where('client_id', $clientId)->orderBy('id')->pluck('id');
            if ($allIds->count() === 1) {
                return (int) $allIds->first();
            }
        }

        return null;
    }

    /**
     * 空でないトレーニーの条件（Trainee::scopeNotBlank と同じ条件をクエリビルダで書いたもの）。
     * どれか 1 つでも当てはまれば「空でない」
     */
    private function whereNotBlank(Builder $q): void
    {
        foreach (self::TEXT_COLUMNS as $column) {
            $q->orWhere(fn (Builder $c) => $c->whereNotNull('trainees.'.$column)->where('trainees.'.$column, '!=', ''));
        }
        foreach (self::NUMBER_COLUMNS as $column) {
            $q->orWhereNotNull('trainees.'.$column);
        }
        foreach (self::RELATED_TABLES as $relatedTable) {
            $q->orWhereExists(fn (Builder $e) => $e->select(DB::raw(1))
                ->from($relatedTable)
                ->whereColumn($relatedTable.'.trainee_id', 'trainees.id'));
        }
    }
};
