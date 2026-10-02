@extends('layouts.app')

@section('title', '音声記録登録（文字起こしテキスト）')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">音声記録登録（文字起こしテキスト）</h2>
        <div class="d-flex gap-2">
            <a href="{{ route('audio-records.index') }}" class="btn btn-secondary">キャンセル</a>
            <button type="submit" form="textPasteForm" class="btn btn-success">登録</button>
        </div>
    </div>

    {{-- 入力エラーの上部案内（必須・形式などの入力エラー。設計書 §2-7）。本画面は入力項目が
         3 つ（会員・表示名・文字起こしテキスト）あるため、「小さなフォーム」の例外には当たらない。 --}}
    <x-form-error-summary />

    {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
         required・maxlength などの属性は残す。 --}}
    <form id="textPasteForm" method="POST" action="{{ route('audio-records.text-paste.store') }}" novalidate>
        @csrf

        {{-- クライアント --}}
        <div class="mb-3">
            <label for="client_id" class="form-label">
                会員 <span class="text-danger">*</span>
            </label>
            <select name="client_id" id="client_id" class="form-select select2-client @error('client_id') is-invalid @enderror" required>
                <option value="">会員を検索...</option>
            </select>
            {{-- Select2 は <select> を隠すため兄弟セレクタで欄下文言が出ない。block を付ける。 --}}
            <x-form-error field="client_id" block />
        </div>

        {{-- タイトル --}}
        <div class="mb-3">
            <label for="title" class="form-label">表示名 <span class="text-danger">*</span></label>
            <input type="text" name="title" id="title"
                   class="form-control @error('title') is-invalid @enderror"
                   value="{{ old('title', $defaultTitle) }}"
                   maxlength="255" required>
            <x-form-error field="title" />
        </div>

        {{-- 文字起こしテキスト --}}
        <div class="mb-3">
            <label for="transcription_text" class="form-label">文字起こしテキスト <span class="text-danger">*</span></label>
            <textarea name="transcription_text" id="transcription_text" rows="15"
                      class="form-control @error('transcription_text') is-invalid @enderror"
                      inputmode="text"
                      placeholder="外部で文字起こしした内容を貼り付けてください" required>{{ old('transcription_text') }}</textarea>
            <x-form-error field="transcription_text" />
        </div>
    </form>
</div>
@endsection

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('.select2-client').select2({
        theme: 'bootstrap-5',
        placeholder: '会員を検索（内部ID、名前、かな）',
        allowClear: true,
        ajax: {
            url: '/api/clients/search',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return { q: params.term };
            },
            processResults: function (data) {
                return { results: data.results };
            },
            cache: true
        },
        minimumInputLength: 1,
        language: {
            inputTooShort: function () { return '1文字以上入力してください'; },
            noResults: function () { return '該当する会員が見つかりません'; },
            searching: function () { return '検索中...'; }
        }
    });

    // バリデーションエラー後にクライアント選択を復元
    @if(old('client_id'))
    $.ajax({
        url: '/api/clients/search',
        data: { id: '{{ old('client_id') }}' },
        dataType: 'json'
    }).then(function(data) {
        if (data.results && data.results.length > 0) {
            var client = data.results[0];
            var option = new Option(client.text, client.id, true, true);
            $('.select2-client').append(option).trigger('change');
        }
    });
    @endif
});
</script>
@endpush
