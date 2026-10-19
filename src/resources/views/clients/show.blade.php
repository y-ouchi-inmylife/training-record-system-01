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
/* 公開済みの評価文のブロック（2026-10-18 追加。設計書 S-0305 セクション2）。
   （この style は画面にそのまま出るので、診断の名前は書かない。名前は文のファイルの 1 か所から読むため）
   - 札「{診断の名前}（{名前}）」は、会員側の札（client.scss の .c-session-tag）と同じ紫の薄い面に本文の色の文字。
     Bootstrap の badge の形（小さな角丸・太字）のまま、色だけ変える（白の上でも記録のブロックの文字と見分けられる）
   - 点マーカーも紫にして、縦線の上で記録と見分けられるようにする（色だけに頼らず、札の文字でも分かる）
   - 評価文は長くなりやすいので、はじめの 4 行で切る（記録のノートは全文を出すが、評価文は S-0313 で読む） */
.record-block--evaluation::before {
    background: #6f42c1;
}
.record-block__tag {
    background-color: #eee8f8;
    color: #212529;
}
.record-block__clamp {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
    line-clamp: 4;
    overflow: hidden;
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
              - メールアドレスなし              → 「マイページ登録の案内を発行」（新規発行）
              - メールアドレス登録待ち（期限内）→ 「マイページ登録の案内を表示」＋「取消」
              - メールアドレス登録待ち（期限切れ）→ 「マイページ登録の案内を発行」（期限切れは未発行と同じ扱い。取消は出さない）
              - 初回設定待ち（期限内・期限切れ）→ 「マイページ登録を削除」（1 段目の上部ボタン列）
                （行き止まりを作らないために出す。お客様が間違ったメールアドレスを
                 登録した場合、削除して「メールアドレスなし」に戻せば発行し直せる。
                 期限切れの方がむしろ削除したい場面が多い）
              - 利用中                          → 「マイページ登録を削除」（1 段目の上部ボタン列）
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
            // マイページ登録を削除：初回設定待ち（期限内・期限切れの両方）と利用中で出す。
            // 判定は「clients.email が非 NULL」で足りる（この 2 状態で真、他の状態で偽）。
            // サーバー側 API（DELETE /clients/{client}/email）も同じ条件でガードする。
            $showDeleteEmail = in_array($status, [
                \App\Models\Client::STATUS_AWAITING_SETUP,
                \App\Models\Client::STATUS_IN_USE,
            ], true);
        @endphp
        {{-- 1段目: 上部の操作ボタン列（右寄せ）。会員自体と会員のアカウントの操作。
             並びは「会員一覧に戻る → マイページ登録を削除 → 編集 → 削除」。マイページ登録の案内の操作（発行・表示・取消）は
             3 段目のバッジ隣に置く（設計書 S-0305「操作ボタンの配置」）。
             「マイページ登録を削除」（2026-10 変更。旧「登録を削除」を 3 段目から移した）：案内の発行・取消とは違い、会員の
             アカウントに対する操作なので、会員の編集・削除と同じこの並びに置く。会員の「削除」（塗りの赤 btn-danger）と
             区別するため、名前に「マイページ登録」を入れ、枠だけの赤（btn-outline-danger）にする。大きさはこの並びの
             ほかのボタンに合わせて既定（btn-sm を付けない）。出す条件は今までどおり $showDeleteEmail（初回設定待ち・利用中）。
             ボタンが 4 つになっても狭い幅ではみ出さないよう、flex-wrap で折り返す（右寄せのまま） --}}
        <div class="d-flex flex-wrap justify-content-end gap-2 mb-2">
            <a href="{{ route('clients.index') }}" class="btn btn-outline-secondary">&laquo; 会員一覧に戻る</a>
            @if($showDeleteEmail)
                {{-- モーダルの見出しと完了メッセージはこのボタン名にそろえる（2026-10 変更）。本文は具体的なまま —
                     詳細は設計書 S-0305「ボタン・モーダル・完了メッセージの語彙」） --}}
                <button type="button" class="btn btn-outline-danger text-nowrap"
                        data-bs-toggle="modal" data-bs-target="#emailDeletionModal">
                    マイページ登録を削除
                </button>
            @endif
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
                    @elseif($client->hasNonBlankTrainees())
                        {{-- 空でないトレーニーがいれば止める（2026-10-18 変更：空のトレーニーだけなら一緒に削除できる。
                             判定は ClientController::destroy と同じ Client::hasNonBlankTrainees） --}}
                        alert('この会員にはトレーニーが登録されているため削除できません。');
                        return false;
                    @elseif($client->audioRecords->count() > 0)
                        alert('この会員には音声記録が登録されているため削除できません。');
                        return false;
                    @else
                        {{-- 空のトレーニーだけがいるとき（会員の登録で自動で作ったものなど。一緒に消える）も同じ文（2026-10-18 変更：
                             ユーザーレビューで不要となり、一度添えていた「登録内容のないトレーニーも一緒に削除します。」を外した） --}}
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
                {{-- ラベルは「メールアドレス（マイページ）」（2026-10 変更。マイページのログインに使うメールアドレスで、
                     状態のバッジとマイページ登録の案内のボタンが並ぶため、連絡先のメールアドレスなどと取り違えないように） --}}
                <div class="text-muted small">メールアドレス（マイページ）</div>
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
                         2 つ並ぶとき（登録待ち期限内）は隣り合ったまま右端に寄る。幅が足りないときは、
                         ボタンごと折り返して右端に寄せたままにする（flex-wrap・justify-content-end。
                         ボタンの中の文字は text-nowrap で折り返さない。2026-10 変更）。
                         ボタン名は「マイページ登録の案内を発行／表示」と「取消」（2026-10 変更。何の案内かを
                         ボタンの文字だけで分かるようにし、愛犬アンケートの案内のボタンと見分けるため。取消は隣の
                         「マイページ登録の案内を表示」と確認〔M03〕の文言で対象が分かる）。読み上げで何の取消かが
                         伝わるよう、案内のボタンが出るときはラッパーを role="group" にして
                         「マイページ登録の案内」の名前を付ける（案内のボタンが出ない状態〔初回設定待ち・利用中〕では付けない） --}}
                    <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto"
                         @if($showIssue || $showPrint || $showCancel) role="group" aria-label="マイページ登録の案内" @endif>
                        @if($showIssue)
                            {{-- 押下でその場で発行し、会員詳細に戻って完了メッセージを出す（モーダルは開かない。案内の画面は開かない。
                                 2026-10-17 変更：その前は発行のあとに案内の画面〔S-0307〕を開いていた）。
                                 2026-10-17 変更：当初は target="_blank" で別のタブに送っていたが、Android のタブレットの Chrome で
                                 新しいタブが送信の中身を持たずに GET で開き、405 になったため、同じタブに戻した --}}
                            <form method="POST" action="{{ route('client-email-registration-tokens.store', $client) }}"
                                  class="d-inline m-0">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary text-nowrap">マイページ登録の案内を発行</button>
                            </form>
                        @endif
                        @if($showPrint)
                            {{-- 表示はオプションの操作なので白抜き（案内を見せる・送るときだけ開く。
                                 2026-10 変更。設計書 4-4・S-0305「ボタンの色」）。同じタブで開く（2026-10-17 変更） --}}
                            <a href="{{ route('client-email-registration-tokens.print', $client) }}"
                               class="btn btn-sm btn-outline-primary text-nowrap">マイページ登録の案内を表示</a>
                        @endif
                        @if($showCancel)
                            <button type="button" class="btn btn-sm btn-outline-danger text-nowrap"
                                    data-bs-toggle="modal" data-bs-target="#tokenCancellationModal">
                                取消
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
                {{-- 見出し「これまでの記録（N件）」と［トレーニング記録を登録］（2026-10-18 変更：ユーザーレビューで、記録の欄に公開済みの
                     評価文も並ぶようになったため、見出しをマイページの「これまでの記録」と同じ言い方にし、ボタンは何を登録するかを書いた。
                     その前は「トレーニング記録（N件）」と［新規登録］）。件数は今までどおり記録だけ（評価文は数えない）。
                     狭い幅で 1 行に収まらないときは、ボタンを次の行へ折り返して右に寄せる（flex-wrap と ms-auto。S-0313 の評価文の見出しの行と同じ） --}}
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h6 class="mb-0" data-client-records-heading>これまでの記録（{{ $client->trainingRecords->count() }}件）</h6>
                    <a href="{{ route('training-records.create', ['client_id' => $client->id]) }}"
                       class="btn btn-primary ms-auto">トレーニング記録を登録</a>
                </div>
                {{-- 記録と、公開済みの愛犬性格診断の評価文を混ぜた一覧（2026-10-18 変更。コントローラで新しい順に並べた $timeline）。
                     見出しの件数は記録だけ（評価文は数えない）。評価文だけがあるときも一覧を出す --}}
                @if($timeline->isNotEmpty())
                    <div class="card-body training-records-scroll">
                        <div class="training-records-timeline">
                            @foreach($timeline as $entry)
                                @if($entry['type'] === \App\Support\RecordTimeline::EVALUATION)
                                    @php
                                        $result = $entry['item'];
                                    @endphp
                                    {{-- 評価文のブロック（設計書 S-0305 セクション2「公開済みの愛犬性格診断の評価文を混ぜる」）。
                                         見出し行：公開した日・札「愛犬性格診断（{トレーニーの名前}）」・担当（公開したトレーナー。削除されていれば出さない）。
                                         その下に小見出し「評価文」と、評価文のはじめの 4 行（改行を保持）。所感・メディアはない。
                                         ブロック全体を押すと愛犬性格診断詳細（S-0313） --}}
                                    <a href="{{ route('trainee-personality-diagnosis-results.show', [$result->trainee, $result]) }}" class="record-block-link" data-timeline-evaluation="{{ $result->id }}">
                                        <div class="record-block record-block--evaluation">
                                            <div class="record-block__line1">
                                                <span class="fw-bold">{{ $result->evaluation_published_at->format('Y/m/d') }}</span>
                                                <span class="badge record-block__tag">{{ __('messages.personality_diagnosis_name') }}（{{ $result->trainee->display_name }}）</span>
                                                @if($result->evaluationPublishedBy)
                                                    <span><span class="text-muted">担当：</span>{{ $result->evaluationPublishedBy->name }}</span>
                                                @endif
                                            </div>
                                            <div class="small fw-bold mt-2 text-body">評価文</div>
                                            <div class="record-block__line3 record-block__clamp">{{ $result->evaluation_text }}</div>
                                        </div>
                                    </a>
                                    @continue
                                @endif
                                @php
                                    $record = $entry['item'];
                                    $isFutureDate = $record->training_date > now()->startOfDay();
                                    // 担当1・担当2 を中黒で連結。両方空なら行ごと非表示
                                    $trainerNames = implode('・', array_filter([
                                        $record->trainer1?->name,
                                        $record->trainer2?->name,
                                    ]));
                                @endphp
                                <a href="{{ route('training-records.show', $record) }}" class="record-block-link" data-timeline-record="{{ $record->id }}">
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
             1 トレーニー = 1 カード。**カード全体クリックはせず、名前（と写真の枠）だけをリンク**にする
             （2026-09 変更。カード内にボタン〔愛犬アンケートの操作など〕を置くと、913c31e で発生した
             stretched-link とボタンの共存問題が起きるため。設計書 S-0305 セクション3 設計方針
             「カード全体はクリック領域にしない」参照）。編集・計測値の登録は、名前リンクで S-0309 へ
             遷移してから行う（2026-10 変更：会員詳細の「計測を追加」をなくした）。
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
                        {{-- 必ず使う操作なので塗り（btn-primary。2026-10 変更。設計書 S-0305「ボタンの色」） --}}
                        <button type="button" class="btn btn-sm btn-primary"
                                onclick="window.contractModal.openForCreate()">契約を追加</button>
                        <button type="button" class="btn btn-sm btn-primary"
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
                                {{-- 左：写真の枠、右：名前・項目 の横並び
                                     （設計書 S-0305 セクション3「写真」）。align-items-start で写真の上端を
                                     名前の行の上端にそろえる。右の列は flex-grow-1 で残りの幅いっぱいに広げ、
                                     min-width: 0 で長い値でも横にはみ出さない。右の列の中身は写真の有無にかかわらず同じ作り。 --}}
                                <div class="d-flex align-items-start gap-3">
                                    {{-- トレーニーの写真（左、96px 四方。上端は名前の行の上端にそろう）。
                                         写真がないときも同じ枠を出し、中に「写真なし」を出す（トレーニー詳細 S-0309 の
                                         写真なしの表示と同じ文言・text-muted・0.875rem）。枠は写真の有無で共通にし、
                                         中身だけを切り替える。これで写真の有無にかかわらず名前・項目の位置がそろう。
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
                                {{-- 案内（愛犬アンケート・愛犬性格診断）の操作（2026-10-19 変更。設計書 S-0305 セクション3「案内の操作」、要件定義書 6-16-8・6-16-11）。
                                     ユーザーレビューで、愛犬アンケートと愛犬性格診断の［…を発行］［…を表示］［取消］がばらばらに並び分かりにくかったため、
                                     名前の行の右の［案内を発行］1 つ（モーダル S-0305-M08 で種類を選ぶ）と、欄のいちばん下の「案内中」の行
                                     （［表示］［取消］）にまとめた。1 頭に出しておける案内は 2 つの種類を合わせて 1 つまで。
                                     - $activeSurveys：回答待ちの案内（未回答で期限内）。前の決まりで出した案内が残っていると 2 つあることがあり、
                                       そのときは「案内中」の行を 2 つ出す（愛犬アンケート → 愛犬性格診断の順）。1 つでもあれば［案内を発行］は出さない
                                     - 期限の日は出さない（2026-10-19 変更：ユーザーレビューで要らないことになった。その前は「（10/26まで）」を出していた）
                                     - activeIntakeSurveyToken・activePersonalityDiagnosisToken は ClientController::show で先読み済み
                                     - 愛犬アンケート＆愛犬性格診断の案内（2026-10-19 追加）：2 つとも回答待ちなら 1 つの「案内中」にまとめ、［表示］は
                                       組み合わせのご案内（S-0314）、［取消］は組み合わせの取消（M09。まだ答えていない分をまとめて消す）。
                                       愛犬アンケートだけ答えたあとは、残りの愛犬性格診断を単独の案内と同じに出す（Trainee::has_active_combined_survey）
                                     経緯：その前は、名前の行の右に［愛犬アンケートの案内を発行］（回答済みのときは白抜き）または
                                     ［愛犬アンケートの案内を表示］［取消］、愛犬性格診断の行の右に［案内を発行］または［案内を表示］［取消］を置いていた --}}
                                @php
                                    $activeSurveys = [];
                                    if ($trainee->has_active_combined_survey) {
                                        $activeSurveys[] = [
                                            'name' => \App\Support\CombinedSurveyName::name(),
                                            // 「＆」のあとで改行して 2 行で出す（2026-10-19 変更。下の clients._survey-name）
                                            'nameLines' => \App\Support\CombinedSurveyName::lines(),
                                            'printUrl' => route('trainee-combined-survey-tokens.print', $trainee),
                                            'cancelModal' => '#combinedSurveyCancelModal',
                                            'cancelAttribute' => 'data-combined-survey-trainee-id',
                                        ];
                                    }
                                    if ($trainee->activeIntakeSurveyToken !== null && ! $trainee->has_active_combined_survey) {
                                        $activeSurveys[] = [
                                            'name' => __('messages.intake_survey_name'),
                                            'printUrl' => route('trainee-intake-survey-tokens.print', $trainee),
                                            'cancelModal' => '#intakeSurveyCancelModal',
                                            'cancelAttribute' => 'data-intake-survey-trainee-id',
                                        ];
                                    }
                                    if ($trainee->activePersonalityDiagnosisToken !== null && ! $trainee->has_active_combined_survey) {
                                        $activeSurveys[] = [
                                            'name' => __('messages.personality_diagnosis_name'),
                                            'printUrl' => route('trainee-personality-diagnosis-tokens.print', $trainee),
                                            'cancelModal' => '#personalityDiagnosisCancelModal',
                                            'cancelAttribute' => 'data-personality-diagnosis-trainee-id',
                                        ];
                                    }
                                    // 「期限切れ」の行に出す種類の名前（2026-10-19 追加）。いちばん新しく発行した案内が未回答で期限切れのときだけ
                                    // （Trainee::expired_survey_kind。回答待ちがあれば null）
                                    $expiredSurveyName = match ($trainee->expired_survey_kind) {
                                        'intake' => __('messages.intake_survey_name'),
                                        'personality' => __('messages.personality_diagnosis_name'),
                                        // 愛犬アンケート＆愛犬性格診断の案内が 2 つとも未回答で期限切れ（2026-10-19 追加）
                                        'combined' => \App\Support\CombinedSurveyName::name(),
                                        default => null,
                                    };
                                    // 組み合わせの期限切れは、案内中と同じく 2 行で出す（2026-10-19 変更）
                                    $expiredSurveyNameLines = $trainee->expired_survey_kind === 'combined' ? \App\Support\CombinedSurveyName::lines() : null;
                                @endphp
                                {{-- 名前の行。右に案内の表示と操作をまとめて右寄せで置く（2026-10-19 変更：設計書 S-0305 セクション3「案内の操作」。
                                     ユーザーレビューで、名前の下に 1 行を足すより名前の行の右にまとめた方が欄が短く見やすいため。その前は、名前の行の
                                     すぐ下・犬種の行の上に「案内中」「期限切れ」の行を出していた）。名前の行のすぐ下に犬種が来る。
                                     - 案内中：「案内中」（太字）＋種類の名前＋［表示］（白抜きの青。ご案内の画面を同じタブで開く）［取消］（白抜きの赤。
                                       今の取消の確認 M06・M07 を開く）。期限の日は出さない。2 つあれば縦に 2 段（名前は 1 段目と同じ高さ）
                                     - 期限切れ：「期限切れ」（太字の赤）＋種類の名前＋［案内を発行］（期限切れの案内の［表示］［取消］は出さない）
                                     - どちらもない：［案内を発行］だけ（いつも使う操作なので塗り。4-4）
                                     上下のそろえ：名前・ラベル・種類の名前は、ボタン（btn-sm）と同じ高さの箱（.trainee-survey-line）の中で縦の中央に置き、
                                     名前の行は、案内中が 2 段のとき（前の決まりの案内）だけ上詰め（align-items-start）にする。これで名前と 1 段目の中央がそろい、
                                     2 段目は名前の下に入る。それ以外は縦の真ん中（align-items-center）：組み合わせの名前は「＆」のあとで改行した 2 行で出し
                                     （2026-10-19 変更。1 行だと 1400px でも名前の右に収まらず、名前の下に回ってほかのトレーニーと形が違ってしまうため）、
                                     名前は 2 行のまとまりの真ん中の高さにそろえる（ラベル・ボタンもまとまりの真ん中。1 段のときは高さが同じなので今までどおり）。
                                     幅が足りないときは flex-wrap で右のまとまりが名前の下の行に回り、ms-auto で右寄せのまま。名前は text-break で
                                     長くてもはみ出さない。ボタンの文字は短いので、読み上げ用に「{表示名}の案内を発行」などの名前を付ける。
                                     名前のリンク：名前が未登録なら「名前未登録」を薄い文字で出し、リンクは張ったまま
                                     （§2-4 の例外。空にするとリンクの文字がなくなり押せなくなるため。2026-10 追加） --}}
                                <div class="d-flex flex-wrap {{ count($activeSurveys) > 1 ? 'align-items-start' : 'align-items-center' }} justify-content-between gap-2 mb-2">
                                    <h6 class="mb-0 text-break trainee-survey-line"><a href="{{ route('trainees.show', $trainee) }}"@if(! $trainee->has_name) class="text-muted"@endif>{{ $trainee->display_name }}</a></h6>
                                    <div class="d-flex flex-column align-items-end gap-1 ms-auto small">
                                        @if($activeSurveys !== [])
                                            <div class="d-flex flex-column align-items-end gap-1" role="group"
                                                 aria-label="{{ $trainee->display_name }}の案内中の案内" data-survey-in-progress>
                                                @foreach($activeSurveys as $survey)
                                                    <div class="d-flex flex-wrap align-items-center justify-content-end gap-2" data-survey-in-progress-item>
                                                        {{-- ラベルと種類の名前は離さない（1 つのまとまり） --}}
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="trainee-survey-line fw-bold text-nowrap">案内中</span>
                                                            @include('clients._survey-name', ['name' => $survey['name'], 'lines' => $survey['nameLines'] ?? null])
                                                        </div>
                                                        {{-- ［表示］［取消］は 1 つのまとまりにして、狭い幅でも 2 つ一緒に次の行へ回す（［取消］だけが落ちないように） --}}
                                                        <div class="d-flex gap-2">
                                                            <a href="{{ $survey['printUrl'] }}" class="btn btn-sm btn-outline-primary text-nowrap"
                                                               aria-label="{{ $survey['name'] }}の案内を表示">表示</a>
                                                            <button type="button" class="btn btn-sm btn-outline-danger text-nowrap"
                                                                    data-bs-toggle="modal" data-bs-target="{{ $survey['cancelModal'] }}"
                                                                    {{ $survey['cancelAttribute'] }}="{{ $trainee->id }}"
                                                                    aria-label="{{ $survey['name'] }}の案内を取消">取消</button>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @else
                                            <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
                                                @if($expiredSurveyName !== null)
                                                    <div class="d-flex align-items-center gap-2" role="group"
                                                         aria-label="{{ $trainee->display_name }}の期限切れの案内" data-survey-expired>
                                                        <span class="trainee-survey-line fw-bold text-danger text-nowrap">期限切れ</span>
                                                        @include('clients._survey-name', ['name' => $expiredSurveyName, 'lines' => $expiredSurveyNameLines])
                                                    </div>
                                                @endif
                                                <button type="button" class="btn btn-sm btn-primary text-nowrap ms-auto"
                                                        data-bs-toggle="modal" data-bs-target="#surveyIssueModal"
                                                        data-survey-issue-trainee-id="{{ $trainee->id }}"
                                                        data-survey-issue-trainee-name="{{ $trainee->display_name }}"
                                                        aria-label="{{ $trainee->display_name }}の案内を発行">案内を発行</button>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                {{-- ラベル＋値の並び。dl.row でラベル幅を col-4 に固定して
                                     値を右側に揃える。
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
                                    {{-- 去勢・避妊（2026-10 追加）。誕生日の下・最終計測の上。未登録は空欄（§2-4）。
                                         2026-10 にトレーニーの備考（この下の行）をなくした --}}
                                    {{-- ラベルは言葉の途中で折り返さない（2026-10-18 追加。狭い幅〔992px でラベルの列 約 86px、375px で約 76px〕で
                                         「去勢・避／妊」「最終計／測」と折れていたため）。「愛犬性格診断」のリンクと同じく、keep-all と <wbr> で
                                         「去勢・／避妊」「最終／計測」の位置でだけ折り返し、overflow-wrap: normal で受け継ぐ break-word を止める。
                                         2〜3 文字のラベル（犬種・性別・誕生日）は狭い幅でも 1 行に収まるので付けない --}}
                                    <dt class="col-4 fw-normal text-muted" style="word-break: keep-all; overflow-wrap: normal;">去勢・<wbr>避妊</dt>
                                    <dd class="col-8 mb-1">{{ $trainee->neuter_status_label }}</dd>
                                    {{-- 暮らしの様子（2026-10-19 変更。設計書 S-0305 セクション3「名前の下の項目の並びと出し方」）。去勢・避妊の下、
                                         愛犬性格診断の上に、ほかの項目と同じ「ラベル・値」の行で、いつも出す（その前は回答があるときだけ、欄のいちばん下に
                                         リンクだけの行を出していた）。
                                         - 値はバッジ：暮らしの様子に文字が入っていれば「入力あり」（bg-success）、なければ「入力なし」（bg-secondary）。
                                           色は評価文の状態のバッジ（公開済み＝bg-success、なし＝bg-secondary）に合わせる
                                         - 「入力あり」は、最新の回答の暮らしの様子の自由記述のどれかに文字が入っているとき（Trainee::has_intake_life_answers。
                                           空白・改行だけは空。2026-10-19 変更：その前は最新の回答の有無で決め、答えの中身は見ていなかった）。
                                           latestIntakeSurveyResponse は ClientController::show で先読み済み（トレーニーごとに問い合わせは増えない）
                                         - ラベルは入力ありのときだけリンク（トレーニー詳細 S-0309 の暮らしの様子の欄 #intake-survey、同じタブ。
                                           読み上げ用の名前は「{表示名}の暮らしの様子」）。入力なしのときはほかのラベルと同じ灰色の文字
                                         - ラベルは「暮らしの」と「様子」の間でだけ折り返す（狭い幅でラベルの列に収まらないため。去勢・避妊と同じやり方） --}}
                                    @if($trainee->has_intake_life_answers)
                                        <dt class="col-4 fw-normal" style="word-break: keep-all; overflow-wrap: normal;"><a href="{{ route('trainees.show', $trainee) }}#intake-survey"
                                           aria-label="{{ $trainee->display_name }}の暮らしの様子">暮らしの<wbr>様子</a></dt>
                                        <dd class="col-8 mb-0" data-intake-survey-status><span class="badge bg-success">入力あり</span></dd>
                                    @else
                                        <dt class="col-4 fw-normal text-muted" style="word-break: keep-all; overflow-wrap: normal;">暮らしの<wbr>様子</dt>
                                        <dd class="col-8 mb-0" data-intake-survey-status><span class="badge bg-secondary">入力なし</span></dd>
                                    @endif
                                </dl>
                                {{-- 性格診断の行（2026-10 追加。要件定義書 6-16-11〜6-16-13、設計書 S-0305 セクション3「性格診断の行」）。
                                     - いつも出す（結果・案内の有無にかかわらず）。暮らしの様子の下、最終計測の上（2026-10-19 変更：ユーザーレビューで
                                       並びを 去勢・避妊 → 暮らしの様子 → 愛犬性格診断 → 最終計測 にした。その前は最終計測の下、「暮らしの様子」のリンクの上）
                                     - 左は「性格診断」の文字のリンク。いつもトレーニー詳細（S-0309）の性格診断の欄（#personality-diagnosis、
                                       同じタブ）へ。結果がなくても飛ぶ（欄に「（未回答）」が出る）。左の位置・文字の大きさ・上の間隔は
                                       「暮らしの様子」のリンクにそろえる（small mt-1）
                                     - 行のボタン（［案内を発行］［案内を表示］［取消］）は置かない（2026-10-19 変更：名前の行の［案内を発行］と、
                                       欄の下の「案内中」の行にまとめた。その前は値の列の右にボタンのまとまりを置いていた）
                                     - 最新の診断の日付（2026-10 追加）：リンクとボタンの間に、最新の結果の回答日（Y/m/d）を出す。
                                       結果がないときは「診断なし」（text-muted）。回答待ちの案内があっても、出すのは結果の日付。
                                       最新は Trainee::latestPersonalityDiagnosisResult（ClientController::show で先読み済み。
                                       トレーニー詳細のグラフの「今回」と同じ回）
                                     - 日付を上の属性の値の列と左をそろえるため、行は属性の並び（dl.row）と同じ row・col-4／col-8 で組む。
                                       ラベルの列にリンク、値の列の左に日付、値の列の右にボタンのまとまり。幅が足りないときに折り返すのは
                                       値の列の中のボタンのまとまりだけで、日付はリンクと同じ行に残る --}}
                                <div class="row small mt-1 align-items-center" data-personality-diagnosis-row>
                                    <div class="col-4">
                                        {{-- リンクの文字は「愛犬」と「性格診断」の間でだけ折り返す（2026-10-18 追加。ラベルの列が狭い幅〔992px で約 86px、
                                             375px で約 76px〕で言葉の途中で折り返していたため）。keep-all で途中では折り返さず、<wbr> の位置でだけ折り返す。
                                             overflow-wrap: normal：上から受け継ぐ break-word を止める（375px では「性格診断」〔約 56px〕が列の中の文字の幅〔約 52px〕に
                                             収まらず、break-word だと「性格診／断」と折れていた。止めると列の右の余白〔12px〕の中に約 4px はみ出すだけで、日付には重ならない）。
                                             labelHtml() は文のファイルの決まった文字を逃がしてから <wbr> でつないだもの（利用者の入力は入らない） --}}
                                        <a href="{{ route('trainees.show', $trainee) }}#personality-diagnosis" style="word-break: keep-all; overflow-wrap: normal;"
                                           aria-label="{{ $trainee->display_name }}の{{ __('messages.personality_diagnosis_name') }}">{{ \App\Support\PersonalityDiagnosisName::labelHtml() }}</a>
                                    </div>
                                    <div class="col-8 d-flex flex-wrap align-items-center gap-2">
                                        @if($trainee->latestPersonalityDiagnosisResult)
                                            <span data-personality-diagnosis-latest>{{ $trainee->latestPersonalityDiagnosisResult->answered_at->format('Y/m/d') }}</span>
                                        @else
                                            <span class="text-muted" data-personality-diagnosis-latest>診断なし</span>
                                        @endif
                                    </div>
                                </div>
                                {{-- 最終計測だけの並び。性格診断の行との間は、性格診断の行の上と同じ間隔（mt-1） --}}
                                <dl class="row mb-0 small mt-1">
                                    {{-- 最終計測（設計書 S-0305 セクション3「最終計測」参照）。欄のいちばん下（2026-10-19 変更：その前は去勢・避妊の下）。
                                         最新 1 件は Trainee::latest_measurement アクセサ経由で取り出し、
                                         「Y/m/d（N.NNkg）」で表示。0 件は「計測なし」（2026-10-19 変更：その前は空欄〔§2-4〕。
                                         文字の色は愛犬性格診断の「診断なし」と同じ text-muted）。
                                         N+1 回避のため ClientController::show で trainees.measurements を eager load 済み。 --}}
                                    {{-- 計測値の登録のボタンは置かない（2026-10 変更。計測値の登録はトレーニー詳細 S-0309 の
                                         計測値の欄から行い、会員詳細のトレーニーの欄はトレーニーの情報を見る場所にするため）。
                                         値だけをほかの属性と同じ dd で出す（行の高さもほかの属性とそろう） --}}
                                    <dt class="col-4 fw-normal text-muted" style="word-break: keep-all; overflow-wrap: normal;">最終<wbr>計測</dt>
                                    @if($trainee->latest_measurement)
                                        <dd class="col-8 mb-0" data-latest-measurement>{{ $trainee->latest_measurement->measured_date->format('Y/m/d') }}（{{ number_format((float) $trainee->latest_measurement->weight_kg, 2) }}kg）</dd>
                                    @else
                                        <dd class="col-8 mb-0 text-muted" data-latest-measurement>計測なし</dd>
                                    @endif
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
                     あったため、位置と文言を変えた。btn-sm で小さくし、色はこの画面の
                     ほかの青いボタンと同じ塗り（btn-primary。2026-10 変更）。 --}}
                <div class="card-footer text-end">
                    <a href="{{ route('trainees.create', $client) }}" class="btn btn-sm btn-primary">トレーニーを追加</a>
                </div>
            </div>

            {{-- 契約・利用の登録・編集モーダル（S-0305-M04 / M05）。回数カードのボタン・見出し・マスから開く。
                 $openModal はエラーで戻ったときに開き直すモーダルの種別（ClientController::show 参照）。 --}}
            @include('clients._contract-modal', ['client' => $client, 'openModal' => $openModal, 'defaultExpiresOn' => $contractDefaultExpiresOn])
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
                {{-- 緊急連絡先（ご家族等）は「電話番号（続柄）」の 1 つの値で出す（Client::emergency_contact_display）。
                     2026-10-17 に「電話番号（予備）」から変え、2026-10-18 に続柄の列をやめてまとめた（続柄が単独の項目に見えたため）。
                     2 行目の 3 列目は 1 行目と同じく空く --}}
                <x-detail-cell label="緊急連絡先（ご家族等）" :value="$client->emergency_contact_display" />
            </div>
        </div>
    </div>

    {{-- 最終更新 --}}
    <div class="text-end text-muted small mb-3">
        最終更新: {{ $client->updated_at->format('Y/m/d H:i') }}@if($client->updatedBy?->name) {{ $client->updatedBy->name }}@endif
    </div>

    {{-- メールアドレス削除確認モーダル（S-0305-M02、段階 4-2）。
         初回設定待ち（期限内・期限切れの両方）と利用中で開く。見出しは「マイページ登録の削除」（2026-10 変更。
         開くボタン「マイページ登録を削除」にそろえた。本文と確定ボタンは変えない）。aria-labelledby は見出しの id
         （emailDeletionModalLabel）を指すので、見出しの文字を変えても参照は切れない。
         ボタン側の $showDeleteEmail と同じ条件でモーダル本体もラップし、
         両者の条件が食い違わないようにする。 --}}
    @if($showDeleteEmail)
    <div class="modal fade" id="emailDeletionModal" tabindex="-1"
         aria-labelledby="emailDeletionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="emailDeletionModalLabel">
                        マイページ登録の削除
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

    {{-- 愛犬アンケート案内取消確認モーダル（S-0305-M06。2026-10 追加）。トレーニーが何頭いても 1 つ --}}
    @if($client->trainees->isNotEmpty())
        @include('trainees._intake-survey-cancel-modal')
        {{-- 性格診断の案内の取消確認モーダル（S-0305-M07。2026-10 追加） --}}
        @include('trainees._personality-diagnosis-cancel-modal')
        {{-- 愛犬アンケート＆愛犬性格診断の案内の取消確認モーダル（S-0305-M09。2026-10-19 追加） --}}
        @include('trainees._combined-survey-cancel-modal')
        {{-- 案内の発行モーダル（S-0305-M08。2026-10-19 追加）。トレーニーが何頭いても 1 つ --}}
        @include('trainees._survey-issue-modal')
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
