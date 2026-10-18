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
    $linkedRecord = $result->trainingRecord;
@endphp

@section('title', $pdName.'詳細')

@section('content')
{{-- 愛犬性格診断詳細（S-0313。2026-10-18 追加。要件定義書 6-16-13〜6-16-15、設計書 S-0313）。
     トレーニー詳細（S-0309）の結果の表の行の［詳細］から開く。結果 1 件のタイプ・レーダーチャート・点数・50問の回答を見て、
     会員様向けの評価文を書く。結果の削除（管理者だけ）もここで行う（2026-10-18 変更：当初は S-0309 の行の［削除］）。
     - レーダーチャートは、この結果を今回にし、それより前の結果から前回・初回を取る（Trainee::personalityRadarDataAsOf）。
       作り（最大 300px の正方形・HTML の凡例・訓練性能を真上・点数なしは点を描かない）は S-0309 と同じ部品
     - 評価文の状態（未作成・下書き・公開済み）は列に持たず、評価文と結び付いたトレーニング記録から決める（evaluation_status）。
       公開済みは表示だけ（直すのはトレーニング記録の編集）
     - ［トレーニング記録にして公開］と確認のモーダル（S-0313-M01）は次の段階で足す --}}
<div class="container">
    <div class="mb-4">
        {{-- 1段目: 操作ボタン列（右寄せ。S-0309・S-0403 と同じ並び）。戻るはグレーの白抜き、削除は画面の対象そのものなので赤の塗り（4-4） --}}
        <div class="d-flex justify-content-end gap-2 mb-2">
            <a href="{{ route('trainees.show', $trainee) }}" class="btn btn-outline-secondary">&laquo; トレーニー詳細へ戻る</a>
            @if(auth()->user()->isAdmin())
                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#personality-diagnosis-delete">削除</button>
            @endif
        </div>

        {{-- 2段目: 見出し（トレーナー側用の表示名。名前が未登録なら「名前未登録」） --}}
        <h2 class="mb-0">{{ $trainee->display_name }}の{{ $pdName }}（{{ $answeredOn }}）</h2>
    </div>

    {{-- 結果（タイプ・この結果の時点のレーダーチャート・7 項目の点数）。並びは S-0309 の欄と同じ：xl 以上はグラフが左、それより狭いときはグラフが上 --}}
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">結果</h6>
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
                         狭い幅では枠の中で横にスクロールする。position-relative は S-0309 の表と同じ理由 --}}
                    <div class="table-responsive position-relative">
                        <table class="table table-sm small align-middle mb-0" data-personality-diagnosis-scores>
                            <thead>
                                <tr>
                                    @foreach($pdCategories as $categoryLabel)
                                        <th scope="col" class="fw-normal text-muted text-end text-nowrap">{{ $categoryLabel }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    @foreach(array_keys($pdCategories) as $category)
                                        <td class="text-end text-nowrap">@if($display[$category] === null)<span aria-hidden="true">—</span><span class="visually-hidden">点数なし</span>@else{{ $display[$category] }}@endif</td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 評価文（要件定義書 6-16-15）。状態は見出しの右に文字で出す（色だけに頼らない）。
         未作成・下書き：テキストエリア（10,000 文字以内）と［保存］（データ保存なので緑。4-4）。空で保存すると未作成に戻る。
         公開済み：表示だけ（改行を保持）と、公開したトレーニング記録へのリンク --}}
    <div class="card mb-3" data-personality-diagnosis-evaluation>
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">評価文</h6>
            <span @class(['small', 'text-muted' => $evaluationStatus === \App\Models\TraineePersonalityDiagnosisResult::EVALUATION_NONE]) data-evaluation-status>{{ $result->evaluation_status_label }}</span>
        </div>
        <div class="card-body">
            @if($isPublished)
                <div class="mb-2" style="white-space: pre-wrap;" data-evaluation-text>{{ $result->evaluation_text }}</div>
            @else
                <x-form-error-summary />
                {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
                <form method="POST" action="{{ route('trainee-personality-diagnosis-results.update', [$trainee, $result]) }}" novalidate>
                    @csrf
                    @method('PUT')
                    <label for="evaluation_text" class="visually-hidden">評価文</label>
                    <textarea class="form-control @error('evaluation_text') is-invalid @enderror" id="evaluation_text" name="evaluation_text"
                              rows="12" maxlength="{{ \App\Models\TraineePersonalityDiagnosisResult::EVALUATION_MAX_LENGTH }}">{{ old('evaluation_text', $result->evaluation_text) }}</textarea>
                    <x-form-error field="evaluation_text" />
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="submit" class="btn btn-success">保存</button>
                    </div>
                </form>
            @endif

            {{-- 最終保存（一度も保存していなければ出さない。トレーナーが削除されていれば日時だけ） --}}
            @if($result->evaluation_updated_at)
                <p class="text-muted small mb-0 @if(! $isPublished) mt-2 @endif" data-evaluation-updated>最終保存：{{ $result->evaluation_updated_at->format('Y/m/d H:i') }}@if($result->evaluationUpdatedBy) {{ $result->evaluationUpdatedBy->name }}@endif</p>
            @endif

            @if($isPublished)
                <p class="mt-3 mb-0" data-evaluation-published>公開済み（<a href="{{ route('training-records.show', $linkedRecord) }}">{{ $linkedRecord->training_date->format('Y/m/d') }}のトレーニング記録</a>）</p>
                <p class="text-muted small mb-0">直すときは、トレーニング記録の編集で直します。</p>
            @endif
        </div>
    </div>

    {{-- 50問の回答（2026-10-18 変更：当初は S-0309 の表の行の［回答］からモーダルで出していた。中身は同じ）。
         設問は結果の設問の版に合った文（PersonalityDiagnosisQuestions::questionsFor()。定義のない版は設問の ID） --}}
    <div class="card mb-3" data-personality-diagnosis-answers>
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

    {{-- 結果の削除確認モーダル（S-0313-M02。管理者だけ。2026-10-18 に S-0309-M01 から移した）。
         結び付いたトレーニング記録があるときは、記録は残るがグラフは消えることを足す。キャンセルは 4-4 のとおり塗り --}}
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
                        @if($linkedRecord)
                            <p class="mb-0 mt-2" data-personality-diagnosis-delete-linked>{{ $linkedRecord->training_date->format('Y/m/d') }}のトレーニング記録に結び付いています。記録は残りますが、記録の中のグラフは消えます。</p>
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
