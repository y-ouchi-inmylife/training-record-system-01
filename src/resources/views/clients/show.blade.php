@extends('layouts.app')

@section('title', '会員詳細')

@push('styles')
<style>
/* トレーニング記録の一覧領域: ビューポート基準の最大高さ、
   収まらない場合のみ縦スクロール（スマホ幅＝lg 未満で左右が縦に積まれるとき。
   PC 幅は下の @media で、右の列の高さに合わせる） */
.training-records-scroll {
    max-height: 60vh;
    overflow-y: auto;
}

/* PC 幅（lg 以上。左右が横に並ぶとき）は、左右の段組の下端をそろえる
   （設計書 S-0305 レイアウト方針「左右の下端をそろえる」）。
   - 行の高さは右の列（回数カード＋トレーニーカード）で決める。左のトレーニング記録カードは
     position: absolute にして行の高さの計算から外し（記録が多くても行を押し広げない）、
     列の高さいっぱいに広げる。bottom は右の列の最後のカードの mb-3 とそろえて 1rem 空ける。
   - 左の列の中の .client-records-fill が列の高さいっぱいに伸び、最低の高さ（カード 40vh＋下の 1rem）を持つ。
     右の列が短いときは、この最低の高さで行が決まる（左の方が高くなる）。
   - カード（.card は flex の縦並び）の見出しは上に固定し、本文が残りの高さでスクロールする。
   会員詳細の専用クラス（client-records-*）にだけ効かせる。 */
@media (min-width: 992px) {
    .client-records-col {
        display: flex;
        flex-direction: column;
    }
    .client-records-fill {
        position: relative;
        flex: 1 1 auto;
        min-height: calc(40vh + 1rem);
    }
    .client-records-card {
        position: absolute;
        top: 0;
        right: 0;
        bottom: 1rem;
        left: 0;
        margin-bottom: 0 !important;
    }
    .client-records-card .training-records-scroll {
        max-height: none;
        flex: 1 1 auto;
        min-height: 0;
    }
}

/* トレーニング記録タイムライン */
.training-records-timeline {
    position: relative;
    padding-left: 24px;
}
.training-records-timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}
.record-block-link {
    display: block;
    text-decoration: none;
    color: inherit;
    margin-bottom: 1rem;
}
.record-block-link:hover .record-block {
    background: #f8f9fa;
}
.record-block-link:focus-visible {
    outline: 2px solid #0d6efd;
    outline-offset: 2px;
    border-radius: 4px;
}
.record-block {
    position: relative;
    padding: 10px 12px;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    background: #fff;
}
/* 点マーカー: 縦線上の位置に配置 */
.record-block::before {
    content: '';
    position: absolute;
    left: -18px;
    top: 16px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--bs-primary);
}
.record-block__line1 {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    color: #212529;
}
.record-block__line3 {
    color: #495057;
    margin-top: 4px;
    font-size: 0.9em;
}
.record-block__line3 {
    white-space: pre-wrap;
    word-break: break-word;
}

/* 回数カード（設計書 S-0305 セクション5）のカード本文: ビューポート基準の最大高さで、
   収まらない場合のみ縦スクロール。見出し（ご利用回数・追加ボタン）はこの外なので常に見える。
   契約が増えても下のトレーニーカードが画面の外に押し出されないよう、
   トレーニング記録（.training-records-scroll の 60vh）より低い 35vh にしている（ノートPCの画面に合わせた値） */
.contract-ticket-scroll {
    max-height: 35vh;
    overflow-y: auto;
}
/* 回数券型のマス。利用済みと未利用（どちらも button）で幅をそろえ、折り返して並べる */
.ticket-slot {
    min-width: 6.5rem;
}
.ticket-slot-empty {
    /* 未利用のマス（btn btn-sm）。点線の枠で、実線の枠の利用済みのマスと見分ける。
       普段は文字と枠を薄くし（文字は本文の色 --bs-body-color の 50%。白の上で #909294 相当、
       コントラスト比 3.12:1。会員側 S-1412 と同じ濃さ）、利用済みの日付を浮き上がらせる。
       押すとその契約で利用を登録できるので、マウスを乗せたとき・キーボードで選んだときは
       文字を本文の色に戻し、枠と背景の色を少し変える（Bootstrap 5.3 の .btn:focus-visible は
       hover の変数を使う。カーソルの指の形・フォーカスの枠は .btn が持つ） */
    --bs-btn-color: rgba(var(--bs-body-color-rgb), 0.5);
    --bs-btn-border-color: rgba(var(--bs-body-color-rgb), 0.12);
    --bs-btn-hover-color: var(--bs-body-color);
    --bs-btn-hover-bg: var(--bs-tertiary-bg);
    --bs-btn-hover-border-color: var(--bs-secondary-color);
    --bs-btn-active-color: var(--bs-body-color);
    --bs-btn-active-bg: var(--bs-secondary-bg);
    --bs-btn-active-border-color: var(--bs-secondary-color);
    border-style: dashed;
}
</style>
@endpush

