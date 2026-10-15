@extends('layouts.client-public')

@php
    $companyName = config('app.client_portal_company');
    // 性別・去勢・避妊の選択肢はトレーナー側と同じ（2026-10 変更。当初は性別に「不明」がなく、
    // 去勢・避妊は「分からない」だった）
    $sexLabels = \App\Models\Trainee::sexLabels();
    $neuterStatusLabels = \App\Models\Trainee::neuterStatusLabels();
    // 愛犬の基本情報の 5 項目の初期値（2026-10 追加。設計書 S-1413「初期値」）：ページを開いた時点の
    // トレーニーの値。入力エラーで戻ったときは、お客様が入力した値だけを使う（old()。消した欄・選ばなかった
    // ラジオボタンにトレーニーの値を戻さないため、old() の第 2 引数にトレーニーの値を渡す形にはしない）
    $hasOldInput = session()->hasOldInput();
    $initial = fn (string $key) => $hasOldInput ? old($key) : $trainee->{$key};
@endphp

@section('title', '受付アンケート')

@section('content')
{{-- 受付アンケート回答画面（S-1413。2026-10 追加。要件定義書 6-15-17）。
     画面の作りは S-1403 初回設定（client/setup/index.blade.php）にそろえる：.c-login--wide、見出しはカードの外、
     入力欄はカードの中に縦一列、<h2 class="fs-6 fw-bold mb-2"> の小見出しは「暮らしの様子」の 1 つだけ（fs-6 を外さないこと。
     理由は S-1403 の「お名前」小見出しのコメント参照）、区切り線は愛犬の基本情報／「暮らしの様子」の境目の 1 本だけ
     （濃さは client.scss の .c-login--wide .border-bottom）。
     - 見出しの下に説明文を置く（S-1413 だけの例外。QR コードから初めて開くお客様に、何のページかを
       伝えるため）。愛犬の名前は使わず、常に「愛犬」（2026-10 変更。トレーナーが電話などで聞いて
       入れた名前が間違っていると、お客様に間違った名前を見せることになるため。当初は会員側の呼び名
       Trainee::client_call_name で「モモちゃん」／「愛犬」と出し分けていた）
     - 会員の氏名・連絡先・住所は出さない（ログインしない画面で、URL を知っていれば開けるため）
     - 所要時間の目安は書かない（設計書 S-1413） --}}
<div class="c-login c-login--wide">
    <h1 class="mb-2">受付アンケート</h1>
    <p class="mb-4">愛犬のことを教えてください。トレーニングの参考にさせていただきます。</p>

    <div class="card c-login-card">
        <div class="card-body p-4">
            {{-- 入力エラーの上部案内（設計書 §2-7） --}}
            <x-form-error-summary />

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
            <form method="POST" action="{{ route('client-portal.intake-survey.store', ['token' => $token]) }}" novalidate>
                @csrf

                {{-- 愛犬の基本情報には小見出しを置かず、カードは名前の欄から始まる（2026-10 変更。見出しの下の説明文
                     「愛犬のことを教えてください。…」と重なるため。当初は小見出し「愛犬のこと」を置いていた）。
                     愛犬の基本情報の 5 項目は、トレーナーが入れた値を初期値にした入力欄。お客様が直して送ると、
                     回答の値でトレーニーを上書きする（空・未選択の項目は変えない。要件定義書 6-15-17。2026-10 変更）。
                     名前は常にテキスト入力で必須。ラベルは「名前」（「お名前」は飼い主の氏名に使っているため）。
                     経緯：当初は、名前が登録済みなら表示のみ（S-1403 のメールアドレスの表示行と同じマークアップ）、
                     未登録ならテキスト入力で必須にしていた（入力済みの値は上書きしない反映のルールだったため） --}}
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

                {{-- 去勢・避妊が愛犬の基本情報の最後。下に区切り線を引く（「暮らしの様子」との境目） --}}
                <div class="mb-3 pb-3 border-bottom">
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

                {{-- 小見出し「暮らしの様子」の同じ行の後ろに、控えめなカッコ書き（太字にしない・少し小さく・色を落とす。
                     fw-normal small text-muted）。カッコ書きは見出しの一部として読まれる。2026-10 変更：当初は小見出しの
                     下に「どれも任意です。思いつくことを自由にお書きください。」の 1 行を置いていた（入力欄に必須の「*」が
                     ないので、任意であることは伝わる。設計書 S-1413） --}}
                <h2 class="fs-6 fw-bold mb-3">暮らしの様子<span class="fw-normal small text-muted">（思いつくことをご自由にお書きください。）</span></h2>

                {{-- 自由記述 4 欄。設問文（ラベル）は TraineeIntakeSurveyResponse::questions() の 1 か所から
                     （S-0309 の回答の表示と共用）。rows="4" で、縦方向にだけ広げられる
                     （textarea の resize: vertical は Bootstrap の既定〔_reboot.scss〕）。
                     - 設問の先頭の「●」は見た目の印。aria-hidden="true" の要素にして読み上げでは読まない
                       （ラベルの読み上げは設問文だけになる）。ラベルを d-flex にし、設問文を別の要素にして、
                       折り返したときに 2 行目が「●」の右から始まるようにする（ぶら下げ）。2026-10 追加
                     - 例文は出さない（2026-10 変更。お客様の回答を例文に誘導してしまうおそれがあるため。当初は
                       入力欄の下に例文 2 つを .form-text で出していた） --}}
                @foreach($questions as $column => $question)
                    <div class="mb-3">
                        <label for="{{ $column }}" class="form-label d-flex"><span class="me-1" aria-hidden="true">●</span><span>{{ $question['question'] }}</span></label>
                        <textarea class="form-control @error($column) is-invalid @enderror"
                                  id="{{ $column }}" name="{{ $column }}" rows="4" maxlength="2000">{{ old($column) }}</textarea>
                        <x-form-error :field="$column" />
                    </div>
                @endforeach

                @include('layouts.partials.privacy-consent')

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">送信する</button>
                </div>
            </form>
        </div>
    </div>

    @if($companyName)
        <p class="c-login-footer">&copy; {{ date('Y') }} {{ $companyName }}</p>
    @endif
</div>

{{-- 誕生日の入力中の年齢表示（トレーナー側と同じ部品） --}}
@vite(['resources/js/trainee-birthday-input.js'])
@endsection
