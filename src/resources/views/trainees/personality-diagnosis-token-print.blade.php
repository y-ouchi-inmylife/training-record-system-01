@extends('layouts.app')

@section('title', __('messages.personality_diagnosis_name').'のご案内')

@section('content')
{{-- 愛犬性格診断のご案内（S-0312。2026-10 追加。要件定義書 6-16-11）。会員詳細（S-0305）の［案内を表示］から開く。
     作りは受付アンケートのご案内（S-0311）と同じ（invitations/_panels）。S-0311 との違い：帯（文字・色・アイコン）、文面、
     有効な案内がないときの文。
     2026-10-18 変更：ユーザーレビューで、印刷して渡す作りをやめ、LINE・メールで送る・その場で読み取ってもらう 2 つの欄にした
     （S-0307 と同じ）。文面には会員の氏名と愛犬の呼び名を入れる（S-0311 と同じ）。
     変数：$trainee、$url（有効な案内がなければ null）、$copyText（同） --}}
<div class="container invitation-page">
    {{-- 戻る：会員詳細（S-0305。案内の操作は会員詳細だけで行うため）へのふつうのリンク --}}
    <div class="d-flex mb-3">
        <a href="{{ route('clients.show', $trainee->client_id) }}" class="btn btn-outline-secondary btn-sm">戻る</a>
    </div>

    {{-- 案内を見分ける帯＝ページの見出し（h1。紫）。有効な案内がないときも出す --}}
    <x-print-band :label="__('messages.personality_diagnosis_name').'のご案内'" variant="personality" icon="heart-pulse" />

    @if($url)
        @include('invitations._panels', [
            'copyText' => $copyText,
            'url' => $url,
            'qrLabel' => __('messages.personality_diagnosis_name').'の案内の QR コード',
        ])
    @else
        {{-- 有効な案内なし（未発行・取消済み・前の案内に回答済みのいずれでも同じ）。「ない」ことは本文（太字）で伝える --}}
        <p class="invitation-empty"><strong>有効な{{ __('messages.personality_diagnosis_name') }}の案内がありません。</strong></p>
    @endif
</div>
@endsection

@if($url)
    @push('scripts')
        @vite(['resources/js/qr-code.js', 'resources/js/copy-message.js'])
    @endpush
@endif
