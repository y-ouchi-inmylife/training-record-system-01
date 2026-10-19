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

@section('title', __('messages.intake_survey_name'))

@section('content')
{{-- 愛犬アンケート回答画面（S-1413。2026-10 追加。要件定義書 6-15-17）。
     画面の作りは S-1403 初回設定（client/setup/index.blade.php）にそろえる：.c-login--wide、見出しはカードの外、
     入力欄はカードの中に縦一列。小見出し・区切り線は置かない（2026-10-19 変更：自由記述の質問（4 問）を愛犬性格診断〔S-1415〕へ移し、
     愛犬アンケートは基本情報〔名前・犬種・性別・誕生日・去勢・避妊〕だけになったため。その前は小見出し「暮らしの様子」と、基本情報との
     境目の区切り線を置いていた）。
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
    {{-- 説明文は lang/ja/messages.php の client_intake_survey.description_sentences（2026-10-19 変更：契約前のお客様も答えるため、完了の画面の文とそろえた）。
         1 文ずつのまとまり（.c-sentence。inline-block）で出し、文の区切りで折り返す（2026-10-19 変更：375px で「ご提案」の途中で折れていたため。
         パソコンの幅では 2 文が 1 行に並ぶ）。まとまりの間に空白を入れない（日本語の文の間に空きができないように） --}}
    <p class="mb-4">@foreach(__('messages.client_intake_survey.description_sentences') as $sentence)<span class="c-sentence">{{ $sentence }}</span>@endforeach</p>

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
                    // 去勢・避妊が最後の欄（2026-10-19 変更：自由記述の質問を愛犬性格診断へ移したので、区切り線は引かない）
                ])

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
