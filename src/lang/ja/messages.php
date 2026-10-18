<?php

// 愛犬性格診断の名前（画面に出す正式名称。2026-10-18 変更：正式名称に決まった。その前は「性格診断」）。
// 名前はまた変わることがあるので、ここ 1 か所だけに置く。画面・コントローラーは __('messages.personality_diagnosis_name') で読み、
// このファイルの中の文（client_survey_notice など）はこの変数から組み立てる。
// コード上の名前（クラス名・テーブル名・ルート名の personality_diagnosis など）は画面に出ないので変えない。
// 名前は「愛犬」「性格診断」の 2 つに分けて持つ（2026-10-18 追加）：ふつうの名前はつなげたもの、狭い場所のラベル用は
// 間に折り返しの印を入れたもの（App\Support\PersonalityDiagnosisName::labelHtml()）。名前を変えるときは下の 1 行だけ直す
$personalityDiagnosisNameParts = ['愛犬', '性格診断'];
$personalityDiagnosisName = implode('', $personalityDiagnosisNameParts);

return [

    /*
    |--------------------------------------------------------------------------
    | 画面に表示する汎用メッセージ
    |--------------------------------------------------------------------------
    |
    | バリデーションの文言は `lang/ja/validation.php` に置き、本ファイルは
    | 画面共通の案内文（入力エラーの上部案内など）を集める。
    | 画面側で直書きしない。
    |
    */

    // §2-7「入力エラーの出し方」の画面上部の短い案内（<x-form-error-summary /> が出す文言）。
    // 2026-10 に 2 文目を落として 1 文に短くした
    // （場所は欄の赤枠と欄の下の文言で分かるため不要。色だけに頼る言い回しもなくす）。
    'form_error_summary' => '入力内容に誤りがあります。',

    // 愛犬性格診断の名前（上の $personalityDiagnosisName。画面に出すときはこのキーから読む）
    'personality_diagnosis_name' => $personalityDiagnosisName,
    // 名前を折り返してよい位置で分けたもの（上の $personalityDiagnosisNameParts。ラベル用の形を作るときに使う）
    'personality_diagnosis_name_parts' => $personalityDiagnosisNameParts,

    // 会員のマイページ（S-1402）の愛犬のカードに出す、回答待ちの案内のお知らせ（2026-10 追加）。
    // 名称（「受付アンケート」「愛犬性格診断」）は文をここだけで変える（愛犬性格診断は上の $personalityDiagnosisName）。
    // 印刷した案内の見出し（「受付アンケートのご案内」「愛犬性格診断のご案内」）と名称をそろえる
    // マイページの「ご連絡先の登録のお願い」（2026-10-18 追加。設計書 S-1402）。契約がある会員で、住所か電話番号が空のときに出す。
    // 足りないものだけを書く（住所だけ・電話番号だけ・両方）
    'client_contact_notice' => [
        'address' => 'ご住所のご登録をお願いします。',
        'phone' => 'お電話番号のご登録をお願いします。',
        'both' => 'ご住所・お電話番号のご登録をお願いします。',
        'register' => '登録する',
    ],

    'client_survey_notice' => [
        'intake' => '受付アンケートにお答えください。',
        'personality' => $personalityDiagnosisName.'にお答えください。',
        'answer' => '回答する',
    ],

    // 回答完了の画面・使えない案内の画面（受付アンケート S-1414・S-1413、性格診断 S-1416・S-1415）に、
    // ログインしている会員が自分の愛犬の案内に回答したときだけ出すリンクの文字（2026-10 追加）
    'client_back_to_mypage' => 'マイページへ戻る',

    // 3 つのご案内（マイページ登録 S-0307・受付アンケート S-0311・愛犬性格診断 S-0312）の、LINE・メールで送る文面（2026-10-18 追加。
    // 設計書 screen-design.md S-0307「LINE・メールで送る文面」）。文面は App\Support\InvitationMessage が行をつないで組み立てる
    // （画面に出す文と［文面をコピー］でコピーする文は同じもの）。トレーナーさんに確かめてもらって直すときは、ここだけを直す。
    // :name＝会員の氏名、:company＝訓練所の名前（config('app.client_portal_company')。空なら from の行ごと出さない）、
    // :callName＝トレーニーの呼び名（「アイちゃん」。名前が空なら「愛犬」）、:expires＝有効期限（2026年10月21日（水））、:count＝問題の数
    'invitation_message' => [
        'greeting' => ':name 様',
        'from' => ':companyです。',
        'expires' => 'リンクの有効期限：:expiresまで',
        // 受付アンケート・愛犬性格診断の案内は有効期限を持たない（要件定義書 6-15-17・6-15-18 備考）。有効期限の行の代わり
        'once' => 'ご回答は1回かぎりです。送信すると、このリンクは使えなくなります。',
        'closing' => 'ご不明な点がございましたら、お気軽にお問い合わせください。',
        'mypage' => [
            'intro' => 'マイページのご登録のご案内をお送りします。',
            'body' => "下のリンクを開いて、メールアドレスをご入力ください。\n届いたメールのリンクから、パスワードなどを設定していただくと、ご登録が完了します。",
        ],
        'intake' => [
            'intro' => ':callNameの受付アンケートのご案内をお送りします。',
            'body' => '下のリンクから、ご回答をお願いいたします。',
        ],
        'personality' => [
            // :diagnosis＝愛犬性格診断の名前（personality_diagnosis_name。InvitationMessage が渡す。名前を差し替えたときも文面に出るように）
            'intro' => ':callNameの:diagnosisのご案内をお送りします。',
            'body' => '下のリンクから、ご回答をお願いいたします。（全:count問です）',
        ],
    ],

];
