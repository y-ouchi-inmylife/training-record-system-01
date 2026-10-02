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

                {{-- 行3: 犬種 + 性別 + 誕生日 を 1 段に並べる。合計 12 で余白なく埋まる。
                     犬種は自由入力で幅を確保したいので col-md-5、性別は最も短いので col-md-3、
                     誕生日は Y-m-d 相当で col-md-4。ラベルは各項目内で form-label-fixed の
                     140px 固定枠に右寄せされる（各項目のまとまり幅が異なるため、
                     3 項目のラベル位置が同じ縦線に揃うわけではない）。 --}}
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
                            <label for="birth_date" class="col-md-auto col-form-label text-md-end form-label-fixed">誕生日</label>
                            <div class="col-12 col-md">
                                {{-- Flatpickr（.datepicker）は altInput を使っていないため、
                                     オリジナルの input がそのまま残る → is-invalid の兄弟セレクタで
                                     invalid-feedback が自動表示されるため block は付けない（3-1 と同じ）。 --}}
                                <input type="text" class="form-control datepicker @error('birth_date') is-invalid @enderror"
                                       id="birth_date" name="birth_date"
                                       value="{{ old('birth_date', $trainee?->birth_date?->format('Y-m-d')) }}"
                                       placeholder="例: 2020-05-01" pattern="\d{4}-\d{2}-\d{2}" maxlength="10"
                                       autocomplete="off">
                                <x-form-error field="birth_date" />
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 行4: 備考 --}}
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
