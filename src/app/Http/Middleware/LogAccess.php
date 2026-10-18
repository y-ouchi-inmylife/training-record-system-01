<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * トレーナー操作履歴記録ミドルウェア
 */
class LogAccess
{
    /** ログ対象のルート名と操作名のマッピング */
    private const ACTION_MAP = [
        'clients.show' => 'view_client',
        'clients.store' => 'create_client',
        'clients.update' => 'edit_client',
        'clients.destroy' => 'delete_client',
        'training-records.show' => 'view_training_record',
        'training-records.store' => 'create_training_record',
        'training-records.update' => 'edit_training_record',
        'training-records.destroy' => 'delete_training_record',
        // トレーニー（D-0700、段階①）。参照は view_client に含めるため view_trainee は作らない。
        'trainees.store' => 'create_trainee',
        'trainees.update' => 'edit_trainee',
        'trainees.destroy' => 'delete_trainee',
        // トレーニー計測値（D-0800、段階②）。参照は view_client に含めるため view_trainee_measurement は作らない。
        'trainee-measurements.store' => 'create_trainee_measurement',
        'trainee-measurements.update' => 'edit_trainee_measurement',
        'trainee-measurements.destroy' => 'delete_trainee_measurement',
        // 受付アンケート案内（DS-1000。2026-10 追加）。対象はトレーニー（target_type='Trainee'、
        // target_id はルートの {trainee}。トークンの行は取消で物理削除されるため）。
        // お客様の回答は記録しない（access_logs.trainer_id が NOT NULL のため。db-schema.md 5-16）
        'trainee-intake-survey-tokens.store' => 'create_trainee_intake_survey_token',
        'trainee-intake-survey-tokens.destroy' => 'delete_trainee_intake_survey_token',
        // 性格診断の案内（DS-1100。2026-10 追加）。受付アンケート案内と同じく、対象はトレーニー
        // （target_type='Trainee'、target_id はルートの {trainee}）。お客様の回答・結果を見たことは記録しない
        'trainee-personality-diagnosis-tokens.store' => 'create_trainee_personality_diagnosis_token',
        'trainee-personality-diagnosis-tokens.destroy' => 'delete_trainee_personality_diagnosis_token',
        // 性格診断の結果の削除（2026-10 追加）。対象はトレーニー（target_id はルートの {trainee}。結果の id は記録しない）
        'trainee-personality-diagnosis-results.destroy' => 'delete_trainee_personality_diagnosis_result',
        // 愛犬性格診断の評価文の保存（S-0313。2026-10-18 追加）。対象はトレーニー（結果の削除と同じ。結果の id は記録しない）。
        // 愛犬性格診断詳細の表示は記録しない（トレーニー詳細を見たことと同じ）
        'trainee-personality-diagnosis-results.update' => 'edit_trainee_personality_diagnosis_evaluation',
        // 評価文の公開・公開取消（2026-10-18 追加）。対象はトレーニー（2026-10-18 変更：その前は公開を
        // トレーニング記録の登録〔create_training_record〕として記録していた。公開で記録を作らなくなったため）
        'trainee-personality-diagnosis-results.publish' => 'publish_trainee_personality_diagnosis_evaluation',
        'trainee-personality-diagnosis-results.unpublish' => 'unpublish_trainee_personality_diagnosis_evaluation',
        // 契約（D-0900）・利用（D-1000）。参照は view_client に含めるため view_ 系は作らない。
        'client-contracts.store' => 'create_client_contract',
        'client-contracts.update' => 'edit_client_contract',
        'client-contracts.destroy' => 'delete_client_contract',
        'client-usages.store' => 'create_client_usage',
        'client-usages.update' => 'edit_client_usage',
        'client-usages.destroy' => 'delete_client_usage',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (auth()->check() && $request->route()) {
            $this->logAccess($request);
        }

        return $response;
    }

    private function logAccess(Request $request): void
    {
        $routeName = $request->route()->getName();
        if (!$routeName) {
            return;
        }

        $action = self::ACTION_MAP[$routeName] ?? null;
        if (!$action) {
            return;
        }

        // 対象IDを取得
        $targetType = null;
        $targetId = null;

        // より限定的な条件を先に判定する必要がある：
        //   'create_trainee_measurement' は 'trainee' も含むため、
        //   trainee_measurement を先に判定しないと trainee の分岐に落ちる。
        if (str_contains($action, 'trainee_measurement')) {
            $targetType = 'TraineeMeasurement';
            // store のとき URL パラメータは `trainee`（親）で、
            // update / destroy のときは `measurement`。store 時点では作成した
            // レコードのIDを取れないため（ミドルウェアはレスポンス後に動く）、
            // target_id は null にする（既存の create_training_record などと同じ扱い）。
            $param = $request->route('measurement');
            $targetId = is_object($param) ? $param->id : $param;
        } elseif (str_contains($action, 'trainee')) {
            $targetType = 'Trainee';
            $param = $request->route('trainee');
            $targetId = is_object($param) ? $param->id : $param;
        } elseif (str_contains($action, 'client_contract')) {
            // 'create_client_contract' などは 'client' も含むため、会員（client）の判定より前に置く。
            // store のとき URL パラメータは `client`（親）で、作成したレコードの ID は取れないため
            // target_id は null にする（計測値の store と同じ扱い）。
            $targetType = 'ClientContract';
            $param = $request->route('contract');
            $targetId = is_object($param) ? $param->id : $param;
        } elseif (str_contains($action, 'client_usage')) {
            // 同上（会員の判定より前に置く）
            $targetType = 'ClientUsage';
            $param = $request->route('usage');
            $targetId = is_object($param) ? $param->id : $param;
        } elseif (str_contains($action, 'client')) {
            $targetType = 'Client';
            $param = $request->route('client');
            $targetId = is_object($param) ? $param->id : $param;
        } elseif (str_contains($action, 'training_record')) {
            $targetType = 'TrainingRecord';
            $param = $request->route('training_record') ?? $request->route('trainingRecord');
            $targetId = is_object($param) ? $param->id : $param;
        }

        AccessLog::create([
            'trainer_id' => auth()->id(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 500),
        ]);
    }
}
