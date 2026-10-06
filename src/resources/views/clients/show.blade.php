@extends('layouts.app')

@section('title', '会員詳細')

@push('styles')
<style>
/* トレーニング記録の一覧領域: ビューポート基準の最大高さ、
   収まらない場合のみ縦スクロール */
.training-records-scroll {
    max-height: 60vh;
    overflow-y: auto;
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
        <div class="col-lg-7">
            <div class="card mb-3">
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
                                <h6 class="mb-2"><a href="{{ route('trainees.show', $trainee) }}">{{ $trainee->name }}</a></h6>
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
                                    <dd class="col-8 mb-1">
                                        @if($trainee->birth_date)
                                            {{ $trainee->birth_date->format('Y/m/d') }}（{{ $trainee->age }}歳）
                                        @endif
                                    </dd>
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
