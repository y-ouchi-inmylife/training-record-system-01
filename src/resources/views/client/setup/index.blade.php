@extends('layouts.client-public')

@php
    $portalName = config('app.client_portal_name', 'トレーニング記録');
    $companyName = config('app.client_portal_company');
    $prefectures = config('prefectures');
@endphp

@section('title', '初回設定')

@section('content')
{{-- pre-auth シェル（ログイン画面と共用）+ 幅広モディファイア。
     初回設定はパスワード＋氏名＋連絡先＋住所を 1 画面に載せるため
     .c-login--wide で md 以上のカード幅を拡張する（設計書 §4-7）。
     ワードマークは layouts.partials.client-nav（ヘッダー帯の左）に移動。
     画面構成は S-1406 / S-1411 と揃える：見出しはカード外、セクション
     見出し（.eyebrow の ── XXX ── 装飾）は置かず、代わりに先頭に
     メールアドレスの表示行を置く（S-1411「お名前」と同じマークアップ）。 --}}
<div class="c-login c-login--wide">
    <h1 class="mb-4">初回設定</h1>

    <div class="card c-login-card">
        <div class="card-body p-4">
            {{-- 入力エラー以外の失敗（登録完了メールの送信失敗で全ロールバックしたときなど）は、
                 入力項目のキーではなく専用のキー `form` でフォームの上に出す（設計書 §2-7）。 --}}
            @error('form')
                <div class="alert alert-danger" role="alert">{{ $message }}</div>
            @enderror

            {{-- 入力エラーの上部案内（必須・形式などの本当の入力エラー。設計書 §2-7）。
                 専用のキー `form` だけのときは出さないよう、入力項目のキーがあるときだけ表示する。 --}}
            @if ($errors->hasAny([
                'password',
                'last_name', 'first_name', 'last_name_kana', 'first_name_kana',
                'phone1', 'phone2',
                'postal_code', 'address1', 'address2', 'address3', 'address4',
                'trainee_name', 'trainee_breed', 'trainee_sex', 'trainee_birth_date', 'trainee_note',
            ]))
                <x-form-error-summary />
            @endif

            {{-- 「ログイン情報」小見出し（2026-09 追加、4 か所の 1 番目）。
                 メール表示行とパスワード入力の 2 つをひとつのまとまりとして扱う。
                 <h2 fs-6> を使う理由・fs-6 を外さない旨は下の「お名前」小見出しの
                 コメント参照。**fs-6 を外さないこと**。
                 文言「ログイン情報」にした理由：当初は「『パスワード』と重複するため
                 パスワードのまとまりには小見出しを置かない」方針だったが、他 3 か所
                 との非対称で構造把握が難しいという指摘を 2026-09 に受け、パスワードと
                 語が重複しない「ログイン情報」で追加した。メール表示行もこのまとまりに
                 入るので実態にも合う（設計書 S-1403 備考 / §4-7 参照）。 --}}
            <h2 class="fs-6 fw-bold mb-2">ログイン情報</h2>
            {{-- メールアドレスは表示のみ（S-1411 の「お名前」表示行と同じマークアップに揃える）。
                 説明文で埋め込むより行として立っている方が、ログイン ID の確認場面として目に入る。
                 下に区切り線は引かない：ログイン情報まとまりの内部（メール表示 → パスワード入力欄）
                 の切り替わりであり、まとまりの境目ではない。まとまりの境目（パスワード（確認）
                 の下）に区切り線①が入る。 --}}
            <div class="mb-3">
                <div class="text-muted small">メールアドレス</div>
                <div>{{ $client->email }}</div>
            </div>

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 required・type・inputmode・autocomplete などの属性は残す。 --}}
            <form method="POST" action="{{ route('client-portal.setup.store', ['token' => $token]) }}" novalidate>
                @csrf

                {{-- パスワードマネージャー向け username（保存パスワードの紐付け先を明示）--}}
                <input
                    type="email"
                    name="username"
                    value="{{ $client->email }}"
                    autocomplete="username"
                    readonly
                    tabindex="-1"
                    aria-hidden="true"
                    class="visually-hidden"
                >

                <div class="mb-3">
                    <label for="password" class="form-label">パスワード <span class="text-danger">*</span></label>
                    <input
                        type="password"
                        class="form-control @error('password') is-invalid @enderror"
                        id="password"
                        name="password"
                        required
                        autofocus
                        autocomplete="new-password"
                        aria-describedby="password-help"
                    >
                    {{-- 強度要件のヘルプ文は <x-form-error> の下に置くと、エラー時に欄との間にヘルプ文が
                         挟まる。Bootstrap の .is-invalid ~ .invalid-feedback の「以後の兄弟」セレクタで
                         ヘルプ文を挟んでも表示される。block は付けない。 --}}
                    <x-form-error field="password" />
                    <div id="password-help" class="form-text">
                        8 文字以上で、大文字・小文字・数字・記号をそれぞれ 1 つ以上入れてください。
                    </div>
                </div>
                {{-- パスワード(確認) の下にまとまりの区切り線を引く(4 まとまりの境目の 2 本目)。
                     pb-3 は罫線とパスワード確認欄下端の間隔、mb-3 は罫線と次のまとまり(氏名)との間隔。 --}}
                <div class="mb-3 pb-3 border-bottom">
                    <label for="password_confirmation" class="form-label">パスワード（確認） <span class="text-danger">*</span></label>
                    <input
                        type="password"
                        class="form-control"
                        id="password_confirmation"
                        name="password_confirmation"
                        required
                        autocomplete="new-password"
                    >
                </div>

                {{-- 「お名前」「ご連絡先」の小見出し（太字）は入力欄のまとまりの先頭に置く。
                     見出しタグ <h2> を使う理由：読み上げソフトに画面の構造を伝えるため。
                     見た目だけの太字（旧 <p class="fw-bold">）では構造が伝わらない。
                     <h2> にする理由：主見出し「初回設定」が <h1> なので、その直下の階層。
                     レベルを飛ばさない。fs-6 は本文サイズに落とすため。Bootstrap の
                     見出しタグは既定で大きなフォントサイズが付くが、本文と地続きの小見出しに
                     するために fs-6（= 1rem = 本文サイズ）で明示的に落とす。**fs-6 を外さないこと**
                     （後から「不要」と見て消されると見た目が変わる）。
                     パスワードのまとまりには置かない：「パスワード」「パスワード（確認）」の
                     ラベル自体がまとまりの内容を表すため、見出しを重ねると同じ語が二重になる。
                     区切り線と小見出しの役割は違う：線＝「ここで切れる」、小見出し＝「次が何か」。
                     両方置いて相補的に働かせる。文言「お名前」は S-1406/S-1411 の表示行と揃える。
                     詳細は設計書 S-1403 備考 / client-portal-design-plan.md §4-7 参照。 --}}
                <h2 class="fs-6 fw-bold mb-2">お名前</h2>
                {{-- 姓・名、せい・めいは横二列のまま維持（縦一列標準への意図的な例外）。
                     姓名は対で入力するもので横に並ぶのが自然。スマホでも 2 項目なら収まる。
                     設計書 client-portal-design-plan.md §4-7 参照。 --}}
                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label for="last_name" class="form-label">姓 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('last_name') is-invalid @enderror"
                               id="last_name" name="last_name" required maxlength="50"
                               value="{{ old('last_name', $client->last_name) }}">
                        <x-form-error field="last_name" />
                    </div>
                    <div class="col-sm-6">
                        <label for="first_name" class="form-label">名</label>
                        <input type="text" class="form-control @error('first_name') is-invalid @enderror"
                               id="first_name" name="first_name" maxlength="50"
                               value="{{ old('first_name', $client->first_name) }}">
                        <x-form-error field="first_name" />
                    </div>
                </div>
                {{-- せい・めい行の下にまとまりの区切り線を引く（4 まとまりの境目の 3 本目、氏名まとまりの下）。
                     連絡先まとまりの下（最終まとまり）には線を引かない（カード枠が外周を担当するため。
                     設計書 S-1403 備考 / client-portal-design-plan.md §4-7 参照）。 --}}
                <div class="row g-2 mb-3 pb-3 border-bottom">
                    <div class="col-sm-6">
                        <label for="last_name_kana" class="form-label">せい</label>
                        <input type="text" class="form-control @error('last_name_kana') is-invalid @enderror"
                               id="last_name_kana" name="last_name_kana" maxlength="50"
                               value="{{ old('last_name_kana', $client->last_name_kana) }}">
                        <x-form-error field="last_name_kana" />
                    </div>
                    <div class="col-sm-6">
                        <label for="first_name_kana" class="form-label">めい</label>
                        <input type="text" class="form-control @error('first_name_kana') is-invalid @enderror"
                               id="first_name_kana" name="first_name_kana" maxlength="50"
                               value="{{ old('first_name_kana', $client->first_name_kana) }}">
                        <x-form-error field="first_name_kana" />
                    </div>
                </div>

                {{-- 「ご連絡先」小見出し。<h2 fs-6> を使う理由・パスワードに小見出しを置かない理由・
                     線との役割分担は「お名前」小見出し直前のコメント参照。**fs-6 を外さないこと**。 --}}
                <h2 class="fs-6 fw-bold mb-2">ご連絡先</h2>
                <div class="mb-2">
                    <label for="phone1" class="form-label">電話番号 <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control @error('phone1') is-invalid @enderror"
                           id="phone1" name="phone1" required maxlength="20"
                           value="{{ old('phone1', $client->phone1) }}">
                    <x-form-error field="phone1" />
                </div>
                <div class="mb-3">
                    <label for="phone2" class="form-label">電話番号（予備）</label>
                    <input type="tel" class="form-control @error('phone2') is-invalid @enderror"
                           id="phone2" name="phone2" maxlength="20"
                           value="{{ old('phone2', $client->phone2) }}">
                    <x-form-error field="phone2" />
                </div>

                <div class="mb-2">
                    {{-- 郵便番号は任意（住所検索の入口という位置づけで、DB も NULL 許容。
                         都道府県以下が入っていれば住所として成立する。設計書 S-1403 備考参照）。
                         入力された場合の形式チェック（7 桁）は FormRequest で維持している。 --}}
                    <label for="postal_code" class="form-label">郵便番号</label>
                    {{-- input-group 内で .invalid-feedback を効かせるには .has-validation を付ける
                         （Bootstrap 5 の仕様）。<x-form-error> は input-group の末尾（検索ボタンの後）に
                         置く。住所検索の結果メッセージ（#address-search-message）は input-group の
                         外（下の兄弟）に置き、欄下エラーと重ならないようにする。 --}}
                    <div class="input-group has-validation">
                        <input type="text" class="form-control @error('postal_code') is-invalid @enderror"
                               id="postal_code" name="postal_code"
                               value="{{ old('postal_code', $client->postal_code) }}"
                               placeholder="123-4567">
                        <button type="button" class="btn btn-outline-secondary"
                                id="btn-search-address" onclick="searchAddress()">検索</button>
                        <x-form-error field="postal_code" />
                    </div>
                    {{-- address-search.js がここに検索の結果メッセージを書き込む（alert 非使用）--}}
                    <div id="address-search-message" class="form-text" role="status"></div>
                </div>

                <div class="mb-2">
                    <label for="address1" class="form-label">都道府県 <span class="text-danger">*</span></label>
                    <select class="form-select @error('address1') is-invalid @enderror"
                            id="address1" name="address1" required>
                        <option value="">選択してください</option>
                        @foreach($prefectures as $pref)
                            <option value="{{ $pref }}" @selected(old('address1', $client->address1) === $pref)>{{ $pref }}</option>
                        @endforeach
                    </select>
                    <x-form-error field="address1" />
                </div>
                <div class="mb-2">
                    <label for="address2" class="form-label">市区町村 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('address2') is-invalid @enderror"
                           id="address2" name="address2" required maxlength="50"
                           value="{{ old('address2', $client->address2) }}">
                    <x-form-error field="address2" />
                </div>
                <div class="mb-2">
                    <label for="address3" class="form-label">町名・番地 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('address3') is-invalid @enderror"
                           id="address3" name="address3" required maxlength="100"
                           value="{{ old('address3', $client->address3) }}">
                    <x-form-error field="address3" />
                </div>
                {{-- 建物名・部屋番号は「ご連絡先」まとまりの最終要素。次の「愛犬の情報」まとまりとの
                     境目に区切り線③を引く（設計書 S-1403 / client-portal-design-plan.md §4-7 参照）。
                     pb-3 は罫線と入力欄下端の間隔、mb-3 は罫線と次のまとまりとの間隔。 --}}
                <div class="mb-3 pb-3 border-bottom">
                    <label for="address4" class="form-label">建物名・部屋番号</label>
                    <input type="text" class="form-control @error('address4') is-invalid @enderror"
                           id="address4" name="address4" maxlength="100"
                           value="{{ old('address4', $client->address4) }}">
                    <x-form-error field="address4" />
                </div>

                {{-- 「愛犬の情報」小見出し。位置と装飾の考え方は「お名前」「ご連絡先」小見出しと同じ
                     （<h2 fs-6> の理由・fs-6 を外さない旨は上のコメント参照）。**fs-6 を外さないこと**。 --}}
                <h2 class="fs-6 fw-bold mb-2">愛犬の情報</h2>
                <div class="mb-2">
                    <label for="trainee_name" class="form-label">名前 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('trainee_name') is-invalid @enderror"
                           id="trainee_name" name="trainee_name" required maxlength="50"
                           value="{{ old('trainee_name', $existingTrainee?->name) }}">
                    <x-form-error field="trainee_name" />
                </div>
                <div class="mb-2">
                    <label for="trainee_breed" class="form-label">犬種</label>
                    <input type="text" class="form-control @error('trainee_breed') is-invalid @enderror"
                           id="trainee_breed" name="trainee_breed" maxlength="100"
                           value="{{ old('trainee_breed', $existingTrainee?->breed) }}">
                    <x-form-error field="trainee_breed" />
                </div>
                <div class="mb-2">
                    <label for="trainee_sex" class="form-label">性別</label>
                    <select class="form-select @error('trainee_sex') is-invalid @enderror"
                            id="trainee_sex" name="trainee_sex">
                        <option value=""></option>
                        @foreach(\App\Models\Trainee::sexLabels() as $value => $label)
                            <option value="{{ $value }}" @selected(old('trainee_sex', $existingTrainee?->sex) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-form-error field="trainee_sex" />
                </div>
                {{-- 誕生日は type="date"（ブラウザ標準の日付入力）を使う。会員側レイアウト
                     （layouts.client-public）には Flatpickr の CSS が読み込まれていないため、
                     `.datepicker` クラスで Flatpickr を呼び出すとレイアウトが崩れる
                     （2026-09 に発生した不具合の修正）。トレーナー側は Flatpickr の CSS を
                     読み込む layouts.app を使うのでそちらは変えない。会員側で日付入力を追加する
                     場合は type="date" を使うこと。 --}}
                <div class="mb-2">
                    <label for="trainee_birth_date" class="form-label">誕生日</label>
                    <input type="date" class="form-control @error('trainee_birth_date') is-invalid @enderror"
                           id="trainee_birth_date" name="trainee_birth_date"
                           value="{{ old('trainee_birth_date', $existingTrainee?->birth_date?->format('Y-m-d')) }}"
                           autocomplete="off">
                    <x-form-error field="trainee_birth_date" />
                </div>
                {{-- 備考：ラベル下（.form-text）に多頭飼い向けの案内を出す。
                     設計書 S-1403 の「多頭飼いの運用」参照。備考の下には区切り線を引かない
                     （愛犬の情報が最終まとまりで、カード枠が外周を担当するため）。
                     多頭飼いの案内（.form-text）は <x-form-error> の下に置くと、エラー時に欄との
                     間に案内文が挟まるが、Bootstrap の .is-invalid ~ .invalid-feedback の
                     「以後の兄弟」セレクタで案内文を挟んでも表示される（パスワードと同じ扱い）。 --}}
                <div class="mb-3">
                    <label for="trainee_note" class="form-label">備考</label>
                    <textarea class="form-control @error('trainee_note') is-invalid @enderror"
                              id="trainee_note" name="trainee_note" rows="3">{{ old('trainee_note', $existingTrainee?->note) }}</textarea>
                    <x-form-error field="trainee_note" />
                    <div class="form-text">2頭目以降がいらっしゃる場合は、こちらにご記入ください。</div>
                </div>

                @include('layouts.partials.privacy-consent')

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">設定して進む</button>
                </div>
            </form>
        </div>
    </div>

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>

{{-- 住所検索スクリプト（郵便番号 → 住所自動入力）--}}
@vite(['resources/js/address-search.js'])
@endsection