@section('content')
<div class="container">
    {{-- ヘッダーサマリー --}}
    <div class="mb-4">
        {{-- 状態別ボタンの表示条件（設計書 S-0305「操作ボタンの配置」）。
             基本 1 つだけだが、「メールアドレス登録待ち（期限内）」だけ「表示」＋「取消」の 2 つが並ぶ：
              - メールアドレスなし              → 「登録案内を発行」（新規発行）
              - メールアドレス登録待ち（期限内）→ 「登録案内を表示」＋「登録案内を取消」
              - メールアドレス登録待ち（期限切れ）→ 「登録案内を発行」（期限切れは未発行と同じ扱い。取消は出さない）
              - 初回設定待ち（期限内・期限切れ）→ 「登録を削除」
                （行き止まりを作らないために出す。お客様が間違ったメールアドレスを
                 登録した場合、削除して「メールアドレスなし」に戻せば発行し直せる。
                 期限切れの方がむしろ削除したい場面が多い）
              - 利用中                          → 「登録を削除」
             状態判定は段階 4-2 で Client モデルに実装した仕組みを使う。
             これらは 3 段目のメールアドレス列の状態バッジ隣で描画する
             （下の 3 段目参照）。上部 1 段目にはクライアント自体の操作だけを置く。 --}}
        @php
            $status = $client->status;
            $expired = $client->show_expired_note;
            $showIssue = ($status === \App\Models\Client::STATUS_NO_EMAIL)
                || ($status === \App\Models\Client::STATUS_AWAITING_EMAIL && $expired);
            $showPrint = $status === \App\Models\Client::STATUS_AWAITING_EMAIL && !$expired;
            // 取消は「表示」と同じ条件（登録待ち・期限内）
            $showCancel = $showPrint;
            // 登録を削除（旧「メールアドレスを削除」）：初回設定待ち（期限内・期限切れの両方）と利用中で出す。
            // 判定は「clients.email が非 NULL」で足りる（この 2 状態で真、他の状態で偽）。
            // サーバー側 API（DELETE /clients/{client}/email）も同じ条件でガードする。
            $showDeleteEmail = in_array($status, [
                \App\Models\Client::STATUS_AWAITING_SETUP,
                \App\Models\Client::STATUS_IN_USE,
            ], true);
        @endphp
        {{-- 1段目: 上部の操作ボタン列（右寄せ）。クライアント自体の操作だけ。
             メールアドレス関連は 3 段目のバッジ隣に置く（設計書 S-0305「操作ボタンの配置」）。 --}}
        <div class="d-flex justify-content-end gap-2 mb-2">
            <a href="{{ route('clients.index') }}" class="btn btn-outline-secondary">&laquo; 会員一覧に戻る</a>
            <a href="{{ route('clients.edit', $client) }}" class="btn btn-primary">編集</a>
            @if(auth()->user()->isAdmin())
                <form method="POST" action="{{ route('clients.destroy', $client) }}" class="d-inline"
                      onsubmit="return confirmDelete()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">削除</button>
                </form>
                <script>
                function confirmDelete() {
                    @if($client->trainingRecords->count() > 0)
                        alert('この会員にはトレーニング記録が登録されているため削除できません。');
                        return false;
                    @elseif($client->trainees->count() > 0)
                        alert('この会員にはトレーニーが登録されているため削除できません。');
                        return false;
                    @elseif($client->audioRecords->count() > 0)
                        alert('この会員には音声記録が登録されているため削除できません。');
                        return false;
                    @else
                        return confirm('この会員を削除しますか？');
                    @endif
                }
                </script>
            @endif
        </div>
        {{-- 2段目: 氏名行 + 状態バッジ --}}
        @php
            $badge = $client->statusBadge();
        @endphp
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <h2 class="mb-0">
                {{ $client->full_name }}@if($client->full_name_kana)<span class="text-muted fs-6">（{{ $client->full_name_kana }}）</span>@endif
            </h2>
            <div class="d-flex align-items-baseline gap-2 ms-3">
                <span class="text-muted small">内部ID</span>
                <span class="font-monospace fs-5">{{ $client->internal_id }}</span>
            </div>
        </div>
        {{-- 3段目: 属性 3 列（初回日／主担当／メールアドレス）。
             状態バッジはメールアドレスの値の右に横並びで置く。バッジが示すのは
             メールアドレスとパスワードの登録状況で、氏名とは関係がないため。
             未登録のときは値がなくバッジだけが同じ位置に表示される。設計書 S-0305 参照 --}}
        <div class="row g-3 mt-2 pt-2 border-top">
            <div class="col-md-4">
                <div class="text-muted small">初回日</div>
                <div style="min-height: 1.5rem;">{{ $client->initial_consultation_date?->format('Y/m/d') }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">主担当</div>
                <div style="min-height: 1.5rem;">{{ $client->primaryTrainer?->name }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">メールアドレス</div>
                {{-- 値 → 状態バッジ → 状態別の操作ボタン、を横並び。
                     値・バッジは左寄せ、操作ボタンは右寄せにする（試行中）。
                     状態でバッジ幅・ボタン数がまちまちなため、左詰めだと状態ごとに
                     ボタンの位置がずれる。ボタン群を 1 つのラッパーでくくり ms-auto を
                     付けることで、状態が変わってもボタンの右端が同じ位置に揃う。
                     設計書 S-0305「操作ボタンの配置」参照（試行の経緯・値が空のときに
                     離れて見える懸念・行を分ける案を採らなかった理由を記載）。
                     ボタンの分岐は上部で組み立てた $showIssue / $showPrint / $showCancel /
                     $showDeleteEmail をそのまま使う（表示条件は変えていない）。
                     狭い幅ではラッパーごと 2 行目に折り返し、2 行目でも右端に寄る。 --}}
                <div class="d-flex align-items-center flex-wrap gap-2" style="min-height: 1.5rem;">
                    @if($client->email)
                        <span>{{ $client->email }}</span>
                    @endif
                    <span class="badge {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                    {{-- 状態別ボタン群のラッパー。ms-auto で右端に寄せる。
                         ラッパー内は gap-2 のみ・flex-nowrap（デフォルト）で、
                         2 つ並ぶとき（登録待ち期限内）は隣り合ったまま右端に寄る --}}
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        @if($showIssue)
                            {{-- 押下でその場で発行し、詳細画面へ戻る（モーダルは開かない） --}}
                            <form method="POST" action="{{ route('client-email-registration-tokens.store', $client) }}"
                                  class="d-inline m-0">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary">登録案内を発行</button>
                            </form>
                        @endif
                        @if($showPrint)
                            <a href="{{ route('client-email-registration-tokens.print', $client) }}"
                               class="btn btn-sm btn-primary" target="_blank" rel="noopener">登録案内を表示</a>
                        @endif
                        @if($showCancel)
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" data-bs-target="#tokenCancellationModal">
                                登録案内を取消
                            </button>
                        @endif
                        @if($showDeleteEmail)
                            {{-- ボタン名は「登録を削除」（旧「メールアドレスを削除」）。
                                 理由は screen-design.md S-0305「操作ボタンの配置」参照。
                                 モーダルのタイトル・本文・完了メッセージは変えていない（役割が
                                 違うため揃えない — 詳細は同設計書参照）。 --}}
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" data-bs-target="#emailDeletionModal">
                                登録を削除
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- 左カラム: トレーニング記録（タイムライン）。col-lg-7（2026-09 変更で 8→7 に狭めた。
             詳細は screen-design.md S-0305 設計方針「2カラム本体の幅を 8:4 から 7:5 に」参照）。 --}}
        {{-- PC 幅では、右の列の高さに合わせてカードを広げ、本文をスクロールさせる
             （client-records-* のスタイル。スマホ幅では効かず、従来どおり本文 max-height: 60vh）。 --}}
        <div class="col-lg-7 client-records-col">
            <div class="client-records-fill">
            <div class="card mb-3 client-records-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">トレーニング記録（{{ $client->trainingRecords->count() }}件）</h6>
                    <a href="{{ route('training-records.create', ['client_id' => $client->id]) }}"
                       class="btn btn-primary">新規登録</a>
                </div>
                @if($client->trainingRecords->count() > 0)
                    <div class="card-body training-records-scroll">
                        <div class="training-records-timeline">
                            @foreach($client->trainingRecords as $record)
                                @php
                                    $isFutureDate = $record->training_date > now()->startOfDay();
                                    // 担当1・担当2 を中黒で連結。両方空なら行ごと非表示
                                    $trainerNames = implode('・', array_filter([
                                        $record->trainer1?->name,
                                        $record->trainer2?->name,
                                    ]));
                                @endphp
                                <a href="{{ route('training-records.show', $record) }}" class="record-block-link">
                                    <div class="record-block">
                                        {{-- 見出し行: 日付・時刻・担当・メディア件数を横並び
                                             （record-block__line1 の flex-wrap で狭い幅では自然に折り返す）。
                                             日付と時刻を太字（fw-bold）にして各ブロックの先頭で「いつの記録か」を
                                             ひと目で分かるようにする。未来日付の text-primary は日付だけに付け
                                             （時刻には色を付けない）、太字と併用する。 --}}
                                        <div class="record-block__line1">
                                            <span class="fw-bold @if($isFutureDate) text-primary @endif">{{ $record->training_date->format('Y/m/d') }}</span>
                                            @if($record->training_time)
                                                <span class="fw-bold">{{ substr($record->training_time, 0, 5) }}</span>
                                            @endif
                                            @if($trainerNames !== '')
                                                <span><span class="text-muted">担当：</span>{{ $trainerNames }}</span>
                                            @endif
                                            @if($record->media_records_count > 0)
                                                <span><span class="text-muted">メディア：</span>{{ $record->media_records_count }}件</span>
                                            @endif
                                        </div>
                                        {{-- トレーナーからのノート: 小見出し + 本文（改行を保持）。
                                             空欄なら小見出しごと非表示（設計書 S-0305 セクション2）。
                                             小見出しは本文より濃い太字（small fw-bold に本文の標準色）にする
                                             — text-muted small では本文の record-block__line3（#495057・0.9em）
                                             より薄く見えて見出しとして読めなかったため（2026-09 実機確認）。
                                             本文は既存の record-block__line3（white-space:pre-wrap / 0.9em /
                                             #495057）を流用。 --}}
                                        @if($record->record_content)
                                            <div class="small fw-bold mt-2 text-body">トレーナーからのノート</div>
                                            <div class="record-block__line3">{{ $record->record_content }}</div>
                                        @endif
                                        {{-- 所感: 小見出し + 本文（改行を保持）。
                                             会員には非開示のため S-0305（トレーナー専用画面）のみで表示する
                                             （会員向け画面 S-14xx には出さない）。空欄なら小見出しごと非表示。
                                             小見出しは training-records/_form.blade.php・show.blade.php の
                                             所感カード（所感 <span class="text-muted">（会員には非開示）</span>）と
                                             同じ表記・作りにそろえる。親 div が fw-bold・text-body なので、
                                             注記部分は fw-normal と text-muted を明示して太字と本文色を打ち消す。 --}}
                                        @if($record->impression)
                                            <div class="small fw-bold mt-2 text-body">所感 <span class="text-muted fw-normal">（会員には非開示）</span></div>
                                            <div class="record-block__line3">{{ $record->impression }}</div>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="card-body">
                        <p class="text-muted mb-0">トレーニング記録はありません</p>
                    </div>
                @endif
            </div>
            </div>
        </div>

        {{-- 右カラム: トレーニー一覧（設計書 S-0305 セクション3）。
             1 トレーニー = 1 カード。**カード全体クリックは廃止し、名前だけをリンク**にする
             （2026-09 変更。カード内に「計測を追加」ボタンを置くにあたり、913c31e で発生した
             stretched-link とボタンの共存問題を根本的に回避するため。設計書 S-0305
             セクション3 設計方針「『計測を追加』ボタンをカード内に置く／カード全体クリックを
             廃止」参照）。編集は名前リンクで S-0309 へ遷移してから「編集」ボタンで行う。
             未登録項目は値を空欄にし行ごと消さない（§2-4 参照）。 --}}
        {{-- 右カラム: トレーニー一覧。col-lg-5（2026-09 変更で 4→5 に広げた。詳細は
             screen-design.md S-0305 設計方針「2カラム本体の幅を 8:4 から 7:5 に」参照）。 --}}
        <div class="col-lg-5">
            {{-- 回数カード（設計書 S-0305 セクション5）。右カラムのトレーニーカードの上に置く。
                 契約ごとに回数ぶんのマスを並べ、その契約に割り当てた利用（client_contract_id）の
                 利用日で埋めていく「回数券型」。並べ方・契約ごとの残り・未割り当ての回数は
                 ContractTicketService で組み立て済みで、Blade は $ticket を並べるだけ
                 （表示のときに自動で割り当てる処理は持たない。設計書 S-0305 セクション5「マスの並べ方」）。 --}}
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    {{-- カードの見出しは「ご利用回数　あと n回」（設計書 S-0305 セクション5「カードヘッダー」）。
                         言葉は会員側（S-1412）にそろえる（トレーナーが会員に聞かれたとき同じ言葉で答えられるように）。
                         ラベル「ご利用回数」は常に出し、数は残り回数が null でないときだけ出す
                         （契約も利用も 1 件もない会員は数字を空欄にする。§2-4 の例外）。
                         数の表示（「あと n回」、マイナスは赤字の「契約 n回分不足」）は会員一覧と同じ x-remaining-count を使い、
                         見出しでだけ fs-5 を渡して数字の部分だけを少し大きくする（ms-2 はラベルとの間の空き）。
                         狭い幅ではボタンと重ならないよう、card-header の flex-wrap で折り返す。 --}}
                    <h6 class="mb-0">ご利用回数@if(! is_null($ticket['remaining']))<span class="ms-2"><x-remaining-count :value="$ticket['remaining']" class="fs-5" /></span>@endif</h6>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                onclick="window.contractModal.openForCreate()">契約を追加</button>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                onclick="window.usageModal.openForCreate()">利用を追加</button>
                    </div>
                </div>
                @if($ticket['isEmpty'])
                    <div class="card-body">
                        <p class="text-muted mb-0">契約・利用は登録されていません</p>
                    </div>
                @else
                    <div class="card-body contract-ticket-scroll">
                        {{-- （契約なし）の行：契約に割り当てていない利用（未割り当て）。あるときだけ一番上に出す。
                             バッジは §2-5「要対応」の bg-warning text-dark で「未割り当て n」（n は未割り当ての利用の件数。利用 1 件は 1 回分）。
                             契約に割り当て直すには、マスを押して利用の編集モーダルで契約を選び直す。 --}}
                        @if($ticket['unassigned']['count'] > 0)
                            <div class="mb-3">
                                {{-- 「未割り当て n」は「（契約なし）」のすぐ後ろ（契約の状態と同じ並べ方。右端には寄せない） --}}
                                <div class="d-flex flex-wrap align-items-center column-gap-2 mb-1">
                                    <span>（契約なし）</span>
                                    <span class="badge bg-warning text-dark">未割り当て {{ $ticket['unassigned']['count'] }}</span>
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach($ticket['unassigned']['slots'] as $usage)
                                        @include('clients._usage-slot', ['usage' => $usage])
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        {{-- 契約ごとの行（契約日の新しいものが上。マスはその契約に割り当てた利用で、古い利用が左）。
                             data-usage-count は割り当てた利用の件数（削除の確認ダイアログの文言に使う。回数ではない） --}}
                        @foreach($ticket['contracts'] as $row)
                            <div @class(['mb-3' => ! $loop->last])>
                                {{-- 見出しと状態を左から並べる（状態は見出しのすぐ後ろ。右端には寄せない）。
                                     狭い幅で見出しが長いときは、状態が次の行に折り返す（flex-wrap） --}}
                                <div class="d-flex flex-wrap align-items-center column-gap-2 mb-1">
                                    {{-- 契約の見出し。押すとその契約の編集モーダル（S-0305-M04）を開く。
                                         メモ・有効期限がない契約は、その部分（「有効期限」の語も）を出さない。
                                         「有効期限」の語は契約のモーダル（S-0305-M04）の項目名とそろえる。
                                         有効期限を過ぎていて残りがある契約（$row['expired']。判定は ContractTicketService）は、
                                         有効期限の日付の部分だけを赤字にする（「有効期限」の文字は赤字にしない）。 --}}
                                    <button type="button" class="btn btn-link p-0 text-start text-decoration-none"
                                            data-id="{{ $row['contract']->id }}"
                                            data-contracted-on="{{ $row['contract']->contracted_on->format('Y-m-d') }}"
                                            data-count="{{ $row['contract']->count }}"
                                            data-expires-on="{{ $row['contract']->expires_on?->format('Y-m-d') }}"
                                            data-note="{{ $row['contract']->note }}"
                                            data-usage-count="{{ $row['usageCount'] }}"
                                            onclick="window.contractModal.openForEdit(this.dataset)">{{ $row['contract']->contracted_on->format('Y/m/d') }} 契約（{{ $row['contract']->count }}回）@if($row['contract']->note) {{ $row['contract']->note }}@endif @if($row['contract']->expires_on) 有効期限 @if($row['expired'])<span class="text-danger">{{ $row['contract']->expires_on->format('Y/m/d') }}</span>@else{{ $row['contract']->expires_on->format('Y/m/d') }}@endif @endif</button>
                                    {{-- 契約の状態（あと n回／すべてご利用済み。会員側 S-1412 と同じ言葉）。見出しのボタンの外に、見出しのすぐ後ろに置く
                                         （見出しとの間は column-gap-2）。有効期限が過ぎていても、この表示は変えない
                                         （期限切れは見出しの有効期限の日付の赤字で示す） --}}
                                    @if($row['usedUp'])
                                        <span class="badge bg-secondary flex-shrink-0">すべてご利用済み</span>
                                    @else
                                        <span class="flex-shrink-0">あと {{ $row['remaining'] }}回</span>
                                    @endif
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach($row['slots'] as $usage)
                                        @if($usage)
                                            @include('clients._usage-slot', ['usage' => $usage])
                                        @else
                                            {{-- 未利用のマス。押すと、この契約を選んだ状態で利用の登録モーダル（S-0305-M05）を開く
                                                 （何番目のマスかは関係しない）。使い切りの契約には未利用のマスがない --}}
                                            <button type="button" class="btn btn-sm ticket-slot ticket-slot-empty"
                                                    data-client-contract-id="{{ $row['contract']->id }}"
                                                    aria-label="未利用。{{ $row['contract']->contracted_on->format('Y/m/d') }} の契約で利用を追加"
                                                    onclick="window.usageModal.openForCreate(this.dataset.clientContractId)">未利用</button>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">トレーニー（{{ $client->trainees->count() }}件）</h6>
                </div>
                @if($client->trainees->count() > 0)
                    <div class="list-group list-group-flush">
                        @foreach($client->trainees as $trainee)
                            {{-- カード（list-group-item）は <div> にし、list-group-item-action は付けない。
                                 ホバー時の背景色変化は失われるが、下の名前リンクの下線で
                                 「クリック可能」を伝える（Bootstrap の <a> 既定の下線を維持する
                                 ため text-decoration-none は付けない）。 --}}
                            <div class="list-group-item">
                                {{-- 左：写真（あるときだけ）、右：名前・項目・「計測を追加」ボタン の横並び
                                     （設計書 S-0305 セクション3「写真」）。align-items-start で写真の上端を
                                     名前の行の上端にそろえる。右の列は flex-grow-1 で残りの幅いっぱいに広げ、
                                     min-width: 0 で長い値でも横にはみ出さない。右の列の中身は写真を置く前と同じ作りで、
                                     「計測を追加」ボタンは写真の有無にかかわらず右の列の右端にそろう。 --}}
                                <div class="d-flex align-items-start gap-3">
                                    {{-- トレーニーの写真（左、96px 四方。上端は名前の行の上端にそろう）。
                                         写真がないときも同じ枠を出し、中に「写真なし」を出す（トレーニー詳細 S-0309 の
                                         写真なしの表示と同じ文言・text-muted・0.875rem）。枠は写真の有無で共通にし、
                                         中身だけを切り替える。これで写真の有無にかかわらず名前・項目・「計測を追加」の位置がそろう。
                                         押すとトレーニー詳細（S-0309）を開く（写真の有無にかかわらず。名前のリンクと同じ行き先）。
                                         写真がないときは img の代替テキストがないため、リンクに読み上げ用の名前を付ける。
                                         URL はトレーニー詳細と同じ Trainee::photo_url（media ディスクの署名付き一時 URL を
                                         手元で作る。外部ストレージへの問い合わせはしない）。photo_path は trainees の列なので、
                                         ClientController::show の trainees の読み込みで足り、追加のクエリは出ない。
                                         枠の見た目はトレーニー詳細（trainees/show.blade.php）の写真の枠と同じ指定で、
                                         大きさだけを 180px から 96px に変えている（角丸・白の余白・object-fit: contain で切り抜かない）。 --}}
                                    <a href="{{ route('trainees.show', $trainee) }}" class="flex-shrink-0 text-decoration-none"
                                       @if(! $trainee->photo_path) aria-label="{{ $trainee->display_name }}の写真なし。トレーニー詳細へ" @endif>
                                        <div class="d-flex align-items-center justify-content-center"
                                             style="width: 96px; height: 96px; background-color: #ffffff; border: 1px solid rgba(15, 26, 46, 0.08); border-radius: 0.5rem; overflow: hidden;">
                                            @if($trainee->photo_path)
                                                <img src="{{ $trainee->photo_url }}"
                                                     alt="{{ $trainee->display_name }}の写真"
                                                     style="max-width: 100%; max-height: 100%; object-fit: contain; display: block;">
                                            @else
                                                <span class="text-muted" style="font-size: 0.875rem;">写真なし</span>
                                            @endif
                                        </div>
                                    </a>
                                    <div class="flex-grow-1" style="min-width: 0;">
                                {{-- 名前の行。右端に受付アンケートのまとまり（「受付アンケート」の文字とボタン）を右寄せで置く
                                     （2026-10 変更。要件定義書 6-16-8、設計書 S-0305 セクション3「受付アンケート」）。
                                     - 名前とまとまりは縦の中央でそろえる（align-items-center）。幅が足りないとき（スマホの幅、
                                       名前が長いとき、回答待ちでボタンが 2 つのとき）は、flex-wrap でまとまりごと名前の下の行に
                                       折り返し、ms-auto で右寄せのままにする。名前は text-break で長くてもはみ出さない
                                     - ラベル（col-4）と値（col-8）の表には入れない：ラベル列は写真の枠と並ぶため lg〜xl で狭く
                                       「受付アンケート」が折り返し、回答待ちではボタンが並ぶため。名前の行の右の空いたところに
                                       置けば、カードの下に行を足さずに済む（経緯：当初はカードの下端に区切り線つきの全幅の行で
                                       置いていた）
                                     - 左の文字は「受付アンケート」だけ：状態はボタンで分かるため、状態の文字・発行日・回答日時は
                                       出さない。状態はバッジにしない（「回答待ち」はお客様の対応を待つ状態で、トレーナーが対応する
                                       状態ではないため）
                                     - 状態は Trainee::intake_survey_status（ClientController::show で
                                       trainees.latestIntakeSurveyResponse・trainees.activeIntakeSurveyToken を先読み済み）
                                     - 「案内を表示」は S-0311 受付アンケート案内画面を別タブで開く（マイページ登録案内の
                                       「登録案内を表示」と同じ）。取消のボタンの文字は「取消」だけ（2026-10 変更。まとまりの中で
                                       対象が伝わり、何を取り消すかは確認〔M06〕の文言で示すため。ボタンに別の aria-label は付けない）。「回答を見る」は S-0309 の暮らしの様子の欄（#intake-survey）へ移る
                                     - 読み上げでどのトレーニーの操作かが分かるよう、まとまりを role="group" にして
                                       「{表示名}の受付アンケート」の名前を付ける（ボタンの文字は変えない）。読み上げの順は
                                       名前 → 受付アンケートのまとまり → 属性 → 計測を追加
                                     名前のリンク：名前が未登録なら「名前未登録」を薄い文字で出し、リンクは張ったまま
                                     （§2-4 の例外。空にするとリンクの文字がなくなり押せなくなるため。2026-10 追加） --}}
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                    <h6 class="mb-0 text-break"><a href="{{ route('trainees.show', $trainee) }}"@if(! $trainee->has_name) class="text-muted"@endif>{{ $trainee->display_name }}</a></h6>
                                    <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto small"
                                         role="group" aria-label="{{ $trainee->display_name }}の受付アンケート">
                                        <span>受付アンケート</span>
                                        <div class="d-flex flex-wrap gap-2">
                                            @if($trainee->intake_survey_status === \App\Models\Trainee::INTAKE_NOT_ISSUED)
                                                <form method="POST" action="{{ route('trainee-intake-survey-tokens.store', $trainee) }}" class="d-inline m-0">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">案内を発行</button>
                                                </form>
                                            @elseif($trainee->intake_survey_status === \App\Models\Trainee::INTAKE_AWAITING)
                                                <a href="{{ route('trainee-intake-survey-tokens.print', $trainee) }}"
                                                   class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">案内を表示</a>
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                        data-bs-toggle="modal" data-bs-target="#intakeSurveyCancelModal"
                                                        data-intake-survey-trainee-id="{{ $trainee->id }}">取消</button>
                                            @elseif($trainee->intake_survey_status === \App\Models\Trainee::INTAKE_ANSWERED)
                                                <a href="{{ route('trainees.show', $trainee) }}#intake-survey"
                                                   class="btn btn-sm btn-outline-primary">回答を見る</a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                {{-- ラベル＋値の並び。dl.row でラベル幅を col-4 に固定して
                                     値を右側に揃える。備考は改行を保持（S-0309 と同じ）。
                                     x-detail-cell は使わない（3 列グリッド前提のコンポーネントで
                                     右カラム col-lg-5 の中の縦並び表示には合わないため）。 --}}
                                <dl class="row mb-0 small">
                                    <dt class="col-4 fw-normal text-muted">犬種</dt>
                                    <dd class="col-8 mb-1">{{ $trainee->breed }}</dd>
                                    <dt class="col-4 fw-normal text-muted">性別</dt>
                                    <dd class="col-8 mb-1">{{ $trainee->sex_label }}</dd>
                                    <dt class="col-4 fw-normal text-muted">誕生日</dt>
                                    {{-- 誕生日と年齢（要件定義書 6-16「年齢の表示規則」。組み立ては Trainee の
                                         birthday_with_age_label アクセサ。未登録は空欄）。2026-10 変更：以前は
                                         birth_date と age アクセサで「Y/m/d（N歳）」に組み立てていた --}}
                                    <dd class="col-8 mb-1">{{ $trainee->birthday_with_age_label }}</dd>
                                    {{-- 去勢・避妊（2026-10 追加）。誕生日の下・備考の上。未登録は空欄（§2-4） --}}
                                    <dt class="col-4 fw-normal text-muted">去勢・避妊</dt>
                                    <dd class="col-8 mb-1">{{ $trainee->neuter_status_label }}</dd>
                                    <dt class="col-4 fw-normal text-muted">備考</dt>
                                    <dd class="col-8 mb-1" style="white-space: pre-wrap;">{{ $trainee->note }}</dd>
                                    {{-- 最終計測（設計書 S-0305 セクション3「最終計測」参照）。
                                         最新 1 件は Trainee::latest_measurement アクセサ経由で取り出し、
                                         「Y/m/d（N.NNkg）」で表示。0 件は空欄（§2-4 参照）。
                                         N+1 回避のため ClientController::show で trainees.measurements を eager load 済み。 --}}
                                    <dt class="col-4 fw-normal text-muted">最終計測</dt>
                                    {{-- 値と「計測を追加」ボタンを横並びで表示（設計書 S-0305
                                         セクション3「『計測を追加』ボタンの位置を最終計測の値の横に」参照）。
                                         当初はカード下部の独立した行に置いていたが、位置が中途半端で
                                         カードが縦に間延びしたため値の右横に移動した。flex-wrap を明示：
                                         狭いときはボタンが下に折り返す（今回は折り返しを防ぐ工夫は入れない）。
                                         値が空欄（計測値 0 件）でもボタンは表示する
                                         （まだ計測がないトレーニーにこそ追加したいため）。 --}}
                                    <dd class="col-8 mb-0 d-flex flex-wrap align-items-center justify-content-between gap-2">
                                        <span>
                                            @if($trainee->latest_measurement)
                                                {{ $trainee->latest_measurement->measured_date->format('Y/m/d') }}（{{ number_format((float) $trainee->latest_measurement->weight_kg, 2) }}kg）
                                            @endif
                                        </span>
                                        <button type="button" class="btn btn-sm btn-outline-primary flex-shrink-0"
                                                onclick="window.measurementModals[{{ $trainee->id }}].openForCreate()">計測を追加</button>
                                    </dd>
                                </dl>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="card-body">
                        <p class="text-muted mb-0">トレーニーは登録されていません</p>
                    </div>
                @endif
                {{-- カード下部の「トレーニーを追加」ボタン（設計書 S-0305 セクション3
                     設計方針「『トレーニーを追加』ボタンの位置と文言を変更」参照）。
                     以前はカードヘッダー右上に「新規登録」ボタンを置いていたが、
                     トレーニング記録カードの「新規登録」と同列に並び誤操作の危険が
                     あったため、位置と文言を変えた。btn-sm btn-outline-primary で
                     視覚重量を落とし、日常操作ではないことを示す。 --}}
                <div class="card-footer text-end">
                    <a href="{{ route('trainees.create', $client) }}" class="btn btn-sm btn-outline-primary">トレーニーを追加</a>
                </div>
            </div>

            {{-- 計測値モーダル（トレーニーごとに 1 個、カードの外側に配置）。
                 カード内の「計測を追加」ボタンから開く。トレーナー側の .card は
                 hover 時の transform 変化を持たないため子要素として置いても機能は
                 するが、Bootstrap のモーダルは position: fixed のオーバーレイで
                 「表示上のカードの一部」ではないため、DOM ツリーでも兄弟として
                 分離しておく（役割の分離）。設計書 S-0309「計測値モーダルの共用
                 （S-0305 との）」参照。$returnTo='client' で成功時に S-0305 に戻す。 --}}
            @if($client->trainees->count() > 0)
                @foreach($client->trainees as $trainee)
                    @include('trainees._measurement-modal', ['trainee' => $trainee, 'returnTo' => 'client'])
                @endforeach
            @endif

            {{-- 契約・利用の登録・編集モーダル（S-0305-M04 / M05）。回数カードのボタン・見出し・マスから開く。
                 $openModal はエラーで戻ったときに開き直すモーダルの種別（ClientController::show 参照）。 --}}
            @include('clients._contract-modal', ['client' => $client, 'openModal' => $openModal])
            @include('clients._usage-modal', [
                'client' => $client,
                'openModal' => $openModal,
                'contractOptions' => $ticket['contractOptions'],
                'defaultContractId' => $ticket['defaultContractId'],
            ])
        </div>
    </div>

    {{-- 連絡先 --}}
    @php
        // 都道府県・市区町村・町名番地は区切りなしで連結し、
        // 建物名・部屋番号との間だけ半角スペースを入れる。
        // 空の項目は array_filter で除外するため余分な区切りは残らない。
        $addressMain = implode('', array_filter([
            $client->address1,
            $client->address2,
            $client->address3,
        ]));
        $fullAddress = implode(' ', array_filter([$addressMain, $client->address4]));
    @endphp
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">連絡先</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <x-detail-cell label="郵便番号" :value="$client->postal_code" />
                <x-detail-cell label="住所" :value="$fullAddress" />
                <div class="col-6 col-md-4"></div>
                <x-detail-cell label="電話番号" :value="$client->phone1" />
                <x-detail-cell label="電話番号（予備）" :value="$client->phone2" />
            </div>
        </div>
    </div>

    {{-- 最終更新 --}}
    <div class="text-end text-muted small mb-3">
        最終更新: {{ $client->updated_at->format('Y/m/d H:i') }}@if($client->updatedBy?->name) {{ $client->updatedBy->name }}@endif
    </div>

    {{-- メールアドレス削除確認モーダル（S-0305-M02、段階 4-2）。
         初回設定待ち（期限内・期限切れの両方）と利用中で開く。
         ボタン側の $showDeleteEmail と同じ条件でモーダル本体もラップし、
         両者の条件が食い違わないようにする。 --}}
    @if($showDeleteEmail)
    <div class="modal fade" id="emailDeletionModal" tabindex="-1"
         aria-labelledby="emailDeletionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="emailDeletionModalLabel">
                        メールアドレスの削除
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    {{-- 文言は初回設定待ち・利用中の両方に当てはまるようにする（設計書 S-0305-M02 参照）。
                         状態で分岐させると保守対象が増えるため一本化 --}}
                    <p class="mb-0">
                        登録されたメールアドレスが削除され、マイページを使えなくなります。<br>
                        会員情報とトレーニング記録は残ります。
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <form method="POST" action="{{ route('client-email.destroy', $client) }}" class="d-inline m-0">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">削除する</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- 受付アンケート案内取消確認モーダル（S-0305-M06。2026-10 追加）。トレーニーが何頭いても 1 つ --}}
    @if($client->trainees->isNotEmpty())
        @include('trainees._intake-survey-cancel-modal')
    @endif

    {{-- マイページ登録案内取消確認モーダル（S-0305-M03）--}}
    @if($showCancel)
    <div class="modal fade" id="tokenCancellationModal" tabindex="-1"
         aria-labelledby="tokenCancellationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="tokenCancellationModalLabel">
                        マイページ登録案内の取消
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">
                        発行済みのマイページ登録案内が使えなくなります。<br>
                        すでにお渡しした案内からは登録できなくなります。
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <form method="POST" action="{{ route('client-email-registration-tokens.destroy', $client) }}" class="d-inline m-0">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">取り消す</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>
@endsection
