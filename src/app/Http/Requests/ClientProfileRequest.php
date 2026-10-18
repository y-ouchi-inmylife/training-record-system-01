<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ClientContactRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * クライアント基本情報（連絡先）の変更（S-1411 / 6-15-9）バリデーション。
 *
 * 連絡先（住所・電話番号・緊急連絡先）を受け取り、氏名・メールアドレス・パスワードは受け付けない（別画面）。
 * 郵便番号は任意（住所検索の入口という位置づけで、DB も NULL 許容。設計書 S-1411 備考参照）。
 *
 * 2026-10-18 変更：住所・電話番号の必須は、ログイン中の会員に契約があるかで決める（ClientContactRules）。
 * - 契約がある：電話番号・都道府県・市区町村・町名番地が必須（預かりが決まっているので入れてもらう）
 * - 契約がない：初回設定と同じく任意（住所は一部だけ入れたらそろえて入れてもらう）
 * その前は、契約の有無にかかわらず必須だった。
 */
class ClientProfileRequest extends FormRequest
{
    use ClientContactRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // ルートは auth:client の中なので会員はいる。会員がいない（ルールだけを取り出すテストなど）ときは契約なしとして扱う
        return $this->contactRules(required: $this->user('client')?->hasContracts() ?? false);
    }

    // messages() は段階 2 で削除。文言は lang/ja/validation.php に集約（設計書 §2-8）。
}
