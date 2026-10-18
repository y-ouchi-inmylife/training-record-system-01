{{-- トレーニー登録・編集の共通フォーム（S-0308 / S-0310） --}}
{{-- 変数:
     - $trainee      : ?App\Models\Trainee   新規時は null、編集時は Trainee モデル
     - $client       : App\Models\Client     紐づく会員（表示のみ・変更不可）
     - $action       : string                フォーム送信先 URL
     - $method       : 'POST' | 'PUT'        PUT のときのみ @method('PUT') を出す
     - $submitLabel  : string                送信ボタン文言（例: '登録' / '更新'）
     - $cancelUrl    : string                キャンセル遷移先 URL
     - $pageTitle    : string                画面見出し（h2）文言
--}}
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">{{ $pageTitle }}</h2>
        <div class="d-flex gap-2">
            <a href="{{ $cancelUrl }}" class="btn btn-secondary">キャンセル</a>
            <button type="submit" form="traineeForm" class="btn btn-success">{{ $submitLabel }}</button>
        </div>
    </div>

    {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
         required・maxlength・type・pattern などの属性は残す。 --}}
    <form method="POST" action="{{ $action }}" id="traineeForm" novalidate
          onkeydown="if(event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') { event.preventDefault(); }">
        @csrf
        @if($method === 'PUT')
            @method('PUT')
        @endif

        {{-- 画面上部の 1 文の案内（設計書 §2-7）。個々のエラーは各欄の下に出す --}}
        <x-form-error-summary />

        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">基本情報</h6></div>
            <div class="card-body">
                {{-- 行1: 会員（表示のみ） --}}
                <div class="row g-3 mb-2">
                    <div class="col-md-8">
                        <div class="row g-2 align-items-center">
                            <label class="col-md-auto col-form-label text-md-end form-label-fixed">会員</label>
                            <div class="col-12 col-md">
                                <div class="form-control-plaintext">{{ $client->full_name }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 行2: 名前を単独の段に置く（col-md-5）。幅は行3 の先頭（犬種、col-md-5）と
                     揃え、1 段目と 2 段目で左端の項目の入力欄の右端が縦に揃うようにする。
                     値が短い（犬名はひらがな数文字程度）ため単独段でも入力欄が間延びしすぎない。
                     経緯：当初は名前が必須項目で、他の任意項目より目立たせるために単独の段にした。
                     2026-10 に名前を任意にした（愛犬アンケートの案内だけを先に発行する運用のため）が、
                     トレーニーを指し示す目印として目立たせる意味は変わらないため、単独の段のままにする。
                     会員フォーム clients/_form.blade.php の項目ごとの幅調整と同じ慣例。
                     md 未満では col-12 相当で全幅（Bootstrap の grid 既定挙動）。 --}}
                <div class="row g-3 mb-2">
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label for="name" class="col-md-auto col-form-label text-md-end form-label-fixed">
                                名前
                            </label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                       id="name" name="name" maxlength="50"
                                       value="{{ old('name', $trainee?->name) }}" autocomplete="off">
                                <x-form-error field="name" />
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 行3: 犬種 + 性別 + 去勢・避妊 を 1 段に並べる。合計 12 で余白なく埋まる。
                     犬種は自由入力で幅を確保したいので col-md-5、性別は最も短いので col-md-3、
                     去勢・避妊は col-md-4（性別の右の空いた枠。screen-design.md S-0308）。
                     ラベルは各項目内で form-label-fixed の 140px 固定枠に右寄せされる（各項目の
                     まとまり幅が異なるため、項目のラベル位置が同じ縦線に揃うわけではない）。
                     経緯：以前は 3 項目目に誕生日（col-md-4、Y-m-d 相当）を置き、合計 12 で埋まって
                     いた。2026-10 に誕生日を年・月・日の 3 つの欄にしたため、収まらず独立した行に
                     移した（screen-design.md S-0308）。空いた col-md-4 に去勢・避妊（2026-10 追加）を置いた。
                     md の幅ではラベルが 140px 固定のため、性別・去勢・避妊の入力欄は狭くなる。 --}}
                <div class="row g-3 mb-2">
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label for="breed" class="col-md-auto col-form-label text-md-end form-label-fixed">犬種</label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control @error('breed') is-invalid @enderror"
                                       id="breed" name="breed" maxlength="100"
                                       value="{{ old('breed', $trainee?->breed) }}" autocomplete="off">
                                <x-form-error field="breed" />
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="row g-2 align-items-center">
                            <label for="sex" class="col-md-auto col-form-label text-md-end form-label-fixed">性別</label>
                            <div class="col-12 col-md">
                                <select class="form-select @error('sex') is-invalid @enderror"
                                        id="sex" name="sex" autocomplete="off">
                                    <option value=""></option>
                                    @foreach(\App\Models\Trainee::sexLabels() as $value => $label)
                                        <option value="{{ $value }}" {{ old('sex', $trainee?->sex) === $value ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-form-error field="sex" />
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="neuter_status" class="col-md-auto col-form-label text-md-end form-label-fixed">去勢・避妊</label>
                            <div class="col-12 col-md">
                                <select class="form-select @error('neuter_status') is-invalid @enderror"
                                        id="neuter_status" name="neuter_status" autocomplete="off">
                                    <option value=""></option>
                                    @foreach(\App\Models\Trainee::neuterStatusLabels() as $value => $label)
                                        <option value="{{ $value }}" {{ old('neuter_status', $trainee?->neuter_status) === $value ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-form-error field="neuter_status" />
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 行4: 誕生日（独立した全幅の行。2026-10 変更。最後の行なので下の余白〔mb-2〕は付けない。2026-10 にトレーニーの備考〔行5〕をなくした）。年・月・日の 3 つの欄で、分かるところだけ
                     入れる（要件定義書 6-16「年齢の表示規則」の「入力のルール」）。
                     - 欄は type="text" inputmode="numeric"（スマホで数字のキーボードを出す。type="number" は
                       ホイールで値が変わる・「e」が入るなどのため使わない）。年は 4 桁、月・日は 2 桁まで
                     - エラーは 3 つの欄のまとまりの下に 1 つだけ（キー birth_date。§2-7 の但し書き）。
                       エラーがあるときは 3 つの欄すべてを赤枠にする。欄の兄弟の位置ではないので block を付ける
                     - flatpickr（.datepicker）は使わない（以前は 1 つの日付の欄で使っていた）
                     - 入力中の年齢（または入力中の案内）を「日」の右に出す（trainee-birthday-input.js。2026-10 追加）。
                       欄と表示先は data-birthday-* 属性で見つける。aria-live="polite" で読み上げにも伝える。
                       年齢は本文と同じ色、入力中の案内は控えめな色（text-muted。JS で付け外し）で、
                       送信時のエラー（下の赤字・赤枠）とは別に出す
                     - 全角の数字は、欄から離れたとき（blur）に半角に書き換える（送信時も半角に直して受け付ける） --}}
                <div class="row g-3">
                    <div class="col-12">
                        <div class="row g-2 align-items-start">
                            <label for="birth_year" id="birth_date-label" class="col-md-auto col-form-label text-md-end form-label-fixed">誕生日</label>
                            <div class="col-12 col-md">
                                <div class="d-flex flex-wrap align-items-center gap-2" role="group" aria-labelledby="birth_date-label" data-birthday-input>
                                    <div class="d-flex align-items-center gap-1">
                                        <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                                               id="birth_year" name="birth_year" inputmode="numeric" maxlength="4" data-birthday-part="year"
                                               style="width: 5.5rem;" aria-label="誕生日の年" aria-describedby="birth_date-help"
                                               value="{{ old('birth_year', $trainee?->birth_year) }}" autocomplete="off">
                                        <span>年</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                                               id="birth_month" name="birth_month" inputmode="numeric" maxlength="2" data-birthday-part="month"
                                               style="width: 4rem;" aria-label="誕生日の月" aria-describedby="birth_date-help"
                                               value="{{ old('birth_month', $trainee?->birth_month) }}" autocomplete="off">
                                        <span>月</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                                               id="birth_day" name="birth_day" inputmode="numeric" maxlength="2" data-birthday-part="day"
                                               style="width: 4rem;" aria-label="誕生日の日" aria-describedby="birth_date-help"
                                               value="{{ old('birth_day', $trainee?->birth_day) }}" autocomplete="off">
                                        <span>日</span>
                                    </div>
                                    {{-- 入力中の年齢（または入力中の案内）。JS が書き込む --}}
                                    <span class="ms-2" data-birthday-display aria-live="polite"></span>
                                </div>
                                <x-form-error field="birth_date" block />
                                <div id="birth_date-help" class="form-text">※ 年だけ、または年と月だけでも入力できます。</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

{{-- 誕生日の入力中の年齢表示（2026-10 追加）--}}
@vite(['resources/js/trainee-birthday-input.js'])
