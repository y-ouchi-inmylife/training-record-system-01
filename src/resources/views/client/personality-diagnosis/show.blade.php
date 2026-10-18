@extends('layouts.client-public')

@php
    $companyName = config('app.client_portal_company');
    // 選んだ状態に戻す値：入力エラーで戻ったときは old()、時間切れ（419）で出し直したときは送られてきた回答
    // （正しい値だけ。bootstrap/app.php で絞り込み済み）。どちらもなければ、どれも選ばない
    $selected = fn (string $id) => old('answers.'.$id, $restoredAnswers[$id] ?? null);
    // 答えていない問（エラーがあり、送られた値がない問）。上部の案内の数と、最初の未回答の問へのリンクに使う
    $unanswered = collect(array_keys($questions))
        ->filter(fn (string $id) => $errors->has('answers.'.$id) && old('answers.'.$id) === null)
        ->values();
@endphp

@section('title', __('messages.personality_diagnosis_name'))

@section('content')
{{-- 性格診断回答画面（S-1415。2026-10 追加。要件定義書 6-15-18）。
     画面の作りは愛犬アンケートの回答画面（S-1413。client/intake-survey/show.blade.php）にそろえる：.c-login--wide、
     見出しはカードの外、見出しの下に説明文、本文はカード、プライバシーポリシー同意文、［送信する］、フッター。
     - 愛犬の名前は使わず、常に「愛犬」（愛犬アンケートと同じ理由。トレーナーが電話などで聞いて入れた名前は間違っているかも
       しれないため）。会員の氏名・連絡先・住所も出さない
     - 7 カテゴリの小見出し（<h2 class="fs-6 fw-bold mb-2">。S-1413 の小見出しと同じ）で区切り、50 問を Q01 から順に並べる。
       設問の文・選択肢は App\Support\PersonalityDiagnosisQuestions から（設問の版 v1）
     - 各問は <fieldset>、設問の文を <legend> にする（読み上げで、どの問の選択肢か分かるように）。選択肢は client.scss の
       .c-scale-choice（ボタンのような枠。中身は本物のラジオボタンなので、矢印キーで選べる）。初期値はなし
     - 答えていない問のエラーは、問の下に「選んでください。」（fieldset の aria-describedby で問に結びつける）。上部には
       「まだ答えていない質問が N 問あります。」と最初の未回答の問へのリンク
     - 所要時間の目安は書かない（設計書 S-1415） --}}
<div class="c-login c-login--wide">
    <h1 class="mb-2">{{ __('messages.personality_diagnosis_name') }}</h1>
    <p class="mb-4">愛犬についてお答えください。ふだんの様子に近いものを選んでください。その状況になったことがなければ「未経験」を選んでください。</p>

    <div class="card c-login-card">
        <div class="card-body p-4">
            @if($expired)
                {{-- 時間切れ（419）で出し直したときの知らせ（未回答のエラーは出さない。設計書 S-1415 備考） --}}
                <div class="alert alert-warning" role="alert">時間がたったため送信できませんでした。答えはそのまま残っています。もう一度［送信する］を押してください。</div>
            @elseif($unanswered->isNotEmpty())
                {{-- 入力エラーの上部案内（設計書 S-1415「バリデーションチェック」。§2-7 の 1 文の案内の代わりに、
                     未回答の数と最初の未回答の問へのリンクを出す） --}}
                <div class="alert alert-danger" role="alert">
                    まだ答えていない質問が {{ $unanswered->count() }} 問あります。
                    <a href="#q-{{ $unanswered->first() }}" class="alert-link">最初の未回答の質問へ</a>
                </div>
            @else
                <x-form-error-summary />
            @endif

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
            <form method="POST" action="{{ route('client-portal.personality-diagnosis.store', ['token' => $token]) }}" novalidate>
                @csrf

                @foreach($questionIdsByCategory as $category => $ids)
                    <h2 class="fs-6 fw-bold mb-2 @if(! $loop->first) mt-4 @endif">{{ $categories[$category] }}</h2>
                    @foreach($ids as $id)
                        @php($hasError = $errors->has('answers.'.$id))
                        <fieldset class="mb-3" id="q-{{ $id }}" @if($hasError) aria-describedby="answers-{{ $id }}-error" @endif>
                            <legend class="fs-6 mb-2">{{ $questions[$id]['text'] }}</legend>
                            <div class="c-scale-choice @if($hasError) is-invalid @endif">
                                @foreach($choices as $value => $label)
                                    <input type="radio" class="btn-check" name="answers[{{ $id }}]"
                                           id="answer-{{ $id }}-{{ $value }}" value="{{ $value }}" autocomplete="off"
                                           @checked((string) $selected($id) === (string) $value)>
                                    <label class="c-scale-choice-label" for="answer-{{ $id }}-{{ $value }}">{{ $label }}</label>
                                @endforeach
                            </div>
                            @if($hasError)
                                <div class="invalid-feedback d-block" id="answers-{{ $id }}-error">{{ $errors->first('answers.'.$id) }}</div>
                            @endif
                        </fieldset>
                    @endforeach
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
@endsection
