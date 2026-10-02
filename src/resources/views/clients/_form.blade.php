{{-- クライアント登録・編集共通フォーム --}}
{{-- 変数:
     - $client       : ?App\Models\Client   新規時は null、編集時は Client モデル
     - $trainers     : Collection<Trainer>  主担当プルダウン用（実務トレーナーのみ）
     - $action       : string               フォーム送信先 URL
     - $method       : 'POST' | 'PUT'       PUT のときのみ @method('PUT') を出す
     - $submitLabel  : string               送信ボタン文言（例: '登録' / '更新'）
     - $cancelUrl    : string               キャンセル遷移先 URL
     - $pageTitle    : string               画面見出し（h2）文言
--}}
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">{{ $pageTitle }}</h2>
        <div class="d-flex gap-2">
            <a href="{{ $cancelUrl }}" class="btn btn-secondary js-leave-link">キャンセル</a>
            <button type="submit" form="clientForm" class="btn btn-success">{{ $submitLabel }}</button>
        </div>
    </div>

    {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
         required・maxlength・type・pattern・inputmode などの属性は残す（入力制限・
         スマホのキーボード・支援技術への必須の伝達のため）。
         onsubmit の validateBeforeSubmit は段階 3-1 のコミット 3（JS の入力チェックを
         削除）で外す。 --}}
    <form method="POST" action="{{ $action }}" id="clientForm" novalidate
          onkeydown="if(event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') { event.preventDefault(); }"
          onsubmit="return validateBeforeSubmit()">
        @csrf
        @if($method === 'PUT')
            @method('PUT')
        @endif

        {{-- 画面上部の 1 文の案内（設計書 §2-7）。個々のエラーは各欄の下に出す --}}
        <x-form-error-summary />

        {{-- カテゴリー1: 基本情報 --}}
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">基本情報</h6></div>
            <div class="card-body">
                <div class="row g-3 mb-2">
                    {{-- 行1: (編集時のみ 内部ID +) 初回日 + 主担当 --}}
                    @if($client)
                        <div class="col-md-3">
                            <div class="row g-2 align-items-center">
                                <label for="internal_id" class="col-md-auto col-form-label text-md-end form-label-fixed">
                                    内部ID <span class="text-danger">*</span>
                                </label>
                                <div class="col-12 col-md">
                                    <input type="text" class="form-control @error('internal_id') is-invalid @enderror"
                                           id="internal_id" name="internal_id"
                                           value="{{ old('internal_id', $client->internal_id) }}" maxlength="10" required
                                           autocomplete="off">
                                    <x-form-error field="internal_id" />
                                </div>
                            </div>
                        </div>
                    @endif
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="initial_consultation_date" class="col-md-auto col-form-label text-md-end form-label-fixed">
                                初回日 <span class="text-danger">*</span>
                            </label>
                            <div class="col-12 col-md">
                                {{-- Flatpickr（.datepicker）は altInput を使っていないため、
                                     オリジナルの input がそのまま残る → is-invalid の兄弟セレクタで
                                     invalid-feedback が自動表示されるため block は付けない。 --}}
                                <input type="text" class="form-control datepicker @error('initial_consultation_date') is-invalid @enderror"
                                       id="initial_consultation_date" name="initial_consultation_date"
                                       value="{{ old('initial_consultation_date', $client?->initial_consultation_date?->format('Y-m-d')) }}" required
                                       placeholder="例: 2000-01-15" pattern="\d{4}-\d{2}-\d{2}" maxlength="10"
                                       autocomplete="off">
                                <x-form-error field="initial_consultation_date" />
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="primary_trainer_id" class="col-md-auto col-form-label text-md-end form-label-fixed">主担当</label>
                            <div class="col-12 col-md">
                                <select class="form-select @error('primary_trainer_id') is-invalid @enderror"
                                        id="primary_trainer_id" name="primary_trainer_id" autocomplete="off">
                                    <option value=""></option>
                                    @foreach($trainers as $trainer)
                                        <option value="{{ $trainer->id }}" {{ old('primary_trainer_id', $client?->primary_trainer_id) == $trainer->id ? 'selected' : '' }}>
                                            {{ $trainer->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-form-error field="primary_trainer_id" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-2">
                    {{-- 行2: 名前(姓+名) + ふりがな(せい+めい) --}}
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label for="last_name" class="col-md-auto col-form-label text-md-end form-label-fixed">
                                名前 <span class="text-danger">*</span>
                            </label>
                            <div class="col-12 col-md">
                                <div class="row g-2">
                                    <div class="col-6">
                                        {{-- 姓は required 属性なし（JS で検証していた名残）。段階 3-1 で
                                             novalidate を付けたので、サーバーの検証に一本化した結果、
                                             required 属性があってもなくても挙動は同じ。属性は現状維持。 --}}
                                        <input type="text" class="form-control @error('last_name') is-invalid @enderror"
                                               id="last_name" name="last_name" inputmode="text"
                                               value="{{ old('last_name', $client?->last_name) }}" placeholder="姓"
                                               autocomplete="off">
                                        <x-form-error field="last_name" />
                                    </div>
                                    <div class="col-6">
                                        <input type="text" class="form-control @error('first_name') is-invalid @enderror"
                                               id="first_name" name="first_name" inputmode="text"
                                               value="{{ old('first_name', $client?->first_name) }}" placeholder="名"
                                               autocomplete="off">
                                        <x-form-error field="first_name" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="row g-2 align-items-center">
                            <label for="last_name_kana" class="col-md-auto col-form-label text-md-end form-label-fixed">ふりがな</label>
                            <div class="col-12 col-md">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="text" class="form-control @error('last_name_kana') is-invalid @enderror"
                                               id="last_name_kana" name="last_name_kana" inputmode="hiragana"
                                               value="{{ old('last_name_kana', $client?->last_name_kana) }}" placeholder="せい"
                                               autocomplete="off">
                                        <x-form-error field="last_name_kana" />
                                    </div>
                                    <div class="col-6">
                                        <input type="text" class="form-control @error('first_name_kana') is-invalid @enderror"
                                               id="first_name_kana" name="first_name_kana" inputmode="hiragana"
                                               value="{{ old('first_name_kana', $client?->first_name_kana) }}" placeholder="めい"
                                               autocomplete="off">
                                        <x-form-error field="first_name_kana" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- カテゴリー2: 連絡先 --}}
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">連絡先</h6></div>
            <div class="card-body">
                <div class="row g-3 mb-2">
                    {{-- 行1: 郵便番号+住所検索 + 都道府県 + 市区町村 --}}
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="postal_code" class="col-md-auto col-form-label text-md-end form-label-fixed">郵便番号</label>
                            <div class="col-12 col-md">
                                {{-- input-group の最後の子に invalid-feedback を置き、親に has-validation
                                     を付けると、Bootstrap 5 のセレクタ
                                     `.input-group.has-validation > .form-control.is-invalid:nth-last-child(n+3) ~ .invalid-feedback`
                                     で表示される。block は付けない。 --}}
                                <div class="input-group has-validation">
                                    <input type="text" class="form-control @error('postal_code') is-invalid @enderror"
                                           id="postal_code" name="postal_code" value="{{ old('postal_code', $client?->postal_code) }}"
                                           autocomplete="off">
                                    <button type="button" class="btn btn-outline-secondary" id="btn-search-address" onclick="searchAddress()">検索</button>
                                    <x-form-error field="postal_code" />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="address1" class="col-md-auto col-form-label text-md-end form-label-fixed">都道府県</label>
                            <div class="col-12 col-md">
                                <select class="form-select @error('address1') is-invalid @enderror"
                                        id="address1" name="address1" autocomplete="off">
                                    <option value=""></option>
                                    @foreach(config('prefectures') as $pref)
                                        <option value="{{ $pref }}" {{ old('address1', $client?->address1) == $pref ? 'selected' : '' }}>{{ $pref }}</option>
                                    @endforeach
                                </select>
                                <x-form-error field="address1" />
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="address2" class="col-md-auto col-form-label text-md-end form-label-fixed">市区町村</label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control @error('address2') is-invalid @enderror"
                                       id="address2" name="address2" inputmode="text"
                                       value="{{ old('address2', $client?->address2) }}" autocomplete="off">
                                <x-form-error field="address2" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-2">
                    {{-- 行2: 町名・番地 + 建物名・部屋番号 --}}
                    <div class="col-md-6">
                        <div class="row g-2 align-items-center">
                            <label for="address3" class="col-md-auto col-form-label text-md-end form-label-fixed">町名・番地</label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control @error('address3') is-invalid @enderror"
                                       id="address3" name="address3" inputmode="text"
                                       value="{{ old('address3', $client?->address3) }}" autocomplete="off">
                                <x-form-error field="address3" />
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="row g-2 align-items-center">
                            <label for="address4" class="col-md-auto col-form-label text-md-end form-label-fixed">建物名・部屋番号</label>
                            <div class="col-12 col-md">
                                <input type="text" class="form-control @error('address4') is-invalid @enderror"
                                       id="address4" name="address4" inputmode="text"
                                       value="{{ old('address4', $client?->address4) }}" autocomplete="off">
                                <x-form-error field="address4" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-2">
                    {{-- 行3: 電話番号 + 電話番号（予備） --}}
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="phone1" class="col-md-auto col-form-label text-md-end form-label-fixed">電話番号</label>
                            <div class="col-12 col-md">
                                <input type="tel" class="form-control @error('phone1') is-invalid @enderror"
                                       id="phone1" name="phone1" value="{{ old('phone1', $client?->phone1) }}" placeholder="例: 090-1234-5678"
                                       autocomplete="off">
                                <x-form-error field="phone1" />
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-2 align-items-center">
                            <label for="phone2" class="col-md-auto col-form-label text-md-end form-label-fixed">電話番号（予備）</label>
                            <div class="col-12 col-md">
                                <input type="tel" class="form-control @error('phone2') is-invalid @enderror"
                                       id="phone2" name="phone2" value="{{ old('phone2', $client?->phone2) }}" placeholder="例: 090-1234-5678"
                                       autocomplete="off">
                                <x-form-error field="phone2" />
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 行4: メールアドレス
                     クライアント自身がメールアドレス登録用 URL から登録する項目のため
                     トレーナーは入力・書き換えできない（設計書 D3、S-0301／S-0306）。
                       - 登録画面（$client === null）: 項目自体を出さない
                       - 編集画面（$client あり）    : 現在値を表示のみ（未登録なら空欄）
                     バリデーションからも外しているため、送信されても保存されない --}}
                @if($client)
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="row g-2 align-items-center">
                                <label class="col-md-auto col-form-label text-md-end form-label-fixed">メールアドレス</label>
                                <div class="col-12 col-md">
                                    <div class="form-control-plaintext">{{ $client->email }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- ボタン --}}
        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="{{ $cancelUrl }}" class="btn btn-secondary js-leave-link">キャンセル</a>
            <button type="submit" class="btn btn-success">{{ $submitLabel }}</button>
        </div>
    </form>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // 未保存変更警告
        new window.UnsavedChangesGuard({
            formSelector: '#clientForm',
            leaveLinkSelector: '.js-leave-link'
        }).init();
    });

    // フィールドエラー表示
    function showFieldError(fieldId, message) {
        var field = document.getElementById(fieldId);
        field.classList.add('is-invalid');
        var errorDiv = field.parentElement.querySelector('.invalid-feedback');
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.className = 'invalid-feedback';
            field.parentElement.appendChild(errorDiv);
        }
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
    }

    function clearFieldError(fieldId) {
        var field = document.getElementById(fieldId);
        field.classList.remove('is-invalid');
        var errorDiv = field.parentElement.querySelector('.invalid-feedback');
        if (errorDiv) errorDiv.style.display = 'none';
    }

    // 送信時バリデーション（姓必須・ふりがなのひらがなチェック・メール形式）
    function validateBeforeSubmit() {
        let valid = true;

        // 姓必須
        clearFieldError('last_name');
        if (!document.getElementById('last_name').value.trim()) {
            showFieldError('last_name', '姓は必須です。');
            valid = false;
        }

        // ふりがな（ひらがな）
        const hiraganaRegex = /^[ぁ-んー\s　]*$/;
        [{id: 'last_name_kana', label: 'せい'}, {id: 'first_name_kana', label: 'めい'}].forEach(function (f) {
            clearFieldError(f.id);
            const v = document.getElementById(f.id).value.trim();
            if (v !== '' && !hiraganaRegex.test(v)) {
                showFieldError(f.id, f.label + 'はひらがなで入力してください。');
                valid = false;
            }
        });

        return valid;
    }

    // 住所検索（郵便番号→zipcloud）は resources/js/address-search.js に切り出し。
    // ページに読み込むと window.searchAddress が定義され、
    // <button onclick="searchAddress()"> から呼び出せる。
</script>
@vite(['resources/js/address-search.js'])
@endpush
