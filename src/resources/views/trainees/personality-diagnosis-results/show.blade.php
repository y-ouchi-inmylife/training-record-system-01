@extends('layouts.app')

@php
    $pdName = __('messages.personality_diagnosis_name');
    $answeredOn = $result->answered_at->format('Y/m/d');
    $pdCategories = \App\Support\PersonalityDiagnosisQuestions::categories();
    $pdIdsByCategory = \App\Support\PersonalityDiagnosisQuestions::questionIdsByCategory();
    $pdChoices = \App\Support\PersonalityDiagnosisQuestions::choices();
    $questions = \App\Support\PersonalityDiagnosisQuestions::questionsFor($result->question_version);
    $display = $result->displayScores();
    $evaluationStatus = $result->evaluation_status;
    $isPublished = $evaluationStatus === \App\Models\TraineePersonalityDiagnosisResult::EVALUATION_PUBLISHED;
@endphp

@section('title', $pdName.'詳細')

@section('content')
{{-- 愛犬性格診断詳細（S-0313。2026-10-18 追加。要件定義書 6-16-13〜6-16-15、設計書 S-0313）。
     トレーニー詳細（S-0309）の結果の表の行の［詳細］から開く。結果 1 件のタイプ・レーダーチャート・点数・50問の回答を見て、
     会員様向けのアドバイスを書く。結果の削除（管理者だけ）もここで行う（2026-10-18 変更：当初は S-0309 の行の［削除］）。
     - レーダーチャートは、この結果を今回にし、それより前の結果から前回・初回を取る（Trainee::personalityRadarDataAsOf）。
       作り（最大 300px の正方形・HTML の凡例・訓練性能を真上・点数なしは点を描かない）は S-0309 と同じ部品
     - アドバイスの状態（なし・下書き・公開済み）は列に持たず、アドバイスと公開した日時から決める（evaluation_status）。
       公開済みも直せる（直すとそのまま会員様の画面に出る）
     - ［公開］と確認のモーダル（S-0313-M01）、［公開取消］と確認のモーダル（S-0313-M03）。要件定義書 6-16-16。
       公開はアドバイスそのものに公開した日時を付ける（トレーニング記録は作らない。2026-10-18 変更）。アドバイスが空なら［公開］は押せない。
       モーダルの［公開する］で、欄の今の文（保存していない直しを含む）を公開の送り先に送る --}}
