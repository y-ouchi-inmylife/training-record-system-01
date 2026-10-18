<?php

namespace App\Http\Requests\Concerns;

/**
 * 会員側の住所・電話番号のルール（初回設定 S-1403・登録情報の変更 S-1411。2026-10-18 追加）。
 *
 * 2026-10-18 のユーザーレビューで、マイページの登録の時点ではまだ預かるか決まっていない会員もいるので、
 * 住所・電話番号を任意にした。契約が登録された会員は預かりが決まっているので、登録情報の変更では必須にする
 * （$required）。マイページでも入力を促す（Client::missingContactDetails、S-1402「ご連絡先の登録のお願い」）。
 *
 * - 必須のとき：電話番号（phone1）・都道府県（address1）・市区町村（address2）・町名番地（address3）が必須（今までの初回設定と同じ）
 * - 任意のとき：全部空でよい。住所の一部だけ（都道府県だけ、建物名だけなど）を入れたときは、都道府県・市区町村・町名番地を
 *   そろえて入れてもらう（required_with。どれか 1 つでも入っていれば残りが必須）。電話番号は空でよい
 * - 郵便番号・建物名・部屋番号（address4）はどちらでも任意（郵便番号は住所検索の入口。今までどおり）
 */
trait ClientContactRules
{
    /**
     * @return array<string, mixed>
     */
    protected function contactRules(bool $required): array
    {
        $address = fn (string $field, int $max) => [
            $required ? 'required' : 'nullable',
            ...($required ? [] : ['required_with:'.implode(',', array_diff(['address1', 'address2', 'address3', 'address4'], [$field]))]),
            'string',
            'max:'.$max,
        ];

        return [
            'phone1' => [$required ? 'required' : 'nullable', 'string', 'max:20', 'regex:/^[0-9\-]+$/'],
            'phone2' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-]+$/'],
            // 続柄（2026-10-17 追加）。任意・自由入力・20文字以内。緊急連絡先（phone2）との組み合わせのきまりは作らない
            'emergency_contact_relationship' => ['nullable', 'string', 'max:20'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{3}-?\d{4}$/'],
            'address1' => $address('address1', 50),
            'address2' => $address('address2', 50),
            'address3' => $address('address3', 100),
            'address4' => 'nullable|string|max:100',
        ];
    }
}
