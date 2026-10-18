@extends('layouts.app')

@section('title', '受付アンケートのご案内')

@section('content')
{{-- 受付アンケートのご案内（S-0311。2026-10 追加。要件定義書 6-16-8）。会員詳細（S-0305）の［受付アンケートの案内を表示］から開く。
     作りはマイページ登録のご案内（S-0307）と同じ（invitations/_panels）。S-0307 との違い：有効期限を持たない
     （代わりに「1回かぎり」の文）、帯の色（緑）、文面。
     2026-10-18 変更：ユーザーレビューで、印刷して渡す作りをやめ、LINE・メールで送る・その場で読み取ってもらう 2 つの欄にした
     （S-0307 と同じ）。文面には会員の氏名と愛犬の呼び名を入れる（その前は、紙をなくしたときに氏名と URL が一緒に漏れないよう、
     どちらも載せていなかった。印刷しなくなったため）。
     変数：$trainee、$url（有効な案内がなければ null）、$copyText（同） --}}
<div class="container invitation-page">
    {{-- 戻る：会員詳細（S-0305。案内の操作は会員詳細だけで行うため）へのふつうのリンク --}}
    <div class="d-flex mb-3">
        <a href="{{ route('clients.show', $trainee->client_id) }}" class="btn btn-outline-secondary btn-sm">戻る</a>
    </div>

    {{-- 案内を見分ける帯＝ページの見出し（h1。緑）。有効な案内がないときも出す --}}
    <x-print-band label="受付アンケートのご案内" variant="intake" icon="clipboard-check" />

    @if($url)
        @include('invitations._panels', [
            'copyText' => $copyText,
            'url' => $url,
            'qrLabel' => '受付アンケート案内の QR コード',
            'qrNote' => __('messages.invitation_message.once'),
        ])
    @else
        {{-- 有効な案内なし（未発行・取消済み・回答済みのいずれでも同じ）。「ない」ことは本文（太字）で伝える --}}
        <p class="invitation-empty"><strong>有効な受付アンケート案内がありません。</strong></p>
    @endif
</div>
@endsection

@if($url)
    @push('scripts')
        @vite(['resources/js/qr-code.js', 'resources/js/copy-message.js'])
    @endpush
@endif
