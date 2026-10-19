@extends('layouts.app')

@section('title', \App\Support\CombinedSurveyName::name().'のご案内')

@section('content')
{{-- 愛犬アンケート＆愛犬性格診断のご案内（S-0314。2026-10-19 追加。要件定義書 6-16-8・6-16-11）。会員詳細（S-0305）の「案内中」の
     ［表示］から開く（2 つとも回答待ちのとき）。作りは愛犬アンケートのご案内（S-0311）と同じ（invitations/_panels）。S-0311 との違い：
     帯（文字・2 色の塗り分け・アイコン）、文面、有効な案内がないときの文。渡すリンク・QR コードの中身は愛犬アンケートの案内のリンク
     （開くと愛犬アンケート → 愛犬性格診断 の順に続けて答えられる）。
     変数：$trainee、$url（2 つとも回答待ちでなければ null）、$copyText（同） --}}
<div class="container invitation-page">
    {{-- 戻る：会員詳細（S-0305。案内の操作は会員詳細だけで行うため）へのふつうのリンク --}}
    <div class="d-flex mb-3">
        <a href="{{ route('clients.show', $trainee->client_id) }}" class="btn btn-outline-secondary btn-sm">戻る</a>
    </div>

    {{-- 案内を見分ける帯＝ページの見出し（h1。左半分が緑・右半分が紫。アイコンはクリップボードとハート）。有効な案内がないときも出す --}}
    <x-print-band :label="\App\Support\CombinedSurveyName::name().'のご案内'" variant="combined" icon="clipboard-heart" />

    @if($url)
        @include('invitations._panels', [
            'copyText' => $copyText,
            'url' => $url,
            'qrLabel' => \App\Support\CombinedSurveyName::name().'の案内の QR コード',
        ])
    @else
        {{-- 有効な案内なし（未発行・取消済み・回答済み・愛犬アンケートだけ答えたあとのいずれでも同じ）。「ない」ことは本文（太字）で伝える --}}
        <p class="invitation-empty"><strong>有効な{{ \App\Support\CombinedSurveyName::name() }}の案内がありません。</strong></p>
    @endif
</div>
@endsection

@if($url)
    @push('scripts')
        @vite(['resources/js/qr-code.js', 'resources/js/copy-message.js'])
    @endpush
@endif
