<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTraineeBirthday;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 愛犬アンケートの回答（S-1413。D-1100。2026-10 追加）のバリデーション。
 *
 * お客様がログインせずに送る（愛犬アンケート用 URL のトークンで対象のトレーニーが決まる）ため、
 * authorize() は常に true。トークンが使えるかどうかはコントローラで確かめる
 * （使えなければ使えない URL の画面を出す）。
 *
 * - 名前：常に必須（トレーニーの名前の有無で分けない。2026-10 変更。当初は、未登録なら必須、登録済みなら
 *   受け取らない〔exclude〕とし、コントローラでロックしたあとに確かめ直していた）
 * - 性別・去勢・避妊：トレーニーと同じ値（男の子／女の子／不明、している／していない／不明。Trainee の
 *   対応表のキー。2026-10 変更。当初は性別に「不明」を置かず、去勢・避妊は「分からない」〔dont_know〕だった）
 * - トークンが見つからないとき（使えない URL）も同じ規則で検証する。入力エラーで戻った先の回答画面
 *   （GET）が、使えない URL の画面を出す
 * - 誕生日はトレーナー側（TraineeRequest）と同じ判定（trait NormalizesTraineeBirthday）
 * - 項目名と文言は lang/ja/validation.php（screen-design.md S-1413）
 * - 2026-10-19 変更：自由記述の質問 4 問を愛犬性格診断へ移した（ClientPersonalityDiagnosisRequest）。愛犬アンケートは基本情報だけ
 */
class ClientIntakeSurveyRequest extends FormRequest
{
    use NormalizesTraineeBirthday {
        prepareForValidation as normalizeTraineeBirthday;
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // 愛犬の基本情報（名前〜去勢・避妊）は、会員の愛犬の情報の変更（S-1417）と同じ規則（2026-10-18 に
        // ClientTraineeProfileRequest::basicInfoRules() へまとめた）。誕生日は withValidator でまとめて判定する
        return ClientTraineeProfileRequest::basicInfoRules();
    }

    /**
     * 検証の前に値を直す：誕生日の 3 つの欄（trait NormalizesTraineeBirthday の処理。全角の数字を半角に、など）
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeTraineeBirthday();
    }
}
