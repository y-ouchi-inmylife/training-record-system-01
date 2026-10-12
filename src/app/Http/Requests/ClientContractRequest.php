<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 契約（D-0900）の登録・編集の共通バリデーションルール。
 *
 * 認可はルートのミドルウェア（practitioners）で担保。
 * 一般トレーナーも操作可能（削除も含めて、設計書 requirements.md 6-17-1〜6-17-3）。
 * ルールは screen-design.md S-0305-M04 のバリデーション表のとおり。
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
}
