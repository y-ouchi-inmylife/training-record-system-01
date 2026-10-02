@extends('layouts.client')

@php
    // 曜日の日本語表記（Carbon の dayOfWeek は 0=日 〜 6=土）
    $weekdaysJp = ['日', '月', '火', '水', '木', '金', '土'];
    $hero = $sessions->first();
    // 体重推移グラフ 1 枚あたりの高さ（暫定値）。値をここで一元管理し、
    // 各 canvas の親要素のインラインスタイルで使い回す。
    $weightChartHeight = '180px';
@endphp

@section('content')
<div class="container py-4 c-dashboard">
    {{-- 挨拶（設計書 §6: 「ようこそ」→「こんにちは」に変更）。
         呼称は「さん」。当初「さん」→「様」（顧客としての位置づけを理由に）→
         2026-09 にトレーナーさんに確認して「さん」に戻した経緯がある
         （詳細は client-portal-design-plan.md §6 書き換え表の「呼称の経緯」参照）。 --}}
    <h1 class="c-greeting" style="font-size: 1.1rem;">{{ auth('client')->user()->full_name }} さん、こんにちは</h1>

    {{-- 犬の名前・写真の予約領域（設計書 §8-4）。
         将来 Client に dog リレーションが入ったら hidden を外して差し込む。 --}}
    <div class="c-dog-placeholder" hidden></div>

    {{-- 体重推移（設計書 S-1402 セクション「体重推移」・6-15-14、段階③）。
         トレーニーごとに折れ線グラフを 1 枚描く。データはコントローラで
         組み立て、`data-measurement-chart` 属性に JSON で載せる（既存の
         `qrcode` が `data-qr-url` を使うのと同じ流儀）。線の色は client.scss
         の :root で定義されている --c-brand-bright を JS 側で読み取る
         （ハードコードしない）。 --}}
    @if(!empty($weightCharts))
        {{-- eyebrow は置かず、「体重」の語はグラフの縦軸ラベル（縦書き）に載せる
             （2026-09 変更。詳細は screen-design.md S-1402「体重推移」節参照）。
             セクションの意味は aria-label で伝える（削除した見出しに向いていた
             aria-labelledby の参照先が失われるため、aria-label に切り替えた）。 --}}
        <section class="c-section" aria-label="体重">
            @foreach($weightCharts as $chart)
                @php
                    // 当該トレーニーのフォームからの送信でエラーが戻ってきたかの判定。
                    // old('_trainee_id') と $chart['id'] を比較する（計測値モーダル S-0309 と同じ流儀）。
                    // 共通の部品 <x-form-error> は共有のエラーバッグを見るため、ここで囲まないと
                    // 他のトレーニーのカードにもエラーが出てしまう（設計書 S-1402「バリデーションチェック」）。
                    $shouldShowPhotoError = $errors->any() && (int) old('_trainee_id') === (int) $chart['id'];
                @endphp
                <article class="c-session" style="margin-bottom: 1rem;">
                    <div class="c-session-body">
                        <div class="c-session-content">
                            <h2 class="mb-2" style="font-size: 1.1rem;">{{ $chart['name'] }}ちゃん</h2>

                            {{-- カード上部：入力エラー以外の失敗（変換・保存失敗）はフォームの上に出す（設計書 §2-7
                                 「入力エラー以外の失敗を入力の項目のキーで返さないルール」）。
                                 入力項目（photo）のエラーに対する上部の 1 文の案内は、本画面が小さなフォーム
                                 （写真 1 枚の登録・エラーになりうる項目は photo のみ）のため §2-7「画面上部の
                                 短い案内」の例外として出さない（段階 5-1）。欄の下の文言（写真枠の下の
                                 <x-form-error field="photo" block />）だけで足りる。
                                 form キーの alert-danger は上記例外とは別のルールのため、残す。
                                 「このトレーニーのフォームで起きたエラー」のときだけ表示する。 --}}
                            @if($shouldShowPhotoError)
                                @error('form')
                                    <div class="alert alert-danger" role="alert">{{ $message }}</div>
                                @enderror
                            @endif

                            {{-- 写真とグラフの横並びラッパー（2026-09 追加、要件定義書 6-15-15）。
                                 モバイル（<576px）は縦積み（flex-column）、sm 以上は横並び（flex-sm-row）。
                                 gap は写真とグラフの間の余白。align-items-start で上端揃え。
                                 SCSS は触らず、Bootstrap の flex ユーティリティとインラインで組む
                                 （設計書 S-1402「トレーニー写真」設計方針参照）。 --}}
                            <div class="d-flex flex-column flex-sm-row gap-3 align-items-start">
                                {{-- 左：トレーニー写真。
                                     モバイル（<576px、親が flex-column）では**中央寄せ**、sm 以上
                                     （親が flex-sm-row）では**上端揃え**にする。親の align-items-start
                                     は cross 軸に対する既定で、flex-column では横方向・flex-sm-row では
                                     縦方向の意味が入れ替わる。photo は sm 未満で中央、sm 以上で先頭
                                     という個別要望のため、この要素だけ align-self でオーバーライドする
                                     （align-self は align-items より個別指定として優先される。Bootstrap
                                     の align-self-* は !important 付きで確実に上書きされる）。 --}}
                                <div class="align-self-center align-self-sm-start" style="flex-shrink: 0;">
                                    {{-- アップロード用フォーム：隠しファイル入力のみを持つ。
                                         写真ありの場合はモーダル内「変更」ボタンから、写真なしの場合は
                                         <label for="..."> から <input> をクリックさせる。form は
                                         見た目の <label>/<button> の外側に置き、CSRF・enctype を保つ。 --}}
                                    {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                                         accept 属性は残す（ブラウザのファイル選択ダイアログで形式を絞るための属性）。 --}}
                                    <form action="{{ route('client-portal.trainee-photo.store', $chart['id']) }}"
                                          method="POST"
                                          enctype="multipart/form-data"
                                          id="trainee-photo-form-{{ $chart['id'] }}"
                                          style="margin: 0; display: contents;"
                                          novalidate>
                                        @csrf
                                        {{-- どのトレーニーのフォームからの送信かを判別する hidden。
                                             バリデーションエラー・変換失敗で戻ったとき、old('_trainee_id') と
                                             各カードの $chart['id'] を比較して該当トレーニーのカードだけに
                                             エラーを出す。TraineePhotoController の validate() のルールに
                                             入れないため validated() に混入しない（計測値モーダル S-0309 と同じ流儀）。 --}}
                                        <input type="hidden" name="_trainee_id" value="{{ $chart['id'] }}">
                                        {{-- 非表示のファイル入力。選択即送信（JavaScript で form.submit()）。
                                             accept は MIME と拡張子の両方を列挙（HEIC は iOS 側で MIME が空に
                                             なるケースがあるため拡張子も入れる。既存メディア機能と同じ考え方）。 --}}
                                        <input type="file"
                                               name="photo"
                                               id="trainee-photo-input-{{ $chart['id'] }}"
                                               accept="image/jpeg,image/png,image/heic,image/heif,.jpg,.jpeg,.png,.heic,.heif"
                                               style="display: none;"
                                               onchange="document.getElementById('trainee-photo-form-{{ $chart['id'] }}').submit();">
                                    </form>

                                    {{-- 写真枠（180px 四方）。クリック時の挙動は写真の有無で分かれる
                                         （設計書 S-1402「トレーニー写真」の 2026-09 変更参照）：
                                         - 写真あり : <button> でモーダルを開く（データ属性で Bootstrap にトリガー）
                                         - 写真なし : <label> でファイル選択を直接開く（1 クリック少ない）
                                         見た目（枠のサイズ・色・角丸・境界）は両方で完全に同じ。 --}}
                                    @if($chart['photoUrl'])
                                        <button type="button"
                                                class="d-flex align-items-center justify-content-center p-0"
                                                data-bs-toggle="modal"
                                                data-bs-target="#trainee-photo-modal-{{ $chart['id'] }}"
                                                style="width: 180px; height: 180px; background-color: #ffffff; border: 1px solid rgba(15, 26, 46, 0.08); border-radius: 0.5rem; cursor: pointer; overflow: hidden; margin: 0;"
                                                aria-label="{{ $chart['name'] }}ちゃんの写真を操作する">
                                            {{-- 縦横比を保ったまま枠内に収める（切り抜きはしない）。
                                                 max-width/height 100% で枠を超えず、object-fit: contain で
                                                 余白は枠の背景色（白）で埋まる（設計書「トレーニー写真」の
                                                 切り抜きしない方針）。 --}}
                                            <img src="{{ $chart['photoUrl'] }}"
                                                 alt="{{ $chart['name'] }}ちゃんの写真"
                                                 style="max-width: 100%; max-height: 100%; object-fit: contain; display: block;">
                                        </button>
                                    @else
                                        <label for="trainee-photo-input-{{ $chart['id'] }}"
                                               class="d-flex align-items-center justify-content-center"
                                               style="width: 180px; height: 180px; background-color: #ffffff; border: 1px solid rgba(15, 26, 46, 0.08); border-radius: 0.5rem; cursor: pointer; overflow: hidden; margin: 0;"
                                               title="写真を登録します"
                                               aria-label="{{ $chart['name'] }}ちゃんの写真を登録する">
                                            <span class="text-muted" style="font-size: 0.875rem;">写真を登録</span>
                                        </label>
                                    @endif

                                    {{-- 写真枠（ファイル選択のまとまり）の下の文言（設計書 §2-7）。
                                         ファイル入力自体は display: none なので、Bootstrap の
                                         .is-invalid ~ .invalid-feedback の兄弟セレクタでは出せない。
                                         block で独立して描画する。幅は写真枠と同じ 180px に収める
                                         （折り返しは発生するが、カード幅ではなく枠の下に紐づく位置に
                                         出すことで、どの入力に対するエラーかを明確にする）。 --}}
                                    @if($shouldShowPhotoError)
                                        <x-form-error field="photo" block />
                                    @endif
                                </div>

                                {{-- 右：体重推移グラフ（既存の作りを維持し、横並び対応のため
                                     flex-grow-1 と min-width: 0 を付ける。min-width: 0 は
                                     flex 子要素の canvas が親幅を超えて突き抜けるのを防ぐ定石）。 --}}
                                <div class="flex-grow-1 w-100" style="min-width: 0;">
                                    @if(empty($chart['datasets']))
                                        {{-- 計測値 0 件のトレーニー（2026-09 追加）。空のグラフ（軸だけ）を
                                             描くと意味のない目盛りが出て不具合に見えるため、canvas を出さず
                                             案内文を表示する。高さは通常のグラフ（180px）と揃え、複数
                                             トレーニーが並んだときのカード高さの一貫性を保つ。空判定のキーは
                                             かつて `labels` だったが、時間軸への変更で `labels` を廃止したため
                                             `datasets` の空判定に切り替えた（2026-09 変更。詳細は
                                             screen-design.md S-1402「体重推移」設計方針参照）。 --}}
                                        <div class="d-flex align-items-center justify-content-center text-muted"
                                             style="height: {{ $weightChartHeight }};">
                                            まだ計測値がありません。
                                        </div>
                                    @else
                                        {{-- data-measurement-chart は datasets のみを渡す（2026-09 変更。
                                             時間軸化で labels / tooltips の別配列は廃止し、Chart.js は
                                             各点の {x, y} オブジェクトから軸・ツールチップを組み立てる）。 --}}
                                        <div style="position: relative; height: {{ $weightChartHeight }};">
                                            <canvas data-measurement-chart="{{ json_encode([
                                                'datasets' => $chart['datasets'],
                                            ], JSON_UNESCAPED_UNICODE) }}"></canvas>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                {{-- トレーニー写真の操作モーダル（写真あり時のみ、設計書 S-1402 「操作モーダルの構成と経緯」参照）。
                     トレーニーごとに 1 個ずつ配置する（複数トレーニーは稀・DOM コスト軽微・共有モーダルで
                     必要な JS 状態管理が不要になるため。同判断の詳細は設計書参照）。
                     モーダルは意図的に .c-session の外側（同階層）に置く：.c-session は :hover 時に
                     transform: translateY(-1px) を持ち、これが子孫の position: fixed の containing block を
                     作るため、モーダルを .c-session の内部に置くと overflow: hidden でクリップされる罠がある。 --}}
                @if($chart['photoUrl'])
                    <div class="modal fade"
                         id="trainee-photo-modal-{{ $chart['id'] }}"
                         tabindex="-1"
                         aria-labelledby="trainee-photo-modal-label-{{ $chart['id'] }}"
                         aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="trainee-photo-modal-label-{{ $chart['id'] }}">{{ $chart['name'] }}ちゃんの写真</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                                </div>
                                {{-- modal-body は置かない（設計書 S-1402「操作モーダルの構成と経緯」の
                                     「本文を置かない理由」参照）。タイトル + フッターの 3 ボタンで情報が足り、
                                     本文は重複する説明になるため。空の modal-body を残すと不要な余白が
                                     生まれるので、要素ごと削除する（Bootstrap は modal-header と
                                     modal-footer だけの構成でも成立する）。 --}}
                                <div class="modal-footer">
                                    {{-- キャンセル：モーダルを閉じるだけ --}}
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                                    {{-- 削除：即実行（追加の confirm() は出さない。モーダルで選ぶこと自体が意思確認、
                                         設計書「操作モーダルの構成と経緯」参照）。btn-danger で他の削除操作と揃える。 --}}
                                    <form action="{{ route('client-portal.trainee-photo.destroy', $chart['id']) }}"
                                          method="POST"
                                          style="margin: 0;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger">削除</button>
                                    </form>
                                    {{-- 変更：モーダルを閉じつつファイル選択ダイアログを開く。
                                         data-bs-dismiss で Bootstrap のモーダル閉じアニメーションを開始し、
                                         onclick で隠しファイル入力を .click() でトリガーする（両者はクリック
                                         イベントの中で同時に発火する）。選択後は input の onchange から
                                         form.submit() で自動送信（変更ハンドラは上のフォーム側にある）。 --}}
                                    <button type="button"
                                            class="btn btn-primary"
                                            data-bs-dismiss="modal"
                                            onclick="document.getElementById('trainee-photo-input-{{ $chart['id'] }}').click();">変更</button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </section>
    @endif

    @if($sessions->isEmpty())
        {{-- 空状態（記録0件）— 設計書 §4-2。記録の種別にトレーニング以外
             （事前相談等）があるためラベル・本文とも「記録」（設計書 §6）。
             eyebrow は記録の有無で出し分けず「最新の記録」で固定する
             （2026-09 変更。当初は「ここに届きます」で本文と動詞連動を
             狙っていたが、記録がある場合の eyebrow「最新の記録」と表記が
             入れ替わり直下の説明文と意味がかぶるため撤回した。詳細は
             client-portal-design-plan.md §4-2）。
             本文は初回ログイン会員にこの場所で何が見えるかを直接伝える
             現在形に差し替えた（2026-09 追い直し。旧本文
             「最初の記録が届くと…順番に並びます」は eyebrow「最新の記録」
             と『記録』の語が二重に出るうえ、「順番に並びます」が
             「最新の記録」（1 件を指す集約表示）と噛み合わず時系列で
             積み上がる別の場所の説明に読めたため）。 --}}
        <section class="c-section c-empty-state" aria-label="空状態">
            <p class="eyebrow">最新の記録</p>
            <div class="c-empty-card">
                <p class="mb-0">
                    日付・写真・トレーナーからのノートを、
                    この場所でご覧いただけます。
                </p>
            </div>
        </section>
    @else
        {{-- hero: 最新の記録（先頭1件）。記録の種別にトレーニング以外
             (事前相談等)があるためラベルは「記録」(設計書 §6)。 --}}
        @php $rec = $hero['record']; $media = $hero['media']; @endphp
        <section class="c-section" aria-labelledby="c-latest-heading">
            <p class="eyebrow" id="c-latest-heading">最新の記録</p>

            <article class="c-session c-session--hero">
                <div class="c-date-block" aria-hidden="true">
                    {{-- ≥576px 用: 縦組み3行(月・日・曜日)。モバイルでは display:none
                         曜日は括弧付き(設計書 §5-6)。素の「日」は日付の単位と区別できないため。 --}}
                    <span class="c-date-month">{{ $rec->training_date->month }}月</span>
                    <span class="c-date-day num-tabular">{{ $rec->training_date->day }}</span>
                    <span class="c-date-weekday">（{{ $weekdaysJp[$rec->training_date->dayOfWeek] }}）</span>
                    {{-- <576px 用: 横1行版(設計書 §5-1 モバイル形態)。≥576px では display:none --}}
                    <span class="c-date-inline">{{ $rec->training_date->month }}月 {{ $rec->training_date->day }}日（{{ $weekdaysJp[$rec->training_date->dayOfWeek] }}）</span>
                </div>
                <div class="c-session-body">
                    <div class="c-session-content">
                    {{-- 日付をスクリーンリーダー向けに追加(視覚では日付ブロックが表示) --}}
                    <p class="visually-hidden">{{ $rec->training_date->format('Y年n月j日') }}（{{ $weekdaysJp[$rec->training_date->dayOfWeek] }}）の記録</p>

                    @if(count($media) > 0)
                        @php
                            $shown = array_slice($media, 0, 4);
                            $extra = count($media) - count($shown);
                        @endphp
                        <div class="c-session-media c-session-media--hero" data-media-grid>
                            @foreach($shown as $m)
                                {{-- 属性値にメディアの表示名・ファイル名は入れない（設計書 S-1402
                                     メディアギャラリー節）。alt / aria-label / data-display-title は
                                     いずれも種別（写真／動画）のみ。data-display-title は JS 経由で
                                     Lightbox の img.alt にセットされる（_lightbox.blade.php 参照）。
                                     typeLabel はコントローラから受け取る（Blade で @php(...) を書くと
                                     周囲の @php ... @endphp ブロックとパースが衝突する事故があったため）。 --}}
                                <div class="c-media-thumb"
                                     data-media-id="{{ $m['id'] }}"
                                     data-media-type="{{ $m['type'] }}"
                                     data-conversion-status="{{ $m['conversionStatus'] }}"
                                     data-display-title="{{ $m['typeLabel'] }}"
                                     role="button"
                                     tabindex="0"
                                     aria-label="{{ $m['typeLabel'] }}を開く">
                                    @if($m['thumbnailUrl'])
                                        <img src="{{ $m['thumbnailUrl'] }}" alt="{{ $m['typeLabel'] }}">
                                        @if($m['type'] === 'video')
                                            @include('media-records._video-play-overlay')
                                        @endif
                                    @else
                                        <span class="c-media-placeholder">{{ $m['typeLabel'] }}</span>
                                    @endif
                                    @if($m['conversionStatus'] !== 'not_required' && $m['conversionStatus'] !== 'done')
                                        <span class="c-media-badge">準備中</span>
                                    @endif
                                </div>
                            @endforeach
                            @if($extra > 0)
                                <a href="{{ route('client-portal.training-records.show', $rec) }}" class="c-media-more" aria-label="残り{{ $extra }}枚を見る">+{{ $extra }}</a>
                            @endif
                        </div>
                    @endif

                    @if($rec->record_content)
                        <p class="c-session-note">{{ \Illuminate\Support\Str::limit($rec->record_content, 120, '…') }}</p>
                    @endif

                    <a href="{{ route('client-portal.training-records.show', $rec) }}" class="c-session-cta">
                        {{ empty($rec->record_content) ? '記録を見る' : '続きを読む' }} <span aria-hidden="true">→</span>
                    </a>
                    </div>
                </div>
            </article>
        </section>

        {{-- feed: これまでの記録（残り）。記録の種別にトレーニング以外
             (事前相談等)があるため、hero と揃えてラベルを「記録」に統一
             (設計書 §6)。件数のサブテキストは「記録」の反復と「これまで」の
             二重をどちらも避けるため「全 N 回」とする。 --}}
        @if($sessions->count() > 1)
            <section class="c-section" aria-labelledby="c-past-heading">
                <p class="eyebrow" id="c-past-heading">これまでの記録</p>
                <p class="meta c-section-sub">全 <span class="num-tabular">{{ $sessions->count() }}</span> 回</p>

                @foreach($sessions->skip(1) as $session)
                    @php $rec = $session['record']; $media = $session['media']; @endphp
                    <article class="c-session">
                        <div class="c-date-block" aria-hidden="true">
                            {{-- ≥576px 用: 縦組み3行(月・日・曜日)。モバイルでは display:none
                                 曜日は括弧付き(設計書 §5-6)。素の「日」は日付の単位と区別できないため。 --}}
                            <span class="c-date-month">{{ $rec->training_date->month }}月</span>
                            <span class="c-date-day num-tabular">{{ $rec->training_date->day }}</span>
                            <span class="c-date-weekday">（{{ $weekdaysJp[$rec->training_date->dayOfWeek] }}）</span>
                            {{-- <576px 用: 横1行版(設計書 §5-1 モバイル形態)。≥576px では display:none --}}
                            <span class="c-date-inline">{{ $rec->training_date->month }}月 {{ $rec->training_date->day }}日（{{ $weekdaysJp[$rec->training_date->dayOfWeek] }}）</span>
                        </div>
                        <div class="c-session-body">
                            <div class="c-session-content">
                            <p class="visually-hidden">{{ $rec->training_date->format('Y年n月j日') }}（{{ $weekdaysJp[$rec->training_date->dayOfWeek] }}）の記録</p>

                            @if(count($media) > 0)
                                @php
                                    $shown = array_slice($media, 0, 3);
                                    $extra = count($media) - count($shown);
                                @endphp
                                <div class="c-session-media" data-media-grid>
                                    @foreach($shown as $m)
                                        {{-- 属性値にメディアの表示名・ファイル名は入れない（設計書 S-1402
                                             メディアギャラリー節）。理由・詳細は hero 側のコメント参照。 --}}
                                        <div class="c-media-thumb"
                                             data-media-id="{{ $m['id'] }}"
                                             data-media-type="{{ $m['type'] }}"
                                             data-conversion-status="{{ $m['conversionStatus'] }}"
                                             data-display-title="{{ $m['typeLabel'] }}"
                                             role="button"
                                             tabindex="0"
                                             aria-label="{{ $m['typeLabel'] }}を開く">
                                            @if($m['thumbnailUrl'])
                                                <img src="{{ $m['thumbnailUrl'] }}" alt="{{ $m['typeLabel'] }}">
                                                @if($m['type'] === 'video')
                                                    @include('media-records._video-play-overlay')
                                                @endif
                                            @else
                                                <span class="c-media-placeholder">{{ $m['typeLabel'] }}</span>
                                            @endif
                                            @if($m['conversionStatus'] !== 'not_required' && $m['conversionStatus'] !== 'done')
                                                <span class="c-media-badge">準備中</span>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if($extra > 0)
                                        <a href="{{ route('client-portal.training-records.show', $rec) }}" class="c-media-more" aria-label="残り{{ $extra }}枚を見る">+{{ $extra }}</a>
                                    @endif
                                </div>
                            @endif

                            @if($rec->record_content)
                                <p class="c-session-note">{{ \Illuminate\Support\Str::limit($rec->record_content, 70, '…') }}</p>
                            @endif

                            <a href="{{ route('client-portal.training-records.show', $rec) }}" class="c-session-cta">
                                {{ empty($rec->record_content) ? '記録を見る' : '続きを読む' }} <span aria-hidden="true">→</span>
                            </a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </section>
        @endif
    @endif
