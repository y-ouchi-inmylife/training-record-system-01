<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * クライアント登録・編集の共通バリデーションルール。
 *
 * 元は ClientController::validationRules() に private で置いていたものを、
 * クライアント側の初回設定画面（段階 4-1）からも同じルールを使うために
 * FormRequest として切り出した。ルールの内容は移動時点で一字一句同一。
 *
 * 内部ID の重複チェック（update 固有）と、そのカスタムメッセージは
 * ControllerController::update 内に据え置いている。
 */
class ClientRequest extends FormRequest
{
    /**
     * ルーティング側のミドルウェア（practitioners 等）で認可を担保しているため、
     * ここでは常に true を返す。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * バリデーションルール。
     *
     * 元 ClientController::validationRules() の内容をそのまま移した。
     * 電話番号／緊急連絡先（ご家族等）／続柄（phone1 / phone2 / emergency_contact_relationship）のラベル差替は
     * `lang/ja/validation.php` の attributes セクションで解決している（phone2 だけは下の attributes() で差し替える）。
     */
    public function rules(): array
    {
        return [
            // カテゴリー1: 基本情報
            'last_name' => 'required|string|max:50',
            'first_name' => 'nullable|string|max:50',
            'last_name_kana' => ['nullable', 'string', 'max:50', 'regex:/^[\p{Hiragana}\s　]+$/u'],
            'first_name_kana' => ['nullable', 'string', 'max:50', 'regex:/^[\p{Hiragana}\s　]+$/u'],
            // メールアドレスはトレーナー側から受け付けない（設計書 D3、S-0301／S-0306）。
            // クライアント自身がメールアドレス登録用 URL から登録する項目のため、
            // ここでルール化しない＝validated() に含まれない＝保存されない。
            'initial_consultation_date' => 'required|date',

            // カテゴリー2: 連絡先
            'phone1' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-]+$/'],
            'phone2' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-]+$/'],
            // 続柄（2026-10-17 追加）。任意・自由入力・20文字以内。緊急連絡先（phone2）との組み合わせのきまりは作らない
            'emergency_contact_relationship' => ['nullable', 'string', 'max:20'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{3}-?\d{4}$/'],
            'address1' => 'nullable|string|max:50',
            'address2' => 'nullable|string|max:50',
            'address3' => 'nullable|string|max:100',
            'address4' => 'nullable|string|max:100',

            'primary_trainer_id' => 'nullable|exists:trainers,id',
        ];
    }

    /**
     * トレーナー側の項目名の差し替え。
     *
     * phone2 は、トレーナー側の登録・編集のラベルが「緊急連絡先」なので、エラーの文の項目名もそろえる
     * （2026-10-18 変更。例：「緊急連絡先の形式が正しくありません。」）。会員側（初回設定・登録情報の変更）は
     * lang/ja/validation.php の「緊急連絡先（ご家族等）」のまま。
     */
    public function attributes(): array
    {
        return [
            'phone2' => '緊急連絡先',
        ];
    }
}
