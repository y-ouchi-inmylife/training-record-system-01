@extends('layouts.app')

@php
    $screenName = __('messages.personality_diagnosis_name').'の評価文プロンプト';
@endphp

@section('title', $screenName)

@section('content')
{{-- 愛犬性格診断の評価文プロンプト（S-0602。2026-10-19 追加。管理者のみ）。要約プロンプト（S-0601。settings/summary-prompts）と同じ作り：
     見出しの右に［更新］、上に使い方の案内、指示文の入力欄（5000 文字以内。文字数カウンタは上限を超えると赤。入力は止めない）、
     未保存の直しがあるときの離脱の確認。違うところ：使える差し込みの印の一覧と、「#####」で分けて出すよう頼む説明を出す --}}
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4" style="max-width: 700px;">
                <h2 class="mb-0">{{ $screenName }}</h2>
                <div class="d-flex gap-2">
                    <button type="submit" form="evaluationPromptForm" class="btn btn-success">更新</button>
                </div>
            </div>

            <div class="alert alert-info" style="max-width: 700px;" data-evaluation-prompt-guide>
                <p class="mb-2">このプロンプトは、{{ __('messages.personality_diagnosis_name') }}詳細の［AIで下書き］で、AI が評価文とスタッフ用メモの下書きを作る際の「指示書」です。差し込みの印は、その診断の結果のデータに置き換えて AI に渡します。</p>
                <p class="mb-2">お客様用とスタッフ用は「#####」で分けて出すよう指示してください（「#####」の前が評価文、後ろがスタッフ用メモの欄に入ります）。</p>
                <p class="mb-1">使える差し込みの印：</p>
                <ul class="mb-0 small" data-evaluation-prompt-placeholders>
                    @foreach($placeholders as $placeholder => $description)
                        <li><code>{{ $placeholder }}</code>：{{ $description }}</li>
                    @endforeach
                </ul>
            </div>

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
            <form id="evaluationPromptForm" method="POST" action="{{ route('settings.evaluation-prompts.update') }}" novalidate>
                @csrf
                @method('PUT')

                <x-form-error-summary />

                <div class="mb-3">
                    <label for="evaluation_prompt" class="visually-hidden">{{ $screenName }}</label>
                    <textarea class="form-control @error('evaluation_prompt') is-invalid @enderror"
                              id="evaluation_prompt"
                              name="evaluation_prompt"
                              inputmode="text"
                              rows="14"
                              required
                              style="font-family: monospace; max-width: 700px;">{{ old('evaluation_prompt', $currentPrompt) }}</textarea>
                    <x-form-error field="evaluation_prompt" />
                    <div class="form-text text-muted" id="evaluation-prompt-char-counter" style="max-width: 700px;"></div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    var textarea = document.getElementById('evaluation_prompt');
    // 文字数カウンタの上限（サーバー側の max:5000 と一致させる）
    const MAX = {{ \App\Services\EvaluationDraft\PersonalityEvaluationPrompt::MAX_LENGTH }};
    var counter = document.getElementById('evaluation-prompt-char-counter');

    function updateCharCounter() {
        var length = textarea.value.length;
        counter.textContent = length + ' / ' + MAX;
        var over = length > MAX;
        counter.classList.toggle('text-danger', over);
        counter.classList.toggle('text-muted', !over);
    }

    textarea.addEventListener('input', updateCharCounter);
    updateCharCounter();

    // 未保存変更警告
    new window.UnsavedChangesGuard({
        formSelector: '#evaluationPromptForm',
        leaveLinkSelector: '.js-leave-link'
    }).init();
});
</script>
@endpush