</div>

{{-- インライン通知(「準備中」等) 用の Bootstrap Toast。
     設計書 §6: alert() を廃止し、内部状態語彙(processing 等)を出さない
     お客様向け文言に置き換える。トーストは cobalt 面 + mat 文字(§2-1)。 --}}
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;">
    <div id="c-toast" class="toast align-items-center c-toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
        <div class="d-flex">
            <div class="toast-body" id="c-toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="閉じる"></button>
        </div>
    </div>
</div>

{{-- 原寸ライトボックス(写真拡大・動画再生) — S-1404 と共用の汎用 partial --}}
@include('media-records._lightbox')

{{-- 体重推移グラフ用スクリプト（Chart.js を npm でビルドに含める。段階③）。 --}}
@if(!empty($weightCharts))
    @vite('resources/js/measurement-chart.js')
@endif
@endsection

@push('scripts')
<script>
// メディアサムネイルをクリック → /client-portal/media/{id}/play で
// presigned URL を取得 → ライトボックス表示。
// 設計書 §6 に従い alert() を廃止し、「準備中」等の状態は Bootstrap Toast で
// お客様向けの言葉に置き換えて表示する(内部状態語 processing 等は出さない)。
document.addEventListener('DOMContentLoaded', function () {
    const grids = document.querySelectorAll('[data-media-grid]');
    if (grids.length === 0) return;

    const toastEl = document.getElementById('c-toast');
    const toastBody = document.getElementById('c-toast-body');

    function showToast(msg) {
        if (!toastEl || !toastBody || typeof bootstrap === 'undefined') return;
        toastBody.textContent = msg;
        bootstrap.Toast.getOrCreateInstance(toastEl).show();
    }

    async function openMedia(card) {
        const id = card.dataset.mediaId;
        const type = card.dataset.mediaType;
        const status = card.dataset.conversionStatus;
        const title = card.dataset.displayTitle || '';

        // 変換未完(pending/processing/error)はお客様向け文言で通知
        if (status !== 'not_required' && status !== 'done') {
            showToast('この写真/動画は準備中です。少ししてから開いてみてください。');
            return;
        }

        try {
            const res = await fetch('/client-portal/media/' + encodeURIComponent(id) + '/play', {
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error('写真/動画を開けませんでした。時間をおいて試してみてください。');
            const body = await res.json();
            const url = body.data && body.data.url;
            if (!url) throw new Error('写真/動画を開けませんでした。時間をおいて試してみてください。');
            if (typeof window.openLightbox !== 'function') {
                throw new Error('この画面ではまだ写真/動画を開けません。ページを再読み込みしてみてください。');
            }
            window.openLightbox(type === 'photo' ? 'IMG' : 'VIDEO', url, title);
        } catch (err) {
            showToast(err.message || '写真/動画を開けませんでした。');
        }
    }

    grids.forEach(function (grid) {
        grid.addEventListener('click', function (e) {
            // "+N" のリンクは通常のページ遷移として扱う
            if (e.target.closest('.c-media-more')) return;

            const card = e.target.closest('.c-media-thumb');
            if (!card) return;
            openMedia(card);
        });

        // キーボード操作: Enter / Space で開く
        grid.addEventListener('keydown', function (e) {
            const card = e.target.closest('.c-media-thumb');
            if (!card) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openMedia(card);
            }
        });
    });
});
</script>
@endpush
