@extends('layouts.app')

@section('title', 'トレーニー詳細')

@section('content')
<div class="container">
    {{-- ヘッダーサマリー — 会員詳細（S-0305）と同じ 3 段構成に揃える
         （1 段目: 操作ボタン列（右寄せ）／ 2 段目: 名前 + 会員リンク + 内部ID ／
          3 段目: セパレータ下に犬種・性別・誕生日を x-detail-cell で並べ、
                備考は 2 段目に全幅で置く）。詳細は設計書 S-0309 参照。 --}}
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

        {{-- 3段目: セパレータ下の属性。1 段目に犬種・性別・誕生日・去勢・避妊を md 以上で 4 列・
             モバイルで 2 列（col-6 col-md-3）に並べ、2 段目に備考を全幅（col-12）で置く。
             基本情報カードは介さない（設計書 S-0309「設計方針」参照）。
             誕生日は要件定義書 6-16「年齢の表示規則」の形（「2020/03/15（6歳7ヶ月）」など）で、
             組み立ては Trainee の birthday_with_age_label アクセサ。誕生日が未登録なら空欄（§2-4）。
             経緯：2026-09 は犬種・性別・誕生日・備考の 4 列 1 段で、誕生日は「Y/m/d（N歳）」
             （age アクセサで年単位・切り捨て）だった。2026-10 に去勢・避妊を加えるため、備考を
             2 段目の全幅に移し（設計書に「備考を全幅に戻す判断もあり得る」と残していた案）、
             誕生日を年・月・日に分けて表示規則を置き換えた。
             備考は改行保持（white-space: pre-wrap）が必要なため x-detail-cell を使わず
             自前で書く。x-detail-cell の cols 既定値（col-6 col-md-4）は会員詳細
             （S-0305 の連絡先セクション）向けの現状値。呼び出し側で col-6 col-md-3 を渡し、
             会員詳細への影響を出さないようにする。 --}}
        <div class="row g-3 mt-2 pt-2 border-top">
            <x-detail-cell label="犬種" :value="$trainee->breed" cols="col-6 col-md-3" />
            <x-detail-cell label="性別" :value="$trainee->sex_label" cols="col-6 col-md-3" />
            <x-detail-cell label="誕生日" :value="$trainee->birthday_with_age_label" cols="col-6 col-md-3" />
            <x-detail-cell label="去勢・避妊" :value="$trainee->neuter_status_label" cols="col-6 col-md-3" />
            <div class="col-12">
                <div class="text-muted small mb-1">備考</div>
                <div style="min-height: 1.5rem; white-space: pre-wrap;">{{ $trainee->note }}</div>
            </div>
        </div>
    </div>

    {{-- 写真＋体重推移グラフ（2026-09 追加、S-0309 設計方針「トレーナー側にも写真と
         体重推移グラフを表示する」参照）。
         位置：属性の下・計測値カードの上。見出しはカード内に置かない（画面上部に
         トレーニー名 h2 があるため、二重見出しを避ける）。
         内側骨格は会員側 client/dashboard.blade.php と揃える：モバイル（<576px）は
         縦積み・sm 以上は横並び、写真は sm 未満で中央寄せ・sm 以上で上端揃え。
         .c-session 等の client.scss クラスは使わない（app.scss に定義がないため）。 --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-column flex-sm-row gap-3 align-items-start">
                {{-- 左：トレーニー写真（180px 四方、縦横比を保つ）。
                     トレーナー側は登録・変更・削除の操作を置かない（要件定義書
                     6-15-15 は会員の機能）。クリックしても何も起きない静的な枠にする。 --}}
                <div class="align-self-center align-self-sm-start" style="flex-shrink: 0;">
                    <div class="d-flex align-items-center justify-content-center"
                         style="width: 180px; height: 180px; background-color: #ffffff; border: 1px solid rgba(15, 26, 46, 0.08); border-radius: 0.5rem; overflow: hidden;">
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
                             高さは通常のグラフ（180px）と揃える。 --}}
                        <div class="d-flex align-items-center justify-content-center text-muted"
                             style="height: 180px;">
                            まだ計測値がありません。
                        </div>
                    @else
                        {{-- data-measurement-chart は datasets のみを渡す（会員側と同じ形）。
                             線の色：--c-brand-bright は client.scss にしか定義がなく、
                             トレーナー側は measurement-chart.js のフォールバック #2A4A94
                             （$brand-bright と同色）が使われる。 --}}
                        <div style="position: relative; height: 180px;">
                            <canvas data-measurement-chart="{{ json_encode([
                                'datasets' => $weightChart['datasets'],
                            ], JSON_UNESCAPED_UNICODE) }}"></canvas>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- 計測値カード（S-0309 セクション「計測値の登録・編集・削除」）
         毎日入力する運用で 1 年で数百件になり得るため、既存の
         「トレーニング記録」カード（S-0305 セクション2）と同じく
         スクロール領域を持たせる（max-height: 60vh）。 --}}
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">計測値（{{ $measurementCount }}件）</h6>
            <button type="button" class="btn btn-primary"
                    onclick="window.measurementModals[{{ $trainee->id }}].openForCreate()">新規登録</button>
        </div>
        @if($measurementCount > 0)
            <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
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

    {{-- 暮らしの様子の欄（受付アンケートの回答。2026-10 追加。要件定義書 6-16-2・6-16-10、設計書 S-0309
         「暮らしの様子の欄」）。計測値のカードの下に置く（毎日使う計測値を上に残し、一度きりの回答は下に置くため）。
         - 欄は回答の中身（自由記述）を読むためのもの。案内の状態は会員詳細のボタンで分かるため、ここでは状態・日付を
           出さず、回答がないとき（未発行・回答待ち）は本文に「（未回答）」とだけ出す（設問文は出さない。見た目は
           回答済みの空欄の「（未回答）」と同じ text-muted）。2026-10 変更：当初は見出し「受付アンケート」の欄で、
           状態の文字・発行日・回答日時と「案内の発行・取消は会員詳細から行えます。」を出していた。
           見出しは回答画面（S-1413）の小見出しと同じ「暮らしの様子」で、回答の有無で変えない
         - 回答がないときも、欄の id と scroll-margin-top は回答済みのときと同じに付ける（欄の作りを 1 つにそろえる）
         - 発行・表示・取消のボタン、取消モーダル（S-0305-M06）は置かない。受付アンケート案内の操作は会員詳細
           （S-0305）だけで行う（要件定義書 6-16-8 備考）
         - 回答があるかは Trainee::intake_survey_status（TraineeController::show で latestIntakeSurveyResponse・
           activeIntakeSurveyToken を読み込み済み）が「回答済み」かどうかで見る
         - 自由記述 4 欄を、設問文（TraineeIntakeSurveyResponse::questions()。回答画面 S-1413 と共用）と回答の対で
           出し、改行を保持する。設問文の先頭の「●」は S-1413 と同じ作り（aria-hidden="true" で読み上げでは読まず、
           d-flex で折り返したときに 2 行目を「●」の右から始める）。回答の行には付けない。答えなかった設問は
           「（未回答）」（§2-4 の例外。お客様が答えなかったこと自体が情報になるため）。編集・削除の操作は置かない
         - 回答した基本情報（名前・犬種・性別・誕生日・去勢・避妊）は出さない：基本情報の回答はトレーニーの基本情報に
           反映済みで、画面上部と重なるため。回答そのものは trainee_intake_survey_responses に残っている
         - 回答が複数件あるときに新しい順に並べるのは第 2 段階（第 1 段階では 1 件）。今は最新の 1 件
           （latestIntakeSurveyResponse）だけを出す
         - id="intake-survey" と scroll-margin-top は、会員詳細の「暮らしの様子」のリンクから飛ぶ先にするため（見出しを変えても
           id は変えない）。scroll-margin-top はナビゲーションバー（sticky-top）に隠れないための値で、ナビゲーション
           バーの高さ 56px（Bootstrap の既定値から計算：上下の余白 0.5rem×2 ＋ 中身 40px。lg 以上）に
           余白 16px を足した 72px（4.5rem） --}}
    <div class="card mb-3" id="intake-survey" style="scroll-margin-top: 4.5rem;">
        <div class="card-header">
            <h6 class="mb-0">暮らしの様子</h6>
        </div>
        <div class="card-body">
            @if($trainee->intake_survey_status === \App\Models\Trainee::INTAKE_ANSWERED)
                @php
                    $intakeResponse = $trainee->latestIntakeSurveyResponse;
                @endphp
                <dl class="mb-0" data-intake-survey-answers>
                    @foreach(\App\Models\TraineeIntakeSurveyResponse::questions() as $column => $question)
                        <dt class="fw-normal text-muted d-flex"><span class="me-1" aria-hidden="true">●</span><span>{{ $question['question'] }}</span></dt>
                        @if(blank($intakeResponse->{$column}))
                            <dd class="{{ $loop->last ? 'mb-0' : 'mb-3' }} text-muted">（未回答）</dd>
                        @else
                            <dd class="{{ $loop->last ? 'mb-0' : 'mb-3' }}" style="white-space: pre-wrap;">{{ $intakeResponse->{$column} }}</dd>
                        @endif
                    @endforeach
                </dl>
            @else
                <p class="mb-0 text-muted">（未回答）</p>
            @endif
        </div>
    </div>

    {{-- 性格診断の欄（2026-10 追加。要件定義書 6-16-2・6-16-13、設計書 S-0309「性格診断の欄」）。暮らしの様子の欄の下に置き、いつも出す。
         - 上にレーダーチャートを 1 つ（今回・前回・初回を重ねる。personality-radar.js）、下にすべての回の結果の表を 1 つ出す
           （2026-10 変更：当初は回ごとに見出しの行・レーダーチャート・点数の表を並べ、開閉していた。回数が増えても欄が長くならない
           ように作り直した）。結果がないときは本文に「（未回答）」とだけ出す（グラフも表も出さない）
         - 結果は Trainee::personalityDiagnosisResults()（新しい順。TraineeController::show で読み込み済み）。表は古い順（上が初回）
         - 点数は表示の値（小数第 1 位）。保存の値（小数第 2 位）から四捨五入する（TraineePersonalityDiagnosisResult::displayScores()）
         - 表の各行の［50問の回答］で、その回の 50 問の回答をモーダルで出す（1 回ごとにモーダルを置き、中身はサーバーで出す）。
           設問は結果の設問の版に合った文（PersonalityDiagnosisQuestions::questionsFor()。定義のない版は設問の ID）
         - 操作（発行・表示・取消）は置かない（会員詳細 S-0305 だけで行う）
         - id="personality-diagnosis" と scroll-margin-top は、会員詳細の「性格診断」のリンクの飛び先にするため（暮らしの様子と同じ値） --}}
    @php
        $pdResults = $trainee->personalityDiagnosisResults;
        $pdCategories = \App\Support\PersonalityDiagnosisQuestions::categories();
        $pdIdsByCategory = \App\Support\PersonalityDiagnosisQuestions::questionIdsByCategory();
        $pdChoices = \App\Support\PersonalityDiagnosisQuestions::choices();
        // レーダーチャートに渡す値：表と同じ表示の値（小数第 1 位）。今回＝いちばん新しい回、前回＝その 1 つ前
        $pdLatest = $pdResults->first();
        $pdPrevious = $pdResults->get(1);
        $pdRadarData = $pdLatest === null ? null : [
            'labels' => array_values($pdCategories),
            'current' => array_values($pdLatest->displayScores()),
            'previous' => $pdPrevious === null ? null : array_values($pdPrevious->displayScores()),
        ];
    @endphp
    <div class="card mb-3" id="personality-diagnosis" style="scroll-margin-top: 4.5rem;">
        <div class="card-header">
            <h6 class="mb-0">性格診断</h6>
        </div>
        <div class="card-body">
            @if($pdResults->isEmpty())
                <p class="mb-0 text-muted">（未回答）</p>
            @else
                {{-- レーダーチャート（1 つ）。グラフは見た目のためのもので、同じ数字を下の表に出すので、読み上げは role="img" と短い説明だけ --}}
                <div class="mx-auto mb-3 show" style="max-width: 420px;" data-personality-diagnosis-result>
                    <canvas role="img" aria-label="レーダーチャート（点数は下の表のとおり）"
                            data-personality-radar="{{ json_encode($pdRadarData, JSON_UNESCAPED_UNICODE) }}"></canvas>
                </div>

                {{-- 結果の表（古い順。スマホの幅では横に流す） --}}
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" data-personality-diagnosis-results>
                        <thead>
                            <tr>
                                <th scope="col" class="fw-normal text-muted text-nowrap">回答日</th>
                                <th scope="col" class="fw-normal text-muted text-nowrap">タイプ</th>
                                @foreach($pdCategories as $categoryLabel)
                                    <th scope="col" class="fw-normal text-muted text-end text-nowrap">{{ $categoryLabel }}</th>
                                @endforeach
                                <th scope="col"><span class="visually-hidden">50問の回答</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pdResults->reverse() as $result)
                                @php($display = $result->displayScores())
                                <tr>
                                    <th scope="row" class="fw-normal text-nowrap">{{ $result->answered_at->format('Y/m/d') }}</th>
                                    <td class="text-nowrap">{{ $result->personality_type_label }}</td>
                                    @foreach(array_keys($pdCategories) as $category)
                                        <td class="text-end">{{ $display[$category] }}</td>
                                    @endforeach
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                                data-bs-toggle="modal" data-bs-target="#personality-diagnosis-answers-{{ $result->id }}"
                                                aria-label="{{ $result->answered_at->format('Y/m/d') }}の50問の回答">50問の回答</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- 50 問の回答のモーダル（1 回ごと。中身はサーバーで出し、スクリプトで差し替えない）。長いので中身をスクロールできる形 --}}
    @foreach($pdResults as $result)
        @php($questions = \App\Support\PersonalityDiagnosisQuestions::questionsFor($result->question_version))
        <div class="modal fade" id="personality-diagnosis-answers-{{ $result->id }}" tabindex="-1"
             aria-labelledby="personality-diagnosis-answers-{{ $result->id }}-label" aria-hidden="true" data-personality-diagnosis-answers>
            <div class="modal-dialog modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="personality-diagnosis-answers-{{ $result->id }}-label">50問の回答（{{ $result->answered_at->format('Y/m/d') }}）</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        @foreach($pdIdsByCategory as $category => $ids)
                            <p class="fw-bold small mb-1 @if(! $loop->first) mt-3 @endif">{{ $pdCategories[$category] }}</p>
                            <dl class="row small mb-0">
                                @foreach($ids as $id)
                                    @php($value = $result->answers[$id] ?? null)
                                    <dt class="col-sm-8 fw-normal">{{ $questions[$id]['text'] ?? $id }}</dt>
                                    <dd class="col-sm-4 mb-1">{{ $value === null ? '' : ($pdChoices[(string) $value] ?? $value) }}</dd>
                                @endforeach
                            </dl>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endforeach

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
