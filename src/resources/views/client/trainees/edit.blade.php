@extends('layouts.client')

@php
    $callName = $trainee->client_call_name;
    $sexLabels = \App\Models\Trainee::sexLabels();
    $neuterStatusLabels = \App\Models\Trainee::neuterStatusLabels();
    // 初期値：トレーニーの今の値。入力エラーで戻ったときは、入力した値だけを使う（愛犬アンケートの回答画面と同じ。
    // 消した欄・選ばなかったラジオボタンにトレーニーの値を戻さないため、old() の第 2 引数は使わない）
    $hasOldInput = session()->hasOldInput();
    $initial = fn (string $key) => $hasOldInput ? old($key) : $trainee->{$key};
@endphp

@section('title', $callName.'の情報の変更')

@section('content')
{{-- 会員の愛犬の情報の変更画面（S-1417。2026-10-18 追加。要件定義書 6-15-20）。
     作り（戻る導線・カードの外の見出し・カード・フル幅の［変更する］）は S-1411 登録情報の変更（client/settings/edit）にそろえる。
     入力欄は愛犬アンケート（S-1413）と同じ部品（client/_trainee-basic-fields）。暮らしの様子・写真は変えない。
     保存すると登録情報（S-1406）に戻り、layouts.client の共通の受け皿に完了メッセージを出す --}}
<div class="container">
    <div class="c-settings">
        {{-- 戻る導線：「← 戻る」（S-1409/S-1410/S-1411 と同じ形）。保存せずに登録情報（S-1406）へ戻る --}}
        <a href="{{ route('client-portal.profile.show') }}" class="c-detail-back">
            <span aria-hidden="true">←</span> 戻る
        </a>

        <h1 class="mb-4">{{ $callName }}の情報の変更</h1>

        <div class="card mb-4">
            <div class="card-body p-4">
                {{-- 入力エラーの上部案内（設計書 §2-7） --}}
                <x-form-error-summary />

                {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
                <form method="POST" action="{{ route('client-portal.trainees.update', $trainee) }}" novalidate>
                    @csrf
                    @method('PUT')

                    @include('client._trainee-basic-fields', [
                        'initial' => $initial,
                        'sexLabels' => $sexLabels,
                        'neuterStatusLabels' => $neuterStatusLabels,
                    ])

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">変更する</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- 誕生日の入力中の年齢表示（トレーナー側・愛犬アンケートと同じ部品） --}}
@vite(['resources/js/trainee-birthday-input.js'])
@endsection
