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
    // 暮らしの様子の 4 欄の初期値（2026-10-19 追加。設計書 S-1413「初期値」）：前回の回答（最新の回答。答え直しのとき）の答え。
    // 前回の回答がなければ空。入力エラーで戻ったときは、上と同じくお客様が入力した値だけを使う
    $initialAnswer = fn (string $column) => $hasOldInput ? old($column) : $previousResponse?->{$column};
@endphp

@section('title', __('messages.intake_survey_name'))

@section('content')
{{-- 愛犬アンケート回答画面（S-1413。2026-10 追加。要件定義書 6-15-17）。
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
    {{-- 愛犬アンケート＆愛犬性格診断の案内なら、進み具合（1/2）を見出しの上に出す（2026-10-19 追加） --}}
    @if($showProgress ?? false)
        @include('client._survey-progress', ['step' => 1])
    @endif
    <h1 class="mb-2">{{ __('messages.intake_survey_name') }}</h1>
    {{-- 説明文は lang/ja/messages.php の client_intake_survey.description（2026-10-19 変更：契約前のお客様も答えるため、完了の画面の文とそろえた） --}}
    <p class="mb-4">{{ __('messages.client_intake_survey.description') }}</p>

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
                @include('client._trainee-basic-fields', [
                    'initial' => $initial,
                    'sexLabels' => $sexLabels,
                    'neuterStatusLabels' => $neuterStatusLabels,
                    // 去勢・避妊が愛犬の基本情報の最後。下に区切り線を引く（「暮らしの様子」との境目）
                    'lastFieldClass' => 'mb-3 pb-3 border-bottom',
                ])

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
                                  id="{{ $column }}" name="{{ $column }}" rows="4" maxlength="2000">{{ $initialAnswer($column) }}</textarea>
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
