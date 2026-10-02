{{--
    フォーム上部の短い案内（Bootstrap 5 の `alert alert-danger`）

    設計書 §2-7「入力エラーの出し方」の規約で定めた、画面上部の 1 文の案内。
    エラーがあるときに「入力内容に誤りがあります。赤字の項目を確認してください。」
    を出す。個々のエラーの一覧は出さない（欄の下で分かるため）。閉じるボタンは
    付けない（§2-7 の規約）。

    引数: なし

    使い方:
      フォームの上（画面の見出しの直下）に 1 つ置く:
        <h2>...</h2>
        <x-form-error-summary />
        <form ...>...</form>

    文言は `lang/ja/messages.php` の `form_error_summary` に置いている
    （画面側では直書きしない）。
    エラーがなければ何も出さない（空タグとして残らない）。
--}}

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ __('messages.form_error_summary') }}</div>
@endif
