@extends('layouts.client')

@php
    $prefectures = config('prefectures');
    // フォームは 1 画面 1 フォームのため名前付きエラーバッグは使わない。
    // 完了メッセージは共通キー session('success') に統一して、layouts.client の
    // 共通受け皿で画面上部に表示する（設計書 §4-10 参照）。
@endphp

@section('title', '登録情報の変更')

@section('content')
<div class="container">
    <div class="c-settings">
        {{-- 戻る導線：「← 戻る」の形で置く（3 変更画面 S-1409/S-1410/S-1411 で共通）。
             画面名を書かないのは、将来この画面への入口が増えても文言を直さずに済むため。
             矢印は装飾なので aria-hidden にし、「戻る」の文字を読み上げソフトに読ませる。
             設計書：`client-portal-design-plan.md` §4-10、`screen-design.md` §6。 --}}
        <a href="{{ route('client-portal.profile.show') }}" class="c-detail-back">
            <span aria-hidden="true">←</span> 戻る
        </a>

        <h1 class="mb-4">登録情報の変更</h1>

        <div class="card mb-4">
            <div class="card-body p-4">
                {{-- 入力エラーの上部案内（必須・形式・文字数などの入力エラー。設計書 §2-7）。
                     本画面はコントローラーで入力エラー以外の失敗（送信失敗など）を withErrors で返していないため、
                     現状は入力項目のキーだけが来る。将来追加した場合に備えて hasAny で絞る。 --}}
                @if ($errors->hasAny([
                    'postal_code', 'address1', 'address2', 'address3', 'address4',
                    'phone1', 'phone2', 'emergency_contact_relationship',
                ]))
                    <x-form-error-summary />
                @endif

                {{-- お名前は表示のみ（変更は担当トレーナーに依頼）。案内文は添えない --}}
                <div class="mb-3">
                    <div class="text-muted small">お名前</div>
                    <div>
                        {{ $client->full_name }}@if($client->full_name_kana)<span class="text-muted small">（{{ $client->full_name_kana }}）</span>@endif
                    </div>
                </div>

                {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
                <form method="POST" action="{{ route('client-portal.profile.update') }}" novalidate>
                    @csrf
                    @method('PUT')

                    {{-- 並びは住所 → 電話番号 → 緊急連絡先（2026-10-18 変更：登録情報の表示〔S-1406〕の順にそろえた。
                         その前は電話番号・緊急連絡先が住所より前）。Tab キーで移る順も見た目の順と同じ（tabindex で順を変えない） --}}
                    {{-- 住所（都道府県・市区町村・町名番地）・電話番号の必須は、契約か利用があるかで決める（$contactRequired。2026-10-18 変更：
                         ユーザーレビューで、契約が登録された会員は預かりが決まっているので入れてもらう。契約がなければ初回設定と同じく任意。
                         その前はいつも必須。2026-10-28 変更：利用がある会員も、トレーニングを実施しているので緊急時の連絡先が要る。その前は契約だけを見ていた）。必須のときだけ必須の印「*」と required を付ける（ClientProfileRequest と同じ決まり） --}}
                    <div class="mb-2">
                        {{-- 郵便番号は任意（住所検索の入口という位置づけで、DB も NULL 許容。
                             都道府県以下が入っていれば住所として成立する。設計書 S-1411 備考参照）。
                             入力された場合の形式チェック（7 桁）は FormRequest で維持している。 --}}
                        <label for="postal_code" class="form-label">郵便番号</label>
                        {{-- input-group 内で .invalid-feedback を効かせるには .has-validation を付ける
                             （Bootstrap 5 の仕様）。<x-form-error> は input-group の末尾（検索ボタンの後）に
                             置く。住所検索の結果メッセージ（#address-search-message）は input-group の
                             外（下の兄弟）に置き、欄下エラーと重ならないようにする。 --}}
                        <div class="input-group has-validation">
                            <input type="text" class="form-control @error('postal_code') is-invalid @enderror"
                                   id="postal_code" name="postal_code"
                                   value="{{ old('postal_code', $client->postal_code) }}"
                                   placeholder="123-4567">
                            <button type="button" class="btn btn-outline-secondary"
                                    id="btn-search-address" onclick="searchAddress()">検索</button>
                            <x-form-error field="postal_code" />
                        </div>
                        {{-- address-search.js がここに検索の結果メッセージを書き込む（alert 非使用）--}}
                        <div id="address-search-message" class="form-text" role="status"></div>
                    </div>

                    <div class="mb-2">
                        <label for="address1" class="form-label">都道府県@if($contactRequired) <span class="text-danger">*</span>@endif</label>
                        <select class="form-select @error('address1') is-invalid @enderror"
                                id="address1" name="address1" @required($contactRequired)>
                            <option value="">選択してください</option>
                            @foreach($prefectures as $pref)
                                <option value="{{ $pref }}" @selected(old('address1', $client->address1) === $pref)>{{ $pref }}</option>
                            @endforeach
                        </select>
                        <x-form-error field="address1" />
                    </div>
                    <div class="mb-2">
                        <label for="address2" class="form-label">市区町村@if($contactRequired) <span class="text-danger">*</span>@endif</label>
                        <input type="text" class="form-control @error('address2') is-invalid @enderror"
                               id="address2" name="address2" @required($contactRequired) maxlength="50"
                               value="{{ old('address2', $client->address2) }}">
                        <x-form-error field="address2" />
                    </div>
                    <div class="mb-2">
                        <label for="address3" class="form-label">町名・番地@if($contactRequired) <span class="text-danger">*</span>@endif</label>
                        <input type="text" class="form-control @error('address3') is-invalid @enderror"
                               id="address3" name="address3" @required($contactRequired) maxlength="100"
                               value="{{ old('address3', $client->address3) }}">
                        <x-form-error field="address3" />
                    </div>
                    <div class="mb-3">
                        <label for="address4" class="form-label">建物名・部屋番号</label>
                        <input type="text" class="form-control @error('address4') is-invalid @enderror"
                               id="address4" name="address4" maxlength="100"
                               value="{{ old('address4', $client->address4) }}">
                        <x-form-error field="address4" />
                    </div>

                    <div class="mb-2">
                        <label for="phone1" class="form-label">電話番号@if($contactRequired) <span class="text-danger">*</span>@endif</label>
                        <input type="tel" class="form-control @error('phone1') is-invalid @enderror"
                               id="phone1" name="phone1" @required($contactRequired) maxlength="20"
                               value="{{ old('phone1', $client->phone1) }}">
                        <x-form-error field="phone1" />
                    </div>
                    {{-- 緊急連絡先（ご家族等）：見出し（legend）の下に「電話番号」「続柄」を横に並べる（7：5。スマホの幅でも横に並べ、576px 未満は 6：6。
                         375px の幅では 7：5 だと続柄の欄が狭く、例の文「例: 母、夫、娘」が欄に収まらなかったため）。
                         fieldset と legend で、読み上げでは「緊急連絡先（ご家族等）」の中の「電話番号」「続柄」と読まれる。
                         legend は Bootstrap の既定で大きな文字・左に浮くので、form-label と fs-6 で欄のラベルと同じ大きさにする。
                         2026-10-18 変更：その前（2026-10-17）は「緊急連絡先（ご家族等）」「続柄」を縦一列の別々の項目にしていたが、
                         続柄が緊急連絡先の方の続柄だとわからなかった --}}
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6 mb-1">緊急連絡先（ご家族等）</legend>
                        <div class="row g-2">
                            <div class="col-6 col-sm-7">
                                <label for="phone2" class="form-label small mb-1">電話番号</label>
                                <input type="tel" class="form-control @error('phone2') is-invalid @enderror"
                                       id="phone2" name="phone2" maxlength="20"
                                       value="{{ old('phone2', $client->phone2) }}">
                                <x-form-error field="phone2" />
                            </div>
                            <div class="col-6 col-sm-5">
                                <label for="emergency_contact_relationship" class="form-label small mb-1">続柄</label>
                                <input type="text" class="form-control @error('emergency_contact_relationship') is-invalid @enderror"
                                       id="emergency_contact_relationship" name="emergency_contact_relationship" maxlength="20"
                                       value="{{ old('emergency_contact_relationship', $client->emergency_contact_relationship) }}" placeholder="例: 母、夫、娘">
                                <x-form-error field="emergency_contact_relationship" />
                            </div>
                        </div>
                    </fieldset>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">変更する</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- 住所検索スクリプト（郵便番号 → 住所自動入力）--}}
@vite(['resources/js/address-search.js'])
@endsection
