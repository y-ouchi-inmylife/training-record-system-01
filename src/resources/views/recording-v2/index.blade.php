@extends('layouts.app')

@section('title', '録音準備')

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
@endpush

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">録音準備</h4>
                </div>
                <div class="card-body">
                    {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                         本画面は小さなフォーム（入力は会員だけ）のため、§2-7「画面上部の短い案内」の
                         例外として <x-form-error-summary /> は置かない。 --}}
                    <form action="{{ route('recording-v2.start') }}" method="POST" novalidate>
                        @csrf

                        <!-- クライアント選択（Select2） -->
                        <div class="mb-3">
                            <label class="form-label" for="client-select">会員 <span class="text-danger">*</span></label>
                            <select name="client_id" class="form-select select2-client @error('client_id') is-invalid @enderror" id="client-select" required>
                                <option value="">会員を検索...</option>
                            </select>
                            {{-- Select2 は <select> を隠して .select2-container を描画するため、
                                 .form-control.is-invalid ~ .invalid-feedback の兄弟セレクタが効かない。
                                 block を付けて invalid-feedback d-block で確実に表示する（§2-7）。 --}}
                            <x-form-error field="client_id" block />
                        </div>

                        <div class="alert alert-info">
                            <strong>注意:</strong> 録音実行へ進むと、録音が完了してログアウトするまで他の画面に移動できなくなります。
                        </div>

                        <!-- 録音開始ボタン -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                録音実行へ進む
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// 送信前の JS の独自のチェックは行わない（設計書 §2-7）。会員の未選択はサーバーの検証に任せる
// （段階 5-2 で削除。以前はここで submit を preventDefault して .client-id-error を表示していた）。
$(document).ready(function() {
    var $clientSelect = $('#client-select');

    $clientSelect.select2({
        theme: 'bootstrap-5',
        placeholder: '会員を検索（内部ID、名前、かな）',
        allowClear: false,
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
});
</script>
@endpush
