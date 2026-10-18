{{-- 愛犬の基本情報（名前・犬種・性別・誕生日・去勢・避妊）の入力欄（2026-10-18 に愛犬アンケートの回答画面から切り出した）。
     愛犬アンケートの回答画面（S-1413。client/intake-survey/show）と、会員の愛犬の情報の変更画面（S-1417。
     client/trainees/edit）で共用する。入力のきまりも同じ（ClientTraineeProfileRequest::basicInfoRules()）。
     <form> の中に置き、誕生日の入力中の年齢の表示のため、呼び出し側のページで trainee-birthday-input.js を読み込む。
     - $initial：項目の値を返す関数（fn (string $key)）。トレーニーの今の値、入力エラーで戻ったときは入力した値だけ
     - $sexLabels・$neuterStatusLabels：性別・去勢・避妊の選択肢（Trainee::sexLabels()・neuterStatusLabels()）
     - $lastFieldClass：最後の欄（去勢・避妊）の class。愛犬アンケートは「暮らしの様子」との境目の区切り線を付ける
       （mb-3 pb-3 border-bottom）。省略すると mb-3 --}}
<div class="mb-3">
    <label for="name" class="form-label">名前 <span class="text-danger">*</span></label>
    <input type="text" class="form-control @error('name') is-invalid @enderror"
           id="name" name="name" required maxlength="50" autocomplete="off"
           value="{{ $initial('name') }}">
    <x-form-error field="name" />
</div>

<div class="mb-3">
    <label for="breed" class="form-label">犬種</label>
    <input type="text" class="form-control @error('breed') is-invalid @enderror"
           id="breed" name="breed" maxlength="100" autocomplete="off"
           value="{{ $initial('breed') }}">
    <x-form-error field="breed" />
</div>

{{-- 性別・去勢・避妊はラジオボタン（会員側で初めて使う部品。押せる範囲を 44px 相当にする
     client.scss の .c-choice）。選択肢は 1 行に横並びにし、幅が足りなければ選択肢ごと次の行に折り返す
     （2026-10 変更。当初は縦に 1 つずつ並べていた）。選ばなくてもよく、選んだものを外す手段は設けない。
     エラーはまとまりの下に出す（欄の兄弟の位置ではないので block。§2-7 の但し書き） --}}
<div class="mb-3">
    <div id="sex-label" class="form-label">性別</div>
    <div class="c-choice" role="radiogroup" aria-labelledby="sex-label">
        @foreach($sexLabels as $value => $label)
            <div class="form-check">
                <input class="form-check-input @error('sex') is-invalid @enderror" type="radio"
                       name="sex" id="sex-{{ $value }}" value="{{ $value }}"
                       @checked($initial('sex') === $value)>
                <label class="form-check-label" for="sex-{{ $value }}">{{ $label }}</label>
            </div>
        @endforeach
    </div>
    <x-form-error field="sex" block />
</div>

{{-- 誕生日：トレーナー側（trainees/_form.blade.php）と同じ 3 つの欄・同じ説明文・入力中の年齢の表示
     （trainee-birthday-input.js。欄と表示先は data-birthday-* 属性で見つける）。
     エラーは 3 つの欄のまとまりの下に 1 つだけ（キー birth_date）。初期値（トレーニーの年・月・日）が
     入っているときも、ページを開いたときに JS が一度表示するので、年齢が出る --}}
<div class="mb-3">
    <label for="birth_year" id="birth_date-label" class="form-label">誕生日</label>
    <div class="d-flex flex-wrap align-items-center gap-2" role="group" aria-labelledby="birth_date-label" data-birthday-input>
        <div class="d-flex align-items-center gap-1">
            <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                   id="birth_year" name="birth_year" inputmode="numeric" maxlength="4" data-birthday-part="year"
                   style="width: 5.5rem;" aria-label="誕生日の年" aria-describedby="birth_date-help"
                   value="{{ $initial('birth_year') }}" autocomplete="off">
            <span>年</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                   id="birth_month" name="birth_month" inputmode="numeric" maxlength="2" data-birthday-part="month"
                   style="width: 4rem;" aria-label="誕生日の月" aria-describedby="birth_date-help"
                   value="{{ $initial('birth_month') }}" autocomplete="off">
            <span>月</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                   id="birth_day" name="birth_day" inputmode="numeric" maxlength="2" data-birthday-part="day"
                   style="width: 4rem;" aria-label="誕生日の日" aria-describedby="birth_date-help"
                   value="{{ $initial('birth_day') }}" autocomplete="off">
            <span>日</span>
        </div>
        {{-- 入力中の年齢（または入力中の案内）。JS が書き込む --}}
        <span class="ms-2" data-birthday-display aria-live="polite"></span>
    </div>
    <x-form-error field="birth_date" block />
    <div id="birth_date-help" class="form-text">※ 年だけ、または年と月だけでも入力できます。</div>
</div>

{{-- 去勢・避妊が最後の欄。下の余白・区切り線は呼び出し側から（$lastFieldClass） --}}
<div class="{{ $lastFieldClass ?? 'mb-3' }}">
    <div id="neuter_status-label" class="form-label">去勢・避妊</div>
    <div class="c-choice" role="radiogroup" aria-labelledby="neuter_status-label">
        @foreach($neuterStatusLabels as $value => $label)
            <div class="form-check">
                <input class="form-check-input @error('neuter_status') is-invalid @enderror" type="radio"
                       name="neuter_status" id="neuter_status-{{ $value }}" value="{{ $value }}"
                       @checked($initial('neuter_status') === $value)>
                <label class="form-check-label" for="neuter_status-{{ $value }}">{{ $label }}</label>
            </div>
        @endforeach
    </div>
    <x-form-error field="neuter_status" block />
</div>
