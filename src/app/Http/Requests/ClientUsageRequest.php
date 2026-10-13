<?php

namespace App\Http\Requests;

use App\Models\ClientContract;
use App\Models\ClientUsage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 利用（D-1000）の登録・編集の共通バリデーションルール。
 *
 * 認可はルートのミドルウェア（practitioners）で担保。
 * 一般トレーナーも操作可能（削除も含めて、設計書 requirements.md 6-17-4〜6-17-6）。
 * ルールは screen-design.md S-0305-M05 のバリデーション表のとおり。
 *
 * 「契約」（client_contract_id）は任意（空＝「契約なし」）。選んだときは：
 *   - この会員の契約であること（登録はルートの $client、編集は $usage->client_id で判定）
 *   - 契約の残りを超えないこと。残りは「契約の回数 − その契約に割り当てた利用の回数の合計」で、
 *     編集のときはその利用自身の今の回数を除いて計算する（同じ契約のまま回数を変える場合も、
 *     別の契約に移す場合も、この計算で正しく判定できる）
 * 会員の残り回数（見出しの数字）がマイナスになる登録・編集は止めない（「契約なし」は残りに関係なく登録できる）。
 * 契約日と利用日の前後・有効期限はチェックに使わない。
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
            'used_on' => 'required|date',
            'count' => 'required|integer|min:1|max:999',
            'note' => 'nullable|string|max:255',
        ];
    }

    /**
     * 契約の所属と残りのチェック。通常のルールで項目のエラーがないときだけ行う
     * （回数が数でないときに残りと比べても意味がないため）。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['client_contract_id', 'count'])) {
                return;
            }

            $contractId = $this->input('client_contract_id');
            if ($contractId === null || $contractId === '') {
                return; // 「契約なし」
            }

            $usage = $this->route('usage');   // 編集時のみ
            $client = $this->route('client'); // 登録時のみ
            $clientId = $usage?->client_id ?? $client?->id;

            $contract = ClientContract::find($contractId);
            if (! $contract || (int) $contract->client_id !== (int) $clientId) {
                $validator->errors()->add(
                    'client_contract_id',
                    __('validation.custom.client_contract_id.other_client')
                );

                return;
            }

            // 契約の残り。編集のときは、その利用自身の今の回数を除く
            $assigned = (int) ClientUsage::query()
                ->where('client_contract_id', $contract->id)
                ->when($usage, fn ($query) => $query->where('id', '!=', $usage->id))
                ->sum('count');
            $remaining = max(0, (int) $contract->count - $assigned);

            $count = (int) $this->input('count');
            if ($count > $remaining) {
                $validator->errors()->add(
                    'client_contract_id',
                    __('validation.custom.client_contract_id.exceeds_remaining', [
                        'remaining' => $remaining,
                        'shortage' => $count - $remaining,
                    ])
                );
            }
        });
    }
}
