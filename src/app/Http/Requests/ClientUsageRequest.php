<?php

namespace App\Http\Requests;

use App\Models\ClientContract;
use App\Models\ClientUsage;
use App\Models\Trainee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 利用（D-1000）の登録・編集の共通バリデーションルール。
 *
 * 認可はルートのミドルウェア（practitioners）で担保。
 * 一般トレーナーも操作可能（削除も含めて、設計書 requirements.md 6-17-4〜6-17-6）。
 * ルールは screen-design.md S-0305-M05 のバリデーション表のとおり。
 *
 * 利用 1 件は 1 回分で、回数は入力しない（D-1000 注記）。
 * 「契約」（client_contract_id）は任意（空＝「契約なし」）。選んだときは：
 *   - この会員の契約であること（登録はルートの $client、編集は $usage->client_id で判定）
 *   - 選んだ契約に残りがあること。「その契約に割り当て済みの利用の件数 ＜ 契約の回数」で判定し、
 *     編集のときはその利用自身を除いて数える（同じ契約のままなら必ず通る。
 *     別の契約に移すときは、移す先の残りで判定される）
 * 会員の残り回数（見出しの数字）がマイナスになる登録・編集は止めない（「契約なし」は残りに関係なく登録できる）。
 * 契約日と利用日の前後・有効期限はチェックに使わない。
 *
 * トレーニー（trainee_id。2026-11 追加。requirements.md 6-17-4）：
 *   - その会員にトレーニーがいるときは必須（空のトレーニーも数える）
 *   - この会員のトレーニーであること。その会員にトレーニーがいないときは指定できない（どの値もこの会員のトレーニーではない）
 *   - 同じトレーニーの利用が同じ日に 2 件以上あってもエラーにしない（1 日で 2 回分使うことがあるため）
 * 開始時刻・終了時刻（start_time・end_time。2026-11 追加）：必須・H:i。終了時刻は開始時刻より後（同じ時刻は不可。日付をまたぐ利用は扱わない）。
 *   前後は 2 つの欄の形式が正しいときだけ確かめる（形式の誤りに重ねて前後の文言を出さないため）。
 * 項目名・文言は lang/ja/validation.php に集約（§2-8）。
 */
class ClientUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_contract_id' => 'nullable|integer',
            'trainee_id' => 'nullable|integer',
            'used_on' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'note' => 'nullable|string|max:255',
        ];
    }

    /**
     * 契約・トレーニー・時刻の、項目どうしやほかの表と関わるチェック。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->checkTrainee($validator);
            $this->checkTimeRange($validator);
            $this->checkContract($validator);
        });
    }

    /**
     * 利用の会員の id（登録はルートの $client、編集は $usage->client_id）
     */
    private function clientId(): ?int
    {
        $usage = $this->route('usage');   // 編集時のみ
        $client = $this->route('client'); // 登録時のみ
        $clientId = $usage?->client_id ?? $client?->id;

        return $clientId === null ? null : (int) $clientId;
    }

    /**
     * トレーニーの必須と所属（2026-11 追加）。トレーニーの欄に通常のルールのエラーがないときだけ行う。
     */
    private function checkTrainee(Validator $validator): void
    {
        if ($validator->errors()->has('trainee_id')) {
            return;
        }

        $clientId = $this->clientId();
        $traineeId = $this->input('trainee_id');

        if ($traineeId === null || $traineeId === '') {
            // その会員にトレーニーがいれば必須（空のトレーニーも数える）
            if (Trainee::query()->where('client_id', $clientId)->exists()) {
                $validator->errors()->add('trainee_id', __('validation.custom.trainee_id.required'));
            }

            return;
        }

        $trainee = Trainee::find($traineeId);
        if (! $trainee || (int) $trainee->client_id !== $clientId) {
            $validator->errors()->add('trainee_id', __('validation.custom.trainee_id.other_client'));
        }
    }

    /**
     * 終了時刻は開始時刻より後（2026-11 追加）。2 つの欄の形式が正しいときだけ行う。
     */
    private function checkTimeRange(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['start_time', 'end_time'])) {
            return;
        }

        // 「HH:MM」どうしは文字列で比べて前後が分かる
        if ((string) $this->input('end_time') <= (string) $this->input('start_time')) {
            $validator->errors()->add('end_time', __('validation.custom.end_time.after_start_time'));
        }
    }

    /**
     * 契約の所属と残りのチェック。契約の欄に通常のルールのエラーがないときだけ行う。
     */
    private function checkContract(Validator $validator): void
    {
        if ($validator->errors()->has('client_contract_id')) {
            return;
        }

        $contractId = $this->input('client_contract_id');
        if ($contractId === null || $contractId === '') {
            return; // 「契約なし」
        }

        $usage = $this->route('usage');   // 編集時のみ
        $clientId = $this->clientId();

        $contract = ClientContract::find($contractId);
        if (! $contract || (int) $contract->client_id !== (int) $clientId) {
            $validator->errors()->add(
                'client_contract_id',
                __('validation.custom.client_contract_id.other_client')
            );

            return;
        }

        // 割り当て済みの利用の件数。編集のときは、その利用自身を除く
        $assigned = ClientUsage::query()
            ->where('client_contract_id', $contract->id)
            ->when($usage, fn ($query) => $query->where('id', '!=', $usage->id))
            ->count();

        if ($assigned >= (int) $contract->count) {
            $validator->errors()->add(
                'client_contract_id',
                __('validation.custom.client_contract_id.no_remaining')
            );
        }
    }
}
