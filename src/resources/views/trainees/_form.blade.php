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
                     名前は必須項目で他の任意項目より目立たせたく、値が短い（犬名はひらがな
                     数文字程度）ため単独段でも入力欄が間延びしすぎない。
                     会員フォーム clients/_form.blade.php の項目ごとの幅調整と同じ慣例。
                     md 未満では col-12 相当で全幅（Bootstrap の grid 既定挙動）。 --}}
                <div class="row g-3 mb-2">
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label for="name" class="col-md-auto col-form-label text-md-end form-label-fixed">
                                名前 <span class="text-danger">*</span>
                            </label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                       id="name" name="name" maxlength="50" required
                                       value="{{ old('name', $trainee?->name) }}" autocomplete="off">
                                <x-form-error field="name" />
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 行3: 犬種 + 性別 を 1 段に並べる。
                     犬種は自由入力で幅を確保したいので col-md-5、性別は最も短いので col-md-3。
                     ラベルは各項目内で form-label-fixed の 140px 固定枠に右寄せされる（各項目の
                     まとまり幅が異なるため、項目のラベル位置が同じ縦線に揃うわけではない）。
                     経緯：以前は 3 項目目に誕生日（col-md-4、Y-m-d 相当）を置き、合計 12 で埋まって
                     いた。2026-10 に誕生日を年・月・日の 3 つの欄にしたため、収まらず独立した行に
                     移した（screen-design.md S-0308）。空いた col-md-4 には去勢・避妊を置く予定。 --}}
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
                </div>

                {{-- 行4: 誕生日（独立した全幅の行。2026-10 変更）。年・月・日の 3 つの欄で、分かるところだけ
                     入れる（要件定義書 6-16「年齢の表示規則」の「入力のルール」）。
                     - 欄は type="text" inputmode="numeric"（スマホで数字のキーボードを出す。type="number" は
                       ホイールで値が変わる・「e」が入るなどのため使わない）。年は 4 桁、月・日は 2 桁まで
                     - エラーは 3 つの欄のまとまりの下に 1 つだけ（キー birth_date。§2-7 の但し書き）。
                       エラーがあるときは 3 つの欄すべてを赤枠にする。欄の兄弟の位置ではないので block を付ける
                     - flatpickr（.datepicker）は使わない（以前は 1 つの日付の欄で使っていた）
                     - 入力中の年齢表示は段階2b で、単位の右に足す --}}
                <div class="row g-3 mb-2">
                    <div class="col-12">
                        <div class="row g-2 align-items-start">
                            <label for="birth_year" id="birth_date-label" class="col-md-auto col-form-label text-md-end form-label-fixed">誕生日</label>
                            <div class="col-12 col-md">
                                <div class="d-flex flex-wrap align-items-center gap-2" role="group" aria-labelledby="birth_date-label">
                                    <div class="d-flex align-items-center gap-1">
                                        <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                                               id="birth_year" name="birth_year" inputmode="numeric" maxlength="4"
                                               style="width: 5.5rem;" aria-label="誕生日の年" aria-describedby="birth_date-help"
                                               value="{{ old('birth_year', $trainee?->birth_year) }}" autocomplete="off">
                                        <span>年</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                                               id="birth_month" name="birth_month" inputmode="numeric" maxlength="2"
                                               style="width: 4rem;" aria-label="誕生日の月" aria-describedby="birth_date-help"
                                               value="{{ old('birth_month', $trainee?->birth_month) }}" autocomplete="off">
                                        <span>月</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                                               id="birth_day" name="birth_day" inputmode="numeric" maxlength="2"
                                               style="width: 4rem;" aria-label="誕生日の日" aria-describedby="birth_date-help"
                                               value="{{ old('birth_day', $trainee?->birth_day) }}" autocomplete="off">
                                        <span>日</span>
                                    </div>
                                </div>
                                <x-form-error field="birth_date" block />
                                <div id="birth_date-help" class="form-text">分かるところだけで構いません。年はおおよそでも大丈夫です。</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 行5: 備考 --}}
                <div class="row g-3">
                    <div class="col-12">
                        <div class="row g-2 align-items-start">
                            <label for="note" class="col-md-auto col-form-label text-md-end form-label-fixed">備考</label>
                            <div class="col-12 col-md">
                                <textarea class="form-control @error('note') is-invalid @enderror"
                                          id="note" name="note" rows="3">{{ old('note', $trainee?->note) }}</textarea>
                                <x-form-error field="note" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
