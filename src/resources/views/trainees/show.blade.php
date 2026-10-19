@extends('layouts.app')

@section('title', 'トレーニー詳細')

@section('content')
<div class="container">
    {{-- ヘッダーサマリー — 会員詳細（S-0305）と同じ 3 段構成に揃える
         （1 段目: 操作ボタン列（右寄せ）／ 2 段目: 名前 + 会員リンク + 内部ID ／
          3 段目: セパレータ下に犬種・性別・誕生日・去勢・避妊を x-detail-cell で並べる）。詳細は設計書 S-0309 参照。 --}}
    <div class="mb-4">
        {{-- 1段目: 操作ボタン列（右寄せ）。従前の「« 会員詳細へ戻る」は廃止し、
             戻り導線は 2 段目の会員名リンクに一本化した（設計書 S-0309「設計方針」参照）。 --}}
        <div class="d-flex justify-content-end gap-2 mb-2">
            <a href="{{ route('trainees.edit', $trainee) }}" class="btn btn-primary">編集</a>
            @if(auth()->user()->isAdmin())
                {{-- 削除確認の文言はコントローラ側で組み立てて渡す（$deleteConfirmMessage）。
                     Blade 内で @json() を使って onsubmit 属性に埋め込む形にすると、
                     @json() が出力する "..." が onsubmit="..." のダブルクォート境界と
                     競合して confirm() が発火せず、確認なしで削除される不具合が
                     発生した（2026-09 修正）。他の onsubmit="return confirm('...')"
                     箇所と揃えて、シングルクォート内で {{ }} で出力する素直な形に
                     している。文言にシングルクォート・改行を含めないこと。 --}}
                <form method="POST" action="{{ route('trainees.destroy', $trainee) }}" class="d-inline"
                      onsubmit="return confirm('{{ $deleteConfirmMessage }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">削除</button>
                </form>
            @endif
        </div>

        {{-- 2段目: トレーニー名 + 会員（S-0305 リンク）+ 内部ID
             書式は S-0305 の氏名見出し行と同じ（h2 の右横に text-muted small ラベル +
             font-monospace fs-5 値）。会員名は S-0305 へのリンクで、これが戻り導線を兼ねる。 --}}
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            {{-- 名前が未登録なら「名前未登録」を薄い文字で出す（§2-4 の例外。2026-10 追加） --}}
            <h2 @class(['mb-0', 'text-muted' => ! $trainee->has_name])>{{ $trainee->display_name }}</h2>
            <div class="d-flex align-items-baseline gap-2 ms-3">
                <span class="text-muted small">会員</span>
                <a href="{{ route('clients.show', $trainee->client) }}">{{ $trainee->client->full_name }}</a>
            </div>
            <div class="d-flex align-items-baseline gap-2 ms-3">
                <span class="text-muted small">内部ID</span>
                <span class="font-monospace fs-5">{{ $trainee->client->internal_id }}</span>
            </div>
        </div>

        {{-- 3段目: セパレータ下の属性。犬種・性別・誕生日・去勢・避妊を md 以上で 4 列・
             モバイルで 2 列（col-6 col-md-3）の 1 段で並べる。
             基本情報カードは介さない（設計書 S-0309「設計方針」参照）。
             誕生日は要件定義書 6-16「年齢の表示規則」の形（「2020/03/15（6歳7ヶ月）」など）で、
             組み立ては Trainee の birthday_with_age_label アクセサ。誕生日が未登録なら空欄（§2-4）。
             2026-10 変更：トレーニーの備考（2 段目の全幅）をなくした（愛犬アンケートの「暮らしの様子」で、
             飼い主さんの言葉で様子を残せるようになったため。要件定義書 D-0700）。
             x-detail-cell の cols 既定値（col-6 col-md-4）は会員詳細
             （S-0305 の連絡先セクション）向けの現状値。呼び出し側で col-6 col-md-3 を渡し、
             会員詳細への影響を出さないようにする。 --}}
        <div class="row g-3 mt-2 pt-2 border-top">
            <x-detail-cell label="犬種" :value="$trainee->breed" cols="col-6 col-md-3" />
            <x-detail-cell label="性別" :value="$trainee->sex_label" cols="col-6 col-md-3" />
            <x-detail-cell label="誕生日" :value="$trainee->birthday_with_age_label" cols="col-6 col-md-3" />
            <x-detail-cell label="去勢・避妊" :value="$trainee->neuter_status_label" cols="col-6 col-md-3" />
        </div>
    </div>

    {{-- 写真＋体重推移グラフ（2026-09 追加、S-0309 設計方針「トレーナー側にも写真と
         体重推移グラフを表示する」参照）。
         位置：属性の下（xl 以上は計測値カードの左、それより狭いときは上）。見出しはカード内に置かない（画面上部に
         トレーニー名 h2 があるため、二重見出しを避ける）。
         内側骨格は会員側 client/dashboard.blade.php と揃える：モバイル（<576px）は
         縦積み・sm 以上は横並び、写真は sm 未満で中央寄せ・sm 以上で上端揃え。
         .c-session 等の client.scss クラスは使わない（app.scss に定義がないため）。
         2026-10 変更：広い画面（xl 以上）では、計測値のカードと横に並べる（左が写真とグラフ、右が計測値）。
         並びの高さはこのカードで決まり、計測値のカードはこの高さにそろえて一覧を中でスクロールする
         （app.scss の .trainee-measurements-scroll）。それより狭いときは今までどおり縦に積む。 --}}
    <div class="row g-3 mb-3" data-trainee-photo-measurements>
        <div class="col-12 col-xl-8">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-start">
                        {{-- 左：トレーニー写真（縦横比を保つ）。大きさは app.scss の .trainee-show-photo（2026-10 変更：
                             sm 以上はグラフと同じ 224px 四方、写真の下にグラフを置くスマホの幅〔sm 未満〕は今までどおり 180px 四方。
                             当初はどの幅でも 180px 四方のインライン style。会員側〔client.scss〕とは共有しない）。
                             トレーナー側は登録・変更・削除の操作を置かない（要件定義書
                             6-15-15 は会員の機能）。クリックしても何も起きない静的な枠にする。 --}}
                        <div class="align-self-center align-self-sm-start" style="flex-shrink: 0;">
                            <div class="trainee-show-photo d-flex align-items-center justify-content-center"
                                 style="background-color: #ffffff; border: 1px solid rgba(15, 26, 46, 0.08); border-radius: 0.5rem; overflow: hidden;">
                                @if($weightChart['photoUrl'])
                                    {{-- object-fit: contain で枠を超えず、余白は白で埋まる（切り抜かない）。
                                         会員側 S-1402 と同じ扱い。 --}}
                                    <img src="{{ $weightChart['photoUrl'] }}"
                                         alt="{{ $trainee->display_name }}の写真"
                                         style="max-width: 100%; max-height: 100%; object-fit: contain; display: block;">
                                @else
                                    {{-- 写真なしのプレースホルダ。会員側は「写真を登録」（登録操作への案内）
                                         だが、トレーナー側は登録操作をしないため文言を「写真なし」に変える。 --}}
                                    <span class="text-muted" style="font-size: 0.875rem;">写真なし</span>
                                @endif
                            </div>
                        </div>

                        {{-- 右：体重推移グラフ。min-width: 0 は flex 子要素の canvas が
                             親幅を超えて突き抜けるのを防ぐ定石（会員側と同じ）。 --}}
                        <div class="flex-grow-1 w-100" style="min-width: 0;">
                            @if(empty($weightChart['datasets']))
                                {{-- 計測値 0 件（会員側と同じ扱い）。空のグラフを描くと意味のない
                                     目盛りが出て不具合に見えるため、canvas を出さず案内文を表示する。
                                     高さは通常のグラフ（224px）と揃える。 --}}
                                <div class="d-flex align-items-center justify-content-center text-muted"
                                     style="height: 224px;">
                                    まだ計測値がありません。
                                </div>
                            @else
                                {{-- data-measurement-chart は datasets のみを渡す（会員側と同じ形）。
                                     線の色：--c-brand-bright は client.scss にしか定義がなく、
                                     トレーナー側は measurement-chart.js のフォールバック #2A4A94
                                     （$brand-bright と同色）が使われる。 --}}
                                {{-- グラフの高さは 224px（2026-10 変更：当初は会員側と同じ 180px。いったん 280px にしたが大きすぎたため 224px。左のカードを高くして、
                                     横に並べた計測値のカードで計測値が 4 件ほど見えるようにするため。Chart.js の設定は変えない） --}}
                                <div style="position: relative; height: 224px;" data-trainee-weight-chart>
                                    <canvas data-measurement-chart="{{ json_encode([
                                        'datasets' => $weightChart['datasets'],
                                    ], JSON_UNESCAPED_UNICODE) }}"></canvas>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 計測値カード（S-0309 セクション「計測値の登録・編集・削除」）
             毎日入力する運用で 1 年で数百件になり得るため、一覧はカードの中でスクロールする
             （件数が増えてもページが伸びないように）。見出し（件数と［新規登録］）はスクロールの外に置き、いつも見える。
             2026-10 変更：当初は max-height: 60vh のスクロール領域を持たせ、写真とグラフのカードの下に置いていた。
             - xl 以上（写真とグラフの右に並べたとき）：カードの高さを左のカードにそろえる（h-100。列は行の高さに伸びる）。
               一覧の枠は .trainee-measurements-scroll で flex: 1 1 0・height: 0 にし、中身（件数）が並びの高さを
               決めないようにする（残りの高さを埋めて、中でスクロールする）
             - それより狭いとき（縦に積むとき）：一覧の枠に最大の高さ（約 10 件分）を決め、超えたら中でスクロールする
             - 列の見出し（計測日など）は、スクロールしても上に残す（position: sticky） --}}
        <div class="col-12 col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">計測値（{{ $measurementCount }}件）</h6>
                    <button type="button" class="btn btn-primary"
                            onclick="window.measurementModals[{{ $trainee->id }}].openForCreate()">新規登録</button>
                </div>
                @if($measurementCount > 0)
                    <div class="table-responsive trainee-measurements-scroll" data-trainee-measurements-scroll>
                        <table class="table table-sm mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>計測日</th>
                                    <th>計測時刻</th>
                                    <th class="text-end">体重（kg）</th>
                                    <th class="text-end" style="min-width: 120px;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($trainee->measurements as $m)
                                    <tr>
                                        <td>{{ $m->measured_date->format('Y/m/d') }}</td>
                                        <td>{{ substr($m->measured_time, 0, 5) }}</td>
                                        <td class="text-end">{{ number_format((float) $m->weight_kg, 2) }}</td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-id="{{ $m->id }}"
                                                    data-measured-date="{{ $m->measured_date->format('Y-m-d') }}"
                                                    data-measured-time="{{ substr($m->measured_time, 0, 5) }}"
                                                    data-weight-kg="{{ $m->weight_kg }}"
                                                    onclick="window.measurementModals[{{ $trainee->id }}].openForEdit(this.dataset)">編集</button>
                                            <form method="POST" action="{{ route('trainee-measurements.destroy', $m) }}"
                                                  class="d-inline"
                                                  onsubmit="return confirm('この計測値を削除しますか？')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">削除</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="card-body">
                        <p class="text-muted mb-0">計測値は登録されていません</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- 性格診断の欄（2026-10 追加。要件定義書 6-16-2・6-16-13、設計書 S-0309「性格診断の欄」）。計測値の下（いちばん下）に置き、いつも出す
         （2026-10 変更：当初は自由記述の質問の欄の下。ユーザーレビューで入れ替えた。2026-10-19 に自由記述の質問の欄を外した）。
         - レーダーチャートを 1 つ（今回・前回・初回を重ねる。personality-radar.js）と、すべての回の結果の表を 1 つ出す。
           xl 以上はグラフが左・表が右、それより狭いときはグラフが上・表が下（2026-10 変更）
           （2026-10 変更：当初は回ごとに見出しの行・レーダーチャート・点数の表を並べ、開閉していた。回数が増えても欄が長くならない
           ように作り直した）。結果がないときは本文に「（未回答）」とだけ出す（グラフも表も出さない）
         - 結果は Trainee::personalityDiagnosisResults()（新しい順。TraineeController::show で読み込み済み）。表は新しい順（2026-10 変更。当初は古い順で、上が初回）
         - 点数は表示の値（小数第 1 位）。保存の値（小数第 2 位）から四捨五入する（TraineePersonalityDiagnosisResult::displayScores()）
         - 点数なし（そのカテゴリの答えがすべて「未経験」）は表で「—」（読み上げは「点数なし」）、タイプの判定なしは「判定なし」。
           グラフでは点を描かない（2026-10-18 追加。要件定義書 6-16）。グラフの下に注記は出さない（表の「—」で分かるため。
           2026-10-18 変更：一度、今回に点数なしがあるときだけ注記を出していた）
         - 表の各行に評価文の状態（なし・下書き・公開済み）と［詳細］を出す。［詳細］で愛犬性格診断詳細（S-0313）へ移る。
           50 問の回答・評価文・結果の削除は S-0313 で行う（2026-10-18 変更：当初は各行の［回答］で 50 問の回答をモーダルで出し、
           管理者には各行の［削除］と確認モーダル S-0309-M01 を出していた）
         - 操作（発行・表示・取消）は置かない（会員詳細 S-0305 だけで行う）
         - id="personality-diagnosis" と scroll-margin-top は、会員詳細の「性格診断」のリンクの飛び先にするため（2026-10-19 に外した自由記述の質問の欄と同じ値） --}}
    @php
        $pdResults = $trainee->personalityDiagnosisResults;
        $pdCategories = \App\Support\PersonalityDiagnosisQuestions::categories();
        // レーダーチャートに重ねる回（今回・前回・初回）と値。会員側のマイページ（S-1402）と同じ組み立てを使う
        // （Trainee::personality_radar_data。2026-10 変更：当初はここで組み立てていた）。結果がなければ null
        $pdRadarData = $trainee->personality_radar_data;
    @endphp
    <div class="card mb-3" id="personality-diagnosis" style="scroll-margin-top: 4.5rem;">
        <div class="card-header">
            <h6 class="mb-0">{{ __('messages.personality_diagnosis_name') }}</h6>
        </div>
        <div class="card-body">
            @if($pdResults->isEmpty())
                <p class="mb-0 text-muted">（未回答）</p>
            @else
                {{-- グラフと表の並び（2026-10 変更）：xl（1200px）以上はグラフを左・表を右に横に並べる（グラフの左右の余白をなくすため）。
                     それより狭いときはグラフが上・表が下（表は 11 列あり、狭い幅で横に並べると窮屈になるため）。
                     align-items-start：回答の回数が多いと表のほうが縦に長くなるので、上にそろえる --}}
                <div class="row gy-3 align-items-start" data-personality-diagnosis-layout>
                    <div class="col-12 col-xl-4">
                        {{-- レーダーチャート（1 つ。今回・前回・初回を重ねる）。グラフは見た目のためのもので、同じ数字を下の表に出すので、
                             読み上げは role="img" と短い説明だけ。最大 300px の正方形（trainee-personality-radar。列の中で中央。
                             2026-10 変更：当初は最大 420px で、欄が縦に長くなっていた）。文字は Chart.js の標準のまま（会員側の compact は使わない） --}}
                        <div class="trainee-personality-radar mx-auto">
                            <canvas id="personality-diagnosis-radar" role="img" aria-label="レーダーチャート（点数は表のとおり）"
                                    data-personality-radar="{{ json_encode($pdRadarData, JSON_UNESCAPED_UNICODE) }}"></canvas>
                        </div>
                        {{-- 凡例（2026-10 変更：Chart.js の凡例はグラフの枠の中に描かれて 2 行に折り返すため、グラフの下に
                             HTML の凡例にした）。項目と線の見本は personality-radar.js が、グラフと同じ値（SERIES_STYLES）から作る。
                             並べ方だけ画面の幅で切り替える（クラスだけ。JS では切り替えない）：グラフが上・表が下のときは 1 行
                             （スマホの幅では収まらなければ折り返す）、xl 以上で横に並べたときは左の列が狭いので縦に 3 行（flex-xl-column）。
                             外の d-flex justify-content-center で、縦 3 行のときも凡例のかたまりをグラフの下の中央に置き、項目は左にそろえる --}}
                        <div class="d-flex justify-content-center mt-2">
                            <ul class="list-unstyled d-flex flex-wrap flex-xl-column align-items-xl-start justify-content-center column-gap-3 row-gap-1 small mb-0"
                                data-personality-radar-legend="personality-diagnosis-radar"></ul>
                        </div>
                    </div>

                    <div class="col-12 col-xl-8">
                        {{-- 結果の表（新しい順。最新を上。並びはグラフの今回・前回・初回と同じ personalityDiagnosisResults の並び〔回答日時の降順、同じ日時なら id の降順〕。2026-10 変更：当初は古い順。スマホの幅では横に流す）。position-relative：最後の列の見出しの visually-hidden（絶対位置）が
                             横に流す枠の外に出てページの幅を広げないよう、枠を位置の基準にする。
                             文字は少し小さくする（small。ボタンは btn-sm のままで小さくしない。btn-sm の文字の大きさは rem なので small の影響を受けない）。
                             タイプは「・」のあとでだけ折り返してよい（横に並べたときに表を収めるため。word-break: keep-all で言葉の途中では
                             折り返さず、「・」のあとに <wbr> を入れる。表示名〔types()〕そのものは変えない）。
                             回答日・点数・ボタンは折り返さない。それでも収まらなければ枠の中で横にスクロールする --}}
                        <div class="table-responsive position-relative">
                            <table class="table table-sm small align-middle mb-0" data-personality-diagnosis-results>
                                <thead>
                                    <tr>
                                        <th scope="col" class="fw-normal text-muted text-nowrap">回答日</th>
                                        <th scope="col" class="fw-normal text-muted text-nowrap">タイプ</th>
                                        @foreach($pdCategories as $categoryLabel)
                                            <th scope="col" class="fw-normal text-muted text-end text-nowrap">{{ $categoryLabel }}</th>
                                        @endforeach
                                        <th scope="col" class="fw-normal text-muted text-nowrap">評価文</th>
                                        <th scope="col"><span class="visually-hidden">操作</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pdResults as $result)
                                        {{-- 1 行の php ディレクティブ（括弧の書き方）にせず、開始と終了の対の書き方にする。この下の自由記述の質問の欄に
                                             対の書き方のブロックがあり、その前に括弧の書き方を置くと、Blade が 2 つを取り違えて構文エラーになるため
                                             （2026-10 に欄の順を入れ替えたときに起きた） --}}
                                        @php $display = $result->displayScores(); @endphp
                                        <tr>
                                            <th scope="row" class="fw-normal text-nowrap">{{ $result->answered_at->format('Y/m/d') }}</th>
                                            <td style="word-break: keep-all;">{!! collect(explode('・', $result->personality_type_label))->map(fn ($part) => e($part))->implode('・<wbr>') !!}</td>
                                            @foreach(array_keys($pdCategories) as $category)
                                                {{-- 点数なし（答えがすべて「未経験」）は「—」。読み上げでは「点数なし」（2026-10-18 追加） --}}
                                                <td class="text-end text-nowrap">@if($display[$category] === null)<span aria-hidden="true">—</span><span class="visually-hidden">点数なし</span>@else{{ $display[$category] }}@endif</td>
                                            @endforeach
                                            {{-- 評価文の状態（2026-10-18 追加。要件定義書 6-16-15）。バッジで出す（色は TraineePersonalityDiagnosisResult::evaluation_status_badge_class。2026-10-18 変更：その前は文字だけで、「未作成」は薄い文字） --}}
                                            <td class="text-nowrap"><span class="badge {{ $result->evaluation_status_badge_class }}">{{ $result->evaluation_status_label }}</span></td>
                                            {{-- ［詳細］：愛犬性格診断詳細（S-0313）へ。任意の操作なので青の白抜き（4-4） --}}
                                            <td class="text-end text-nowrap">
                                                <a href="{{ route('trainee-personality-diagnosis-results.show', [$trainee, $result]) }}" class="btn btn-sm btn-outline-primary text-nowrap"
                                                   aria-label="{{ $result->answered_at->format('Y/m/d') }}の{{ __('messages.personality_diagnosis_name') }}の詳細">詳細</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- 自由記述の質問の欄（愛犬アンケートの回答の自由記述 4 欄）は 2026-10-19 に外した（設計書 S-0309）。4 問を愛犬性格診断へ移し、
         診断ごとの答えは愛犬性格診断詳細（S-0313）の「暮らしの様子」の欄で見る。その前は性格診断の欄の下（いちばん下）に、
         最新の愛犬アンケートの回答の 4 問を出していた（id="intake-survey"。会員詳細の「暮らしの様子」のリンクの飛び先） --}}

    {{-- 計測値の登録・編集モーダル（登録・編集で共用。この画面だけで使う）。登録・編集・削除の後は
         S-0309 に戻る。ID を一意化した辞書形式の経緯は設計書 S-0309「計測値モーダル」参照。 --}}
    @include('trainees._measurement-modal', ['trainee' => $trainee])
</div>

{{-- 性格診断のレーダーチャート用スクリプト（結果があるときだけ読み込む。2026-10 追加） --}}
@if($trainee->personalityDiagnosisResults->isNotEmpty())
    @vite('resources/js/personality-radar.js')
@endif

{{-- 体重推移グラフ用スクリプト（Chart.js を npm でビルドに含める。会員側 S-1402 と同じ
     条件付きの書き方。計測値が 0 件なら canvas を出さないため、スクリプトも読ませない）。 --}}
@if(!empty($weightChart['datasets']))
    @vite('resources/js/measurement-chart.js')
@endif
@endsection