<div class="container">
    <div class="mb-4">
        {{-- 1段目: 操作ボタン列（右寄せ。S-0309・S-0403 と同じ並び）。戻るはグレーの白抜き、削除は画面の対象そのものなので赤の塗り（4-4） --}}
        <div class="d-flex justify-content-end gap-2 mb-2">
            <a href="{{ route('trainees.show', $trainee) }}" class="btn btn-outline-secondary">&laquo; トレーニー詳細へ戻る</a>
            @if(auth()->user()->isAdmin())
                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#personality-diagnosis-delete">削除</button>
            @endif
        </div>

        {{-- 2段目: 見出し（トレーナー側用の表示名。名前が未登録なら「名前未登録」）+ 会員（S-0305 リンク）+ 内部ID。
             2026-10-18 変更：ユーザーレビューで、トレーニー詳細（S-0309）の 2 段目と同じ形にした（共通の部品はないので同じ書き方）。
             内部ID は S-0305・S-0309・S-0403 と同じく会員の内部ID（診断の結果の ID ではない）。
             見出しの行の下に線（S-0309 の属性の段の上の線と同じく、見出しの行から 0.5rem 下） --}}
        <div class="d-flex align-items-center gap-2 flex-wrap pb-2 border-bottom" data-personality-diagnosis-heading>
            <h2 class="mb-0">{{ $trainee->display_name }}の{{ $pdName }}（{{ $answeredOn }}）</h2>
            <div class="d-flex align-items-baseline gap-2 ms-3">
                <span class="text-muted small">会員</span>
                <a href="{{ route('clients.show', $trainee->client) }}">{{ $trainee->client->full_name }}</a>
            </div>
            <div class="d-flex align-items-baseline gap-2 ms-3">
                <span class="text-muted small">内部ID</span>
                <span class="font-monospace fs-5">{{ $trainee->client->internal_id }}</span>
            </div>
        </div>
    </div>

    {{-- 結果（タイプ・この結果の時点のレーダーチャート・7 項目の点数）。並びは S-0309 の欄と同じ：xl 以上はグラフが左、それより狭いときはグラフが上 --}}
    {{-- 見出しの右端に「50問の回答」のリンク（2026-10-19 追加：いちばん下の 50問の回答の欄が遠く、スクロールしないと見られなかったため）。
         同じ画面の下の欄（#personality-diagnosis-answers）へ移る。見た目は会員詳細の「性格診断」のリンクと同じふつうの文字のリンク（小さい文字）。
         狭い幅で 1 行に収まらないときは次の行へ折り返して右に寄せる（アドバイスとスタッフ用メモのカードの見出しの行と同じ） --}}
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0">結果</h6>
            <a href="#personality-diagnosis-answers" class="small ms-auto" data-personality-diagnosis-answers-link>50問の回答</a>
        </div>
        <div class="card-body">
            <div class="row gy-3 align-items-start" data-personality-diagnosis-layout>
                <div class="col-12 col-xl-4">
                    <div class="trainee-personality-radar mx-auto">
                        <canvas id="personality-diagnosis-radar" role="img" aria-label="レーダーチャート（点数は表のとおり）"
                                data-personality-radar="{{ json_encode($radarData, JSON_UNESCAPED_UNICODE) }}"></canvas>
                    </div>
                    <div class="d-flex justify-content-center mt-2">
                        <ul class="list-unstyled d-flex flex-wrap flex-xl-column align-items-xl-start justify-content-center column-gap-3 row-gap-1 small mb-0"
                            data-personality-radar-legend="personality-diagnosis-radar"></ul>
                    </div>
                </div>

                <div class="col-12 col-xl-8">
                    <div class="text-muted small">タイプ</div>
                    {{-- タイプは「・」のあとでだけ折り返してよい（S-0309 の表と同じ） --}}
                    <p class="fs-5 mb-3" style="word-break: keep-all;" data-personality-diagnosis-type>{!! collect(explode('・', $result->personality_type_label))->map(fn ($part) => e($part))->implode('・<wbr>') !!}</p>
                    {{-- この結果の 7 項目の点数（1 行の表。S-0309 の表と同じ書き方。点数なしは「—」、読み上げは「点数なし」）。
                         狭い幅では枠の中で横にスクロールする。position-relative は S-0309 の表と同じ理由。
                         幅は中身に合わせる（w-auto。2026-10-18 変更：右の列いっぱいに広がり、数字の間が空いて見えたため）。
                         列の間は Bootstrap の table-sm の余白に px-2 を足して、数字が詰まりすぎないようにする --}}
                    <div class="table-responsive position-relative">
                        <table class="table table-sm small align-middle mb-0 w-auto" data-personality-diagnosis-scores>
                            <thead>
                                <tr>
                                    @foreach($pdCategories as $categoryLabel)
                                        <th scope="col" class="fw-normal text-muted text-end text-nowrap px-2">{{ $categoryLabel }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    @foreach(array_keys($pdCategories) as $category)
                                        <td class="text-end text-nowrap px-2">@if($display[$category] === null)<span aria-hidden="true">—</span><span class="visually-hidden">点数なし</span>@else{{ $display[$category] }}@endif</td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- 自由記述の質問（4 問。2026-10-19 追加。要件定義書 6-16-15、設計書 S-0313「自由記述の質問」）。右の列の点数の表の下
                         （2026-10-19 変更：ユーザーレビューで、結果の欄の右下が空いていたため、診断の答えとして結果と一緒に見られるようにした。
                         その前は「結果」と「アドバイス」のあいだの別のカード）。1200px より狭いときは、グラフ → タイプ → 点数の表 のあとに続く。
                         - 見出しは置かず、点数の表と 4 問のあいだは空き（mt-4。1.5rem）だけで分ける（2026-10-19 変更：ユーザーレビューで、
                           点数の表の下の罫線と区切りの線が 2 本続いて見えたため、区切りの線を外した。空きは問と問のあいだ〔mb-3。1rem〕より広くし、
                           まとまりの切れ目と分かるようにした）。経緯：同じ日に、見出し〔当時の呼び名。トレーナーさんの元のフォームにない言葉〕を外し、
                           会員側の回答の画面（S-1415）と同じ書き方の区切りの線（.border-bottom の空の要素。上下 1rem）を引いていた。
                           S-1415 の区切りの線は変えない（50 問のボタンのあとで、線がないと区切りが分からないため）
                         - 読み上げでは、4 問のまとまりに見えない名前「自由記述の質問」を付ける（role="group" と aria-label。
                           その前は見出しの id を aria-labelledby で結び付けていた）
                         - この診断で答えた 4 問（PersonalityDiagnosisQuestions::lifeQuestions()。回答の画面 S-1415 と共用）を、設問文と答えの対で出す。
                           設問文の先頭の「●」は読み上げでは読まず（aria-hidden）、d-flex で折り返したときに 2 行目を「●」の右から始める。
                           答えは改行を保持（pre-wrap）、空の問は「（未回答）」（text-muted）。見るだけ（編集・削除の操作は置かない） --}}
                    <div class="mt-4" role="group" aria-label="自由記述の質問" data-personality-diagnosis-life>
                        <dl class="mb-0">
                            @foreach(\App\Support\PersonalityDiagnosisQuestions::lifeQuestions() as $column => $question)
                                <dt class="fw-normal text-muted d-flex"><span class="me-1" aria-hidden="true">●</span><span>{{ $question['question'] }}</span></dt>
                                @if(blank($result->{$column}))
                                    <dd class="{{ $loop->last ? 'mb-0' : 'mb-3' }} text-muted" data-life-answer="{{ $column }}">（未回答）</dd>
                                @else
                                    <dd class="{{ $loop->last ? 'mb-0' : 'mb-3' }}" style="white-space: pre-wrap;" data-life-answer="{{ $column }}">{{ $result->{$column} }}</dd>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- アドバイスとスタッフ用メモ（要件定義書 6-16-15・6-16-16・6-16-19、設計書 S-0313「アドバイスとスタッフ用メモのカード」）。
         2026-10-19 変更：ユーザーレビューで、［更新］が 2 つあり分かりにくく、［AIで下書き］がアドバイスのカードにあってスタッフ用メモも
         書き換わるように見えなかったため、2 つのカードを 1 つにまとめ、カードの見出しの右に［AIで自動生成］と［更新］を 1 つずつ置いた。
         その前は「アドバイス」と「スタッフ用メモ」の 2 つのカードにそれぞれ［更新］があり、スタッフ用メモは別のフォーム・別のルート（PUT …/staff-memo）で保存していた。
         - ［更新］は 1 つのフォーム（id="evaluation-form"）で、アドバイスとスタッフ用メモを両方まとめて保存する（PUT …/{result}）。
           ボタンはフォームの外（カードの見出し）にあるので form 属性で送る
         - ［AIで自動生成］（2026-10-19 変更：その前は［AIで下書き］）：いつも使うとはかぎらない操作なので青の枠線（4-4）。押すとアドバイスと
           スタッフ用メモを AI で自動生成し、2 つの欄に入れる（保存はしない。下のスクリプト）。どちらかの欄に文があるときは、先に確かめる（S-0313-M04）
         - 上のまとまり：小見出し「アドバイス」（入力欄の label）＋状態のバッジ（S-0309 の結果の表と同じ色）＋公開済みのときは公開した日。行の右に
           ［公開］（青の塗り。業務の中で必ず使う操作。欄が空〔空白だけを含む〕なら押せない）または［公開取消］（白抜きの赤。4-4）。
           空で保存すると「なし」に戻る（公開済みのときは空にできない）。公開済みのときも直せ、直すとそのまま会員様の画面に出る
         - 下のまとまり：小見出し「スタッフ用メモ」（入力欄の label）＋「（会員様には表示されません）」（text-muted small。入力欄の aria-describedby）。
           トレーナーだけが見るメモで、会員様の画面には一切出さない
         - 2 つのまとまりのあいだは薄い線（border-top）と上下の空きで分ける
         - 見出しの行・アドバイスの小見出しの行とも、上下の真ん中をそろえ、狭い幅で 1 行に収まらないときはボタンを次の行へ折り返して右に寄せる
           （flex-wrap と ms-auto） --}}
    {{-- AI の自動生成のお知らせ（2026-10-19 追加）。［AIで自動生成］のあと、下のスクリプトがこの中にお知らせを作って出す（成功は緑、失敗は赤）。
         最初は空。見た目・消え方・読み上げ（role="alert"）はレイアウトのフラッシュメッセージと同じ
         （2026-10-19 変更：緑は data-auto-dismiss を付けて 5 秒で消す。赤はほかのエラーと同じく消さない。どちらも閉じるボタンあり。
         その前は、この要素そのものに role="status" を付け、色を付け替えて出したままにしていた） --}}
    <div data-evaluation-draft-notice></div>

    <div class="card mb-3" data-personality-diagnosis-evaluation>
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0">アドバイスとスタッフ用メモ</h6>
            {{-- ［AIで自動生成（Claude／OpenAI／Gemini）］（2026-10-20 変更：しばらく 3 社を比べながら使い、最終的に 1 つに絞るため。
                 その前は［AIで自動生成］1 つ）。キーとモデルの設定がそろっている AI の分だけ、この順で出す（$draftProviders。EvaluationDraftProviders）。
                 狭い幅で収まらないときは、まとまりごと次の行へ折り返して右に寄せ、まとまりの中で折り返しても右に寄せる。ボタンの文字は折り返さない --}}
            <div class="d-flex flex-wrap justify-content-end gap-2 ms-auto" data-evaluation-buttons>
                @foreach($draftProviders as $provider => $providerLabel)
                    <button type="button" class="btn btn-outline-primary text-nowrap" data-evaluation-draft data-provider="{{ $provider }}"
                            data-url="{{ route('trainee-personality-diagnosis-results.evaluation-draft', [$trainee, $result]) }}">AIで自動生成（{{ $providerLabel }}）</button>
                @endforeach
                <button type="submit" form="evaluation-form" class="btn btn-success">更新</button>
            </div>
        </div>
        <div class="card-body">
            <x-form-error-summary />
            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
            <form method="POST" action="{{ route('trainee-personality-diagnosis-results.update', [$trainee, $result]) }}" novalidate id="evaluation-form" data-evaluation-form>
                @csrf
                @method('PUT')
                {{-- 992px 以上は横に並べる（アドバイス 8/12・スタッフ用メモ 4/12。2026-10-19 変更：見比べながら直せるように。
                     その前はどの幅でも縦に並べていた）。992px 未満は今のまま縦（アドバイス → 横線 → スタッフ用メモ）。
                     左右の小見出しの行の高さ・入力欄の高さは app.scss の evaluation-heading-row・evaluation-textarea でそろえる --}}
                <div class="row" data-evaluation-columns>
                    <div class="col-12 col-lg-8" data-evaluation-section>
                        <div class="evaluation-heading-row d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <label for="evaluation_text" class="fw-bold mb-0">アドバイス</label>
                                {{-- 状態はバッジ（S-0309 の結果の表と同じ色。2026-10-18 変更：その前は小さな文字で、「未作成」は薄い文字） --}}
                                <span class="badge {{ $result->evaluation_status_badge_class }}" data-evaluation-status>{{ $result->evaluation_status_label }}</span>
                                {{-- 公開済みのときだけ、バッジの右に公開した日（会員様の一覧に出る日付と同じ） --}}
                                @if($isPublished)
                                    <span class="text-muted small" data-evaluation-published-on>{{ $result->evaluation_published_at->format('Y/m/d') }} 公開</span>
                                @endif
                            </div>
                            <div class="d-flex flex-wrap gap-2 ms-auto" data-evaluation-publish-buttons>
                                @if($isPublished)
                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#personality-diagnosis-unpublish"
                                            data-evaluation-unpublish>公開取消</button>
                                @else
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#personality-diagnosis-publish"
                                            data-evaluation-publish @disabled(blank(old('evaluation_text', $result->evaluation_text)))>公開</button>
                                @endif
                            </div>
                        </div>
                        <textarea class="form-control evaluation-textarea @error('evaluation_text') is-invalid @enderror" id="evaluation_text" name="evaluation_text"
                                  rows="12" maxlength="{{ \App\Models\TraineePersonalityDiagnosisResult::EVALUATION_MAX_LENGTH }}">{{ old('evaluation_text', $result->evaluation_text) }}</textarea>
                        <x-form-error field="evaluation_text" />
                    </div>

                    <div class="col-12 col-lg-4">
                        <div class="evaluation-staff-memo border-top mt-3 pt-3" data-personality-diagnosis-staff-memo>
                            <div class="evaluation-heading-row d-flex flex-wrap align-items-center gap-2 mb-2">
                                <label for="staff_memo" class="fw-bold mb-0">スタッフ用メモ</label>
                                <span class="text-muted small" id="staff-memo-note" data-staff-memo-note>（会員様には表示されません）</span>
                            </div>
                            <textarea class="form-control evaluation-textarea @error('staff_memo') is-invalid @enderror" id="staff_memo" name="staff_memo" aria-describedby="staff-memo-note"
                                      rows="6" maxlength="{{ \App\Models\TraineePersonalityDiagnosisResult::STAFF_MEMO_MAX_LENGTH }}">{{ old('staff_memo', $result->staff_memo) }}</textarea>
                            <x-form-error field="staff_memo" />
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- 50問の回答（2026-10-18 変更：当初は S-0309 の表の行の［回答］からモーダルで出していた。中身は同じ）。
         設問は結果の設問の版に合った文（PersonalityDiagnosisQuestions::questionsFor()。定義のない版は設問の ID）。
         id と scroll-margin-top は、「結果」の欄の見出しの右の「50問の回答」のリンクの移り先にするため（2026-10-19 追加。
         上の固定のメニューに見出しが隠れないよう、トレーニー詳細の愛犬性格診断の欄〔#personality-diagnosis〕と同じ値） --}}
    <div class="card mb-3" id="personality-diagnosis-answers" style="scroll-margin-top: 4.5rem;" data-personality-diagnosis-answers>
        <div class="card-header">
            <h6 class="mb-0">50問の回答</h6>
        </div>
        <div class="card-body">
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

    {{-- 最終更新（アドバイスを最後に更新した日時とトレーナー。一度も保存していなければ出さない。公開済みでも出す。トレーナーが削除されて
         いれば日時だけ）。トレーニング記録詳細（S-0403）・会員詳細（S-0305）と同じく、画面のいちばん下に右寄せで小さく置く
         （2026-10-18 変更：ユーザーレビューで、ほかの画面と同じ置き方にした。その前は評価文のカードの中、テキストエリアのすぐ下に左寄せ） --}}
    @if($result->evaluation_updated_at)
        <div class="text-end text-muted small mb-3" data-evaluation-updated>
            最終更新: {{ $result->evaluation_updated_at->format('Y/m/d H:i') }}@if($result->evaluationUpdatedBy?->name) {{ $result->evaluationUpdatedBy->name }}@endif
        </div>
    @endif
    {{-- 公開の確認モーダル（S-0313-M01。2026-10-18 追加）。公開済みでないときだけ置く。［公開する］で、アドバイスの欄の今の文を
         （2026-10-19 変更：スタッフ用メモの欄の今の文も）隠しの欄に写してから送る（下のスクリプト。二重に押されないよう、送ったらボタンを押せなくする）。キャンセルは 4-4 のとおり塗り。
         2026-10-18 変更：公開でトレーニング記録を作らなくなったため、見出しと文を変えた（その前は「トレーニング記録にして公開」）。
         2026-10-18 変更：ユーザーレビューで、見出しを「{名前}の公開」、文を「会員のマイページに{名前}を公開します。」にした
         （その前は「評価文の公開」と、直せること・すぐ見えることを添えた文）。名前は $pdName（lang/ja/messages.php の 1 か所）。
         2026-10-18 変更：ユーザーレビューで、文のあとに「よろしいですか？」を足した（同じ行に続け、全角の「？」。トレーナー側のほかの確認にそろえた）。
         2026-10-18 変更：ユーザーレビューで、公開取消の確認（M03「会員のマイページから{名前}の公開を取り消しますか？」）と言い方をそろえ、
         「会員のマイページに{名前}を公開しますか？」にした（その前は「〜を公開します。よろしいですか？」） --}}
    {{-- AI の自動生成の確かめモーダル（S-0313-M04。2026-10-19 追加）。アドバイスかスタッフ用メモのどちらかに文があるときだけ、［AIで自動生成］で開く
         （どちらも空なら開かずに作る。2026-10-19 変更：見出しと文を「AIで下書き」「…AIの下書きで置き換えます。…」から変えた）。［キャンセル］は灰色の塗り（この画面のほかの確認のモーダルと同じ。4-4）、［置き換える］は青の塗り
         （設計書 S-0313-M04。2026-10-19 変更：その前の［キャンセル］は赤の枠線） --}}
    <div class="modal fade" id="personality-diagnosis-draft-confirm" tabindex="-1"
         aria-labelledby="personality-diagnosis-draft-confirm-label" aria-hidden="true" data-evaluation-draft-confirm>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="personality-diagnosis-draft-confirm-label">AIで自動生成</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">アドバイスとスタッフ用メモを、AIで自動生成した内容で置き換えます。よろしいですか？</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="button" class="btn btn-primary" data-evaluation-draft-replace>置き換える</button>
                </div>
            </div>
        </div>
    </div>

    @if(! $isPublished)
        <div class="modal fade" id="personality-diagnosis-publish" tabindex="-1"
             aria-labelledby="personality-diagnosis-publish-label" aria-hidden="true" data-personality-diagnosis-publish>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="personality-diagnosis-publish-label">{{ $pdName }}の公開</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">会員のマイページに{{ $pdName }}を公開しますか？</p>
                        {{-- 2026-10-19 追加：［公開する］で 2 つの欄の今の文を保存してから公開することを伝える（1 行目と同じ大きさ・色。間を少し空ける） --}}
                        <p class="mb-0 mt-2" data-evaluation-publish-saves>現在の編集内容は保存されます。</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <form method="POST" action="{{ route('trainee-personality-diagnosis-results.publish', [$trainee, $result]) }}" class="d-inline m-0" data-evaluation-publish-form>
                            @csrf
                            <input type="hidden" name="evaluation_text" value="">
                            {{-- 2026-10-19 追加：スタッフ用メモの欄の今の文も送り、保存してから公開する --}}
                            <input type="hidden" name="staff_memo" value="">
                            <button type="submit" class="btn btn-primary">公開する</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- 公開取消確認モーダル（S-0313-M03。2026-10-18 追加）。公開済みのときだけ置く。見せ方は S-0305-M06（愛犬アンケート案内の取消）と
         同じ形（説明文・実行ボタン btn-danger・キャンセル）。［取り消す］で 2 つの欄の今の文も送り、保存してから公開を取り消す
         （2026-10-19 変更：［公開する］とそろえた。その前は、欄に保存していない直しがあっても送らず、アドバイスは最後に保存した文のままだった）。
         二重に押されないよう、送ったらボタンを押せなくする（下のスクリプト）。
         2026-10-18 変更：ユーザーレビューで、［公開をやめる］を［公開取消］にし、見出し「{名前}の公開を取り消す」・文「会員のマイページから{名前}の公開を
         取り消しますか？」・実行ボタン［取り消す］（ほかの取消の確認 S-0305-M03・M06・M07 と同じ）にした。名前は $pdName（M01 と同じ）。
         その前は見出し「アドバイスの公開をやめる」・文「会員様のマイページからアドバイスを外します。アドバイスは下書きとして残ります。」・実行ボタン［公開をやめる］ --}}
    @if($isPublished)
        <div class="modal fade" id="personality-diagnosis-unpublish" tabindex="-1"
             aria-labelledby="personality-diagnosis-unpublish-label" aria-hidden="true" data-personality-diagnosis-unpublish>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="personality-diagnosis-unpublish-label">{{ $pdName }}の公開を取り消す</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">会員のマイページから{{ $pdName }}の公開を取り消しますか？</p>
                        {{-- 2026-10-19 追加：［取り消す］で 2 つの欄の今の文を保存してから公開を取り消すことを伝える（M01 の 2 行目と同じ見た目） --}}
                        <p class="mb-0 mt-2" data-evaluation-unpublish-saves>現在の編集内容は保存されます。</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <form method="POST" action="{{ route('trainee-personality-diagnosis-results.unpublish', [$trainee, $result]) }}" class="d-inline m-0" data-evaluation-unpublish-form>
                            @csrf
                            {{-- 2026-10-19 追加：アドバイスとスタッフ用メモの欄の今の文も送り、保存してから公開を取り消す（下のスクリプトで入れる） --}}
                            <input type="hidden" name="evaluation_text" value="">
                            <input type="hidden" name="staff_memo" value="">
                            <button type="submit" class="btn btn-danger">取り消す</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- 結果の削除確認モーダル（S-0313-M02。管理者だけ。2026-10-18 に S-0309-M01 から移した）。
         公開済みのアドバイスがあるときは、会員様のマイページからも消えることを足す（2026-10-18 変更：その前は、
         結び付いたトレーニング記録が残ることの文）。キャンセルは 4-4 のとおり塗り --}}
    @if(auth()->user()->isAdmin())
        <div class="modal fade" id="personality-diagnosis-delete" tabindex="-1"
             aria-labelledby="personality-diagnosis-delete-label" aria-hidden="true" data-personality-diagnosis-delete>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="personality-diagnosis-delete-label">{{ $pdName }}の結果の削除</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">{{ $answeredOn }} の{{ $pdName }}の結果を削除します。元に戻せません。</p>
                        @if($isPublished)
                            <p class="mb-0 mt-2" data-personality-diagnosis-delete-published>公開済みのアドバイスも、会員様のマイページから消えます。</p>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <form method="POST" action="{{ route('trainee-personality-diagnosis-results.destroy', [$trainee, $result]) }}" class="d-inline m-0">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">削除する</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

