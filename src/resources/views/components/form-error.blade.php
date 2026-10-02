{{--
    入力欄の下に出す赤字のエラー表示（Bootstrap 5 の `invalid-feedback`）

    設計書 §2-7「入力エラーの出し方」の規約で定めた、欄の下のエラー表示を
    1 行で書けるようにする匿名コンポーネント。1 つの欄に複数のエラーが
    あるときは、最初の 1 つだけを出す（`$errors->first($field)`）。

    引数:
      field  (string, 必須)  エラーのキー（例：'last_name', 'client_id'）
      block  (bool, 任意)    真のとき `invalid-feedback d-block` を付ける

    使い方:
      <input type="text" name="last_name"
             class="form-control @error('last_name') is-invalid @enderror">
      <x-form-error field="last_name" />

    `block` を付けるのは、入力欄の兄弟の位置に置けないとき:
      - `input-group`（郵便番号＋検索ボタンなど）の外側に置く
      - Select2 など、オリジナルの <select> を隠すライブラリ部品の下
      - 複数の入力欄をまとめた項目（姓・名、せい・めい）の外側
      - `<input type="file">` と兄弟でない位置
    これらは Bootstrap 既定の `.is-invalid ~ .invalid-feedback` セレクタで
    自動表示されないため、`d-block` で表示を確実にする。

    エラーがなければ何も出さない（空タグとして残らない）。
--}}
@props([
    'field' => null,
    'block' => false,
])

@if($errors->has($field))
    <div class="invalid-feedback{{ $block ? ' d-block' : '' }}">{{ $errors->first($field) }}</div>
@endif
