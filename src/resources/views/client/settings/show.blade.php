@extends('layouts.client')

@php
    // 住所を 2 行に組み立てる。
    // 1 行目：〒郵便番号 + 都道府県 + 市区町村 + 町名・番地（各要素は入力があれば繋げる）
    // 2 行目：建物名・部屋番号（入力があるときのみ）
    // §2-4 に従い、未入力の要素は詰めて、空白や記号を残さない。
    $addressLine1Parts = array_filter([
        $client->postal_code ? '〒' . $client->postal_code : null,
        $client->address1,
        $client->address2,
        $client->address3,
    ], fn ($v) => $v !== null && $v !== '');
    $addressLine1 = implode(' ', $addressLine1Parts);
    $addressLine2 = $client->address4;
    $hasAddress = $addressLine1 !== '' || $addressLine2;
@endphp

@section('title', '登録情報')

@section('content')
<div class="container">
    <div class="c-settings">
        <h1 class="mb-4">登録情報</h1>

        {{-- 登録内容の表示。縦一列（ラベル上・値下）で、項目と項目の間だけに罫線を引く。
             お客様側の確認・表示画面の標準（設計書 client-portal-design-plan.md §2-3）。
             カードの枠が外周を担当しているので、カード内側の上端・下端に線を引くと役割が重複する。
             そのためラッパーの border-top と最終項目の border-bottom は付けない。
             未入力値の扱いは §2-4 のセル形式に従い、ラベルを残し値を空にする。
             各行の値エリアには min-height を持たせて、値が空でも行高が保たれるようにする。
             並び順の先頭にメールアドレスを置くのは、メールアドレスがログイン ID を兼ねているため
             （設計書 screen-design.md S-1406 備考参照）。 --}}
        <div class="card mb-4">
            <div class="card-body p-4">
                <div>
                    <div class="py-3 border-bottom">
                        <div class="text-muted small mb-1">メールアドレス</div>
                        <div style="min-height: 1.5rem;">{{ $client->email }}</div>
                    </div>
                    <div class="py-3 border-bottom">
                        <div class="text-muted small mb-1">お名前</div>
                        <div style="min-height: 1.5rem;">{{ $client->full_name }}</div>
                    </div>
                    <div class="py-3 border-bottom">
                        <div class="text-muted small mb-1">お名前（かな）</div>
                        <div style="min-height: 1.5rem;">{{ $client->full_name_kana }}</div>
                    </div>
                    <div class="py-3 border-bottom">
                        <div class="text-muted small mb-1">住所</div>
                        <div style="min-height: 1.5rem;">
                            @if($hasAddress)
                                @if($addressLine1 !== ''){{ $addressLine1 }}@endif
                                @if($addressLine2)<br>{{ $addressLine2 }}@endif
                            @endif
                        </div>
                    </div>
                    <div class="py-3 border-bottom">
                        <div class="text-muted small mb-1">電話番号</div>
                        <div style="min-height: 1.5rem;">{{ $client->phone1 }}</div>
                    </div>
                    {{-- 緊急連絡先（ご家族等）は「電話番号（続柄）」の 1 つの値で出す（Client::emergency_contact_display。
                         2026-10-18 変更：その前は「続柄」を別の行に出していたが、緊急連絡先の方の続柄だとわからなかった） --}}
                    <div class="py-3">
                        <div class="text-muted small mb-1">緊急連絡先（ご家族等）</div>
                        <div style="min-height: 1.5rem;">{{ $client->emergency_contact_display }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 3 つの入口ボタンは同格。フル幅の .btn-primary（明るい紺 #2A4A94）で揃える。
             並び順：メールアドレス → パスワード → 登録情報（設計書 §4-10 参照）。
             上の表示項目の並び（メールアドレスを先頭）とボタンの並びを対応させるため。 --}}
        <div class="d-grid gap-2">
            <a href="{{ route('client-portal.settings.email.edit') }}" class="btn btn-primary">メールアドレスを変更</a>
            <a href="{{ route('client-portal.settings.password.edit') }}" class="btn btn-primary">パスワードを変更</a>
            <a href="{{ route('client-portal.profile.edit') }}" class="btn btn-primary">登録情報を変更</a>
        </div>

        {{-- 愛犬の情報（2026-10-18 追加。設計書 S-1406「愛犬の情報」・要件定義書 6-15-20）。
             - 愛犬ごとのカード。並びはマイページの愛犬のカードと同じ（Client::trainees() の id 昇順）。愛犬がいなければ欄ごと出さない
             - カードの中は上の登録内容と同じ形（縦一列、ラベル上・値下、項目の間だけに罫線、空の値はラベルを残して値を空に）
             - 値の出し方は会員詳細（S-0305）と同じアクセサ。性別は項目名を添えるので「不明」も出す。写真は出さない
             - 暮らしの様子は出さない（2026-10-19 変更：4 問を愛犬性格診断へ移したため。その前は登録内容の下に愛犬アンケートの 4 問と答えを出していた）
             - カードの下に［{呼び名}の情報を変更］（上の 3 つの入口ボタンと同じ見た目）→ S-1417 --}}
        @if($trainees->isNotEmpty())
            <h2 class="h4 mt-5 mb-3">愛犬の情報</h2>
            @foreach($trainees as $trainee)
                @php
                    $profileItems = [
                        '名前' => $trainee->name,
                        '犬種' => $trainee->breed,
                        '性別' => $trainee->sex_label,
                        '誕生日' => $trainee->birthday_with_age_label,
                        '去勢・避妊' => $trainee->neuter_status_label,
                    ];
                @endphp
                <div class="card mb-2" data-client-trainee-info>
                    <div class="card-body p-4">
                        <h3 class="h5 mb-0">{{ $trainee->client_call_name }}</h3>
                        @foreach($profileItems as $label => $value)
                            {{-- 罫線は項目の間だけ（2026-10-19 変更：暮らしの様子を外したので、最後の去勢・避妊の下には引かない） --}}
                            <div class="{{ $loop->last ? 'pt-3' : 'py-3 border-bottom' }}">
                                <div class="text-muted small mb-1">{{ $label }}</div>
                                <div style="min-height: 1.5rem;">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="d-grid {{ $loop->last ? '' : 'mb-4' }}">
                    <a href="{{ route('client-portal.trainees.edit', $trainee) }}" class="btn btn-primary">{{ $trainee->client_call_name }}の情報を変更</a>
                </div>
            @endforeach
        @endif
    </div>
</div>
@endsection
