<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 利用（D-1000）の登録・編集の共通バリデーションルール。
 *
 * 認可はルートのミドルウェア（practitioners）で担保。
 * 一般トレーナーも操作可能（削除も含めて、設計書 requirements.md 6-17-4〜6-17-6）。
 * ルールは screen-design.md S-0305-M05 のバリデーション表のとおり。
 * 残り回数がマイナスになる登録・編集も止めない（残り回数のチェックは持たない）。
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
            'used_on' => 'required|date',
            'count' => 'required|integer|min:1|max:999',
            'note' => 'nullable|string|max:255',
        ];
    }
}
