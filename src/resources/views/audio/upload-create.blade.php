@extends('layouts.app')

@section('title', '音声記録登録（音声ファイルのアップロード）')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">音声記録登録（音声ファイルのアップロード）</h2>
        <div class="d-flex gap-2">
            <a href="{{ route('audio-records.index') }}" class="btn btn-secondary">キャンセル</a>
            <button type="submit" form="uploadForm" class="btn btn-success">登録</button>
        </div>
    </div>

    {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
         本画面は小さなフォーム（入力は会員とファイルだけ）のため、§2-7「画面上部の短い案内」の
         例外として <x-form-error-summary /> は置かない。
         required・accept などの属性は残す。 --}}
    <form id="uploadForm" method="POST" action="{{ route('audio-records.upload.store') }}" enctype="multipart/form-data" novalidate>
        @csrf

        {{-- クライアント --}}
        <div class="mb-3">
            <label for="upload_client_id" class="form-label">
                会員 <span class="text-danger">*</span>
            </label>
            <select name="client_id" id="upload_client_id"
                    class="form-select select2-client-upload @error('client_id') is-invalid @enderror" required>
                <option value="">会員を検索...</option>
            </select>
            {{-- Select2 は <select> を隠すため兄弟セレクタで欄下文言が出ない。block を付ける。 --}}
            <x-form-error field="client_id" block />
        </div>

        {{-- 音声ファイル --}}
        <div class="mb-3">
            <label for="file" class="form-label">音声ファイル <span class="text-danger">*</span></label>
            <input type="file" name="file" id="file"
                   class="form-control @error('file') is-invalid @enderror"
                   accept=".mp3,.m4a,.wav,.mp4,.webm" required>
            {{-- サーバー側のエラーの表示（§2-7「欄の下」）。block を付けて invalid-feedback d-block で
                 表示する。送信前の JS のサイズ確認（#file-client-error）とは別の要素のまま残し、
                 JS 側（下の inline script）で選び直し時に #file-server-error を非表示にして
                 二重表示を防ぐため、ID を保つ。<x-form-error> は id 属性を受け取らないので直書き。 --}}
            @error('file')
                <div class="invalid-feedback d-block" id="file-server-error">{{ $message }}</div>
            @enderror
            {{-- 送信前のブラウザ側バリデーション（サイズの確認。§2-7 の例外）用エラー枠。
                 JS から文言を書き込んで表示する。見た目は欄の下と揃える（invalid-feedback d-block）。 --}}
            <div class="invalid-feedback d-none" id="file-client-error"></div>
            <div class="form-text">
                対応形式: MP3, M4A, WAV, MP4, WebM（最大100MB）
            </div>
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
    $('.select2-client-upload').select2({
        theme: 'bootstrap-5',
        placeholder: '会員を検索（内部ID、名前、かな）',
        allowClear: true,
        width: '100%',
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
            $('.select2-client-upload').append(option).trigger('change');
        }
    });
    @endif

    // サーバ側の上限を SSoT として渡す。JS 側に数値を直接書かない。
    const MAX_FILE_SIZE = @json(\App\Models\AudioRecord::MAX_FILE_SIZE);
    const MAX_FILE_SIZE_MB = MAX_FILE_SIZE / 1024 / 1024;

    const fileInput = document.getElementById('file');
    const fileErrorEl = document.getElementById('file-client-error');
    const uploadForm = document.getElementById('uploadForm');

    // 選択・送信時にファイルの大きさを確かめる。上限内なら true。
    function checkFileSize() {
        const file = fileInput.files[0];
        if (file && file.size > MAX_FILE_SIZE) {
            // 大きさは 1MB = 1024 × 1024 バイトで計算し、小数点以下1桁で切り上げる。
            // 四捨五入や切り捨てだと、上限をわずかに超えたファイル（例：100.04MB）が
            // 「100.0MB」と表示され、「100MBを超えている」という文と矛盾するため。
            const fileSizeMB = (Math.ceil(file.size / 1024 / 1024 * 10) / 10).toFixed(1);
            fileInput.classList.add('is-invalid');
            fileErrorEl.textContent = '選択したファイル（' + fileSizeMB + 'MB）は' + MAX_FILE_SIZE_MB + 'MBを超えているため、登録できません。';
            fileErrorEl.classList.remove('d-none');
            return false;
        }
        // 上限内、またはファイル未選択なら、JS 側のエラー表示は消す。
        fileInput.classList.remove('is-invalid');
        fileErrorEl.textContent = '';
        fileErrorEl.classList.add('d-none');
        return true;
    }

    fileInput.addEventListener('change', function() {
        // 前回の送信で表示されているサーバー側のエラーは、ファイルを選び直した時点で
        // 消して、ブラウザ側のチェック結果だけを表示する（二重表示の防止）。
        const serverErrorEl = document.getElementById('file-server-error');
        if (serverErrorEl) {
            serverErrorEl.classList.add('d-none');
        }
        checkFileSize();
    });
    uploadForm.addEventListener('submit', function(e) {
        if (!checkFileSize()) {
            e.preventDefault();
        }
    });
});
</script>
@endpush
