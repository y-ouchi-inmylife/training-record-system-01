<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 契約（D-0900）の登録・編集の共通バリデーションルール。
 *
 * 認可はルートのミドルウェア（practitioners）で担保。
 * 一般トレーナーも操作可能（削除も含めて、設計書 requirements.md 6-17-1〜6-17-3）。
 * ルールは screen-design.md S-0305-M04 のバリデーション表のとおり。
 * 編集のときは、回数を「この契約に割り当て済みの利用回数の合計」より少なくできない
 * （requirements.md 6-17-2）。
 * 項目名・文言は lang/ja/validation.php に集約（§2-8）。
 */
class ClientContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contracted_on' => 'required|date',
            'count' => 'required|integer|min:1|max:999',
            'expires_on' => 'nullable|date|after_or_equal:contracted_on',
            'note' => 'nullable|string|max:255',
        ];
    }

    /**
     * 編集時の「割り当て済みの利用回数以上」のチェック。回数の通常のルールで
     * エラーがないときだけ行う。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $contract = $this->route('contract'); // 編集時のみ
            if (! $contract || $validator->errors()->has('count')) {
                return;
            }

            $assigned = (int) $contract->usages()->sum('count');
            if ((int) $this->input('count') < $assigned) {
                $validator->errors()->add(
                    'count',
                    __('validation.custom.count.below_assigned', ['assigned' => $assigned])
                );
            }
        });
    }
}
