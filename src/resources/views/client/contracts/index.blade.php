@extends('layouts.client')

@section('title', 'ご利用回数')

{{-- 会員のご利用回数画面（設計書 screen-design.md S-1412、要件定義書 6-15-16）。
     トレーナー側の回数カード（S-0305 セクション5）と同じ内容を、会員向けの言葉づかいで見るだけ表示する。
     - 読むだけ：見出し・マスは button にせず、onclick・data-*・モーダルは出さない
     - メモは一切出さない（契約のメモ・利用のメモ・メモの印。トレーナーが会員に見られることを
       意識せずに書くおそれがあるため）
     - 高さの制限・スクロールは付けない（ページ全体でスクロールする）
     - 値は Client\ContractController で ContractTicketService から組み立て済み（Blade では計算しない） --}}

@section('content')
<div class="container py-4 c-detail">
    {{-- 戻る導線：S-1404（トレーニング記録詳細）と同じ「HOME」（行き先を名指しするリンク。矢印は付けない） --}}
    <a href="{{ route('client-portal.dashboard') }}" class="c-detail-back">
        HOME
    </a>

    @if($isEmpty)
        <h1 class="c-detail-title">ご利用回数</h1>

        {{-- 契約も利用も 1 件もないとき（会員側の空の状態の書き方：.c-empty-card） --}}
        <section class="c-section c-empty-state" aria-label="空状態">
            <div class="c-empty-card">
                <p class="mb-0">回数のご契約はありません。</p>
            </div>
        </section>
    @else
        {{-- 見出しと全体の数を 1 行に並べる（ベースラインでそろえる。幅が足りなければ数は次の行へ折り返す）。
             数は h1 の外に置き、読み上げでは見出しを「ご利用回数」だけにする。
             全体の数は「あと n回」を大きめに。マイナスのときは文で伝える（赤字にしない） --}}
        <div class="c-ticket-heading">
            <h1 class="c-detail-title">ご利用回数</h1>
            <p class="c-ticket-total">
                <x-client-remaining-count :value="$remaining" />
            </p>
        </div>

        {{-- 契約ごとのカード（新しい契約が上）。押せない（読むだけ） --}}
        @foreach($contracts as $row)
            <section class="c-ticket" aria-label="{{ $row['contract']->contracted_on->format('Y/m/d') }} のご契約">
                <div class="c-ticket-head">
                    <span class="c-ticket-title"><span class="num-tabular">{{ $row['contract']->contracted_on->format('Y/m/d') }}</span> ご契約（<span class="num-tabular">{{ $row['contract']->count }}</span>回）</span>
                    {{-- 有効期限の日付は、期限を過ぎていて残りがあるとき（expired）だけ赤字（会員側の $danger＝$madder） --}}
                    @if($row['contract']->expires_on)
                        <span class="c-ticket-expires">有効期限 @if($row['expired'])<span class="text-danger num-tabular">{{ $row['contract']->expires_on->format('Y/m/d') }}</span>@else<span class="num-tabular">{{ $row['contract']->expires_on->format('Y/m/d') }}</span>@endif</span>
                    @endif
                    @if($row['usedUp'])
                        <span class="badge bg-secondary">すべてご利用済み</span>
                    @else
                        <span class="c-ticket-status">あと <span class="num-tabular">{{ $row['remaining'] }}</span>回</span>
                    @endif
                </div>
                {{-- マス：利用済みは利用日、未利用は「未利用」。利用日の昇順で左から並べ、狭い幅では折り返す --}}
                <div class="c-ticket-slots">
                    @foreach($row['slots'] as $usage)
                        @if($usage)
                            <span class="c-ticket-slot num-tabular">{{ $usage->used_on->format('Y/m/d') }}</span>
                        @else
                            <span class="c-ticket-slot c-ticket-slot--empty">未利用</span>
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach

        {{-- 契約に含まれないご利用：あるときだけ、一番下に落ち着いた表示で（黄色のバッジは使わない） --}}
        @if($unassigned['count'] > 0)
            <section class="c-ticket" aria-label="契約に含まれないご利用">
                <div class="c-ticket-head">
                    <span class="c-ticket-title">契約に含まれないご利用 <span class="num-tabular">{{ $unassigned['count'] }}</span>回</span>
                </div>
                <div class="c-ticket-slots">
                    @foreach($unassigned['slots'] as $usage)
                        <span class="c-ticket-slot num-tabular">{{ $usage->used_on->format('Y/m/d') }}</span>
                    @endforeach
                </div>
            </section>
        @endif
    @endif
</div>
@endsection