{{-- レーダーチャート（S-0309 と同じ部品） --}}
@vite('resources/js/personality-radar.js')
@endsection

@push('scripts')
{{-- アドバイスの公開（S-0313-M01）と公開取消（S-0313-M03）。2026-10-18 追加。
     - 欄が空（空白だけ）なら［公開］を押せなくする
     - モーダルの［公開する］では、欄の今の文（アドバイスとスタッフ用メモ。2026-10-19 からスタッフ用メモも）を隠しの欄に写して送る
     - どちらのモーダルも、送ったら実行のボタンを押せなくする（二重に押されないように） --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 画面を離れるときの警告（2026-10-19 追加。設計書 S-0313「アドバイスとスタッフ用メモのカード」）。ほかの編集画面（S-0602 アドバイスの
    // プロンプトなど）と同じ UnsavedChangesGuard を使う。見張るのはカードのフォーム（アドバイスとスタッフ用メモの 2 つの入力欄）。
    // ［更新］はこのフォームを送るので警告しない。M01 の［公開する］は別のフォームなので、送る前に下で「変わっている」を消す
    var unsavedGuard = new window.UnsavedChangesGuard({
        formSelector: '#evaluation-form',
        leaveLinkSelector: '.js-leave-link'
    });
    unsavedGuard.init();

    var textarea = document.querySelector('[data-evaluation-form] textarea[name="evaluation_text"]');
    var openButton = document.querySelector('[data-evaluation-publish]');
    var publishForm = document.querySelector('[data-evaluation-publish-form]');
    var unpublishForm = document.querySelector('[data-evaluation-unpublish-form]');
    if (textarea && openButton && publishForm) {
        var update = function () {
            openButton.disabled = textarea.value.trim() === '';
        };
        textarea.addEventListener('input', update);
        update();
        publishForm.addEventListener('submit', function () {
            publishForm.querySelector('input[name="evaluation_text"]').value = textarea.value;
            // 2026-10-19 追加：スタッフ用メモの欄の今の文も送る（保存してから公開する）
            var memoField = document.querySelector('[data-evaluation-form] textarea[name="staff_memo"]');
            if (memoField) {
                publishForm.querySelector('input[name="staff_memo"]').value = memoField.value;
            }
            publishForm.querySelector('button[type="submit"]').disabled = true;
            // 2 つの欄を保存してから公開するので、画面を離れるときの警告は出さない（2026-10-19 追加）
            unsavedGuard.hasChanges = false;
        });
    }
    if (unpublishForm) {
        unpublishForm.addEventListener('submit', function () {
            // 2 つの欄の今の文を送り、保存してから公開を取り消す（2026-10-19 追加。［公開する］と同じ）
            unpublishForm.querySelector('input[name="evaluation_text"]').value = textarea.value;
            unpublishForm.querySelector('input[name="staff_memo"]').value = document.querySelector('[data-evaluation-form] textarea[name="staff_memo"]').value;
            unpublishForm.querySelector('button[type="submit"]').disabled = true;
            // 保存してから公開を取り消すので、画面を離れるときの警告は出さない（2026-10-19 追加。確認が 2 つ重なって出ないように）
            unsavedGuard.hasChanges = false;
        });
    }

    // ［AIで自動生成］（2026-10-19 追加。設計書 S-0313「AIで自動生成・スタッフ用メモ」。2026-10-19 にボタン名と文を変えた。前の文は Blade のコメントと設計書に残す）
    // - どちらかの欄に文があれば、先に確かめのモーダル（S-0313-M04）。どちらも空なら確かめずに作る
    // - 作っている間は押したボタンを「生成中…」にし、3 つとも押せなくする（同時に 2 つ走らないように）。返ってきたら 2 つの欄に入れる（保存はしない）
    // - 欄に入れたら input のイベントを出し、［公開］を押せるかどうか（上の update）も動かす
    // - 2026-10-20 変更：ボタンを AI の会社ごとの 3 つにした。押したボタンの provider を送り、M04 から作るときはどのボタンから開いたかを覚えておく
    var draftButtons = Array.prototype.slice.call(document.querySelectorAll('[data-evaluation-draft]'));
    var staffMemo = document.querySelector('[data-evaluation-form] textarea[name="staff_memo"]');
    var notice = document.querySelector('[data-evaluation-draft-notice]');
    var confirmEl = document.querySelector('[data-evaluation-draft-confirm]');
    if (draftButtons.length > 0 && textarea && staffMemo && notice && confirmEl) {
        var pendingButton = null;
        // お知らせはレイアウトのフラッシュメッセージと同じ作り。閉じると要素ごと外れるので、出すたびに作り直す
        var showNotice = function (ok, message) {
            notice.replaceChildren();
            var alertEl = document.createElement('div');
            alertEl.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' alert-dismissible fade show';
            alertEl.setAttribute('role', 'alert');
            alertEl.appendChild(document.createTextNode(message));
            var closeButton = document.createElement('button');
            closeButton.type = 'button';
            closeButton.className = 'btn-close';
            closeButton.setAttribute('data-bs-dismiss', 'alert');
            alertEl.appendChild(closeButton);
            notice.appendChild(alertEl);
            if (ok) {
                // 緑はほかの完了メッセージと同じく 5 秒で消す（赤のエラーは消さない）
                alertEl.setAttribute('data-auto-dismiss', '');
                window.autoDismissAlert(alertEl);
            }
        };
        var makeDraft = function (button) {
            var label = button.textContent;
            draftButtons.forEach(function (b) { b.disabled = true; });
            button.textContent = '生成中…';
            notice.replaceChildren();
            fetch(button.getAttribute('data-url'), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
                body: JSON.stringify({ provider: button.getAttribute('data-provider') }),
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    if (!response.ok || typeof body.evaluation !== 'string') {
                        throw new Error('draft failed');
                    }
                    return body;
                });
            }).then(function (body) {
                textarea.value = body.evaluation;
                staffMemo.value = body.staff_memo || '';
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                staffMemo.dispatchEvent(new Event('input', { bubbles: true }));
                // お知らせは何をしたかだけを伝える（2026-10-19 変更：次にすることの指示は書かず、「文」という言葉も使わない）
                var message = body.staff_memo_missing
                    ? 'AIでアドバイスを自動生成しました。スタッフ用メモは自動生成できませんでした。'
                    : 'AIでアドバイスとスタッフ用メモを自動生成しました。';
                showNotice(true, message);
            }).catch(function () {
                showNotice(false, 'AIで自動生成できませんでした。時間をおいてもう一度お試しください。');
            }).finally(function () {
                button.textContent = label;
                draftButtons.forEach(function (b) { b.disabled = false; });
            });
        };
        draftButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (textarea.value.trim() === '' && staffMemo.value.trim() === '') {
                    makeDraft(button);
                    return;
                }
                pendingButton = button;
                bootstrap.Modal.getOrCreateInstance(confirmEl).show();
            });
        });
        confirmEl.querySelector('[data-evaluation-draft-replace]').addEventListener('click', function () {
            bootstrap.Modal.getOrCreateInstance(confirmEl).hide();
            if (pendingButton) {
                makeDraft(pendingButton);
                pendingButton = null;
            }
        });
    }
});
</script>
@endpush
