@extends('layouts.app')

@section('title', '要約プロンプト')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4" style="max-width: 700px;">
                <h2 class="mb-0">要約プロンプト</h2>
                <div class="d-flex gap-2">
                    <button type="submit" form="summaryPromptForm" class="btn btn-success">更新</button>
                </div>
            </div>

            {{-- プロンプト編集ガイド（利用者向け説明） --}}
            <div class="alert alert-info" style="max-width: 700px;">
                <p class="mb-2">このプロンプトは、AI が音声記録を要約する際の「指示書」です。ここに書いた内容がそのまま AI に渡され、要約の観点や形式を決めます。</p>
                <p class="mb-0">【要約項目】や【出力形式】などの見出しは、AI に分かりやすく指示するための文章です（システムが認識する特別なキーワードではありません）。項目を消してもシステムは止まりませんが、その観点は要約に反映されなくなります。要約してほしい観点は項目として残す・追加するのがコツです。</p>
            </div>

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 required 属性を足す（サーバーで必須のため。3-2 の姓と同じ扱い、支援技術への伝達）。
                 maxlength は付けない（文字数カウンタで上限表示するが、入力は止めない方針のため）。 --}}
            <form id="summaryPromptForm" method="POST" action="{{ route('settings.summary-prompts.update') }}" novalidate>
                @csrf
                @method('PUT')

                {{-- 画面上部の 1 文の案内（設計書 §2-7） --}}
                <x-form-error-summary />

                <div class="mb-3">
                    <textarea class="form-control @error('current_prompt') is-invalid @enderror"
                              id="current_prompt"
                              name="current_prompt"
                              inputmode="text"
                              rows="12"
                              required
                              style="font-family: monospace; max-width: 700px;">{{ old('current_prompt', $currentPrompt) }}</textarea>

                    {{-- 欄下のエラー表示（is-invalid の兄弟セレクタで自動表示される。文字数
                         カウンタはこの下にあるが、Bootstrap のセレクタは直後の兄弟セレクタ
                         ではなく「以後の兄弟」セレクタ（~）で invalid-feedback を見つける
                         ため、カウンタが挟まっても表示される。block は付けない）。 --}}
                    <x-form-error field="current_prompt" />

                    {{-- 文字数カウンタ（上限超過時は赤表示。入力自体はブロックしない） --}}
                    <div class="form-text text-muted" id="prompt-char-counter" style="max-width: 700px;"></div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    var textarea = document.getElementById('current_prompt');

    // 文字数カウンタの上限（サーバー側バリデーション max:5000 と一致させる）
    const MAX = 5000;
    var counter = document.getElementById('prompt-char-counter');

    function updateCharCounter() {
        var length = textarea.value.length;
        counter.textContent = length + ' / ' + MAX;
        // 上限超過時は赤く表示（入力はブロックしない）
        var over = length > MAX;
        counter.classList.toggle('text-danger', over);
        counter.classList.toggle('text-muted', !over);
    }

    // 入力に追従
    textarea.addEventListener('input', updateCharCounter);
    // 初期表示時にも現在の文字数を反映
    updateCharCounter();

    // 未保存変更警告
    new window.UnsavedChangesGuard({
        formSelector: '#summaryPromptForm',
        leaveLinkSelector: '.js-leave-link'
    }).init();
});
</script>
@endpush
