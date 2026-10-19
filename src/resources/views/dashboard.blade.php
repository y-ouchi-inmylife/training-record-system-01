@extends('layouts.app')

@section('content')
<div class="container">
    {{-- ウェルカムメッセージ --}}
    <div class="alert alert-info mb-4">
        ようこそ、<strong>{{ Auth::user()->name }}</strong> さん。
        （権限: {{ Auth::user()->role_display_name }}）
    </div>

    {{-- 過去 30 日の愛犬性格診断（S-0201。2026-10-20 追加：アドバイスの公開し忘れがひと目で分かるように）。
         最近のトレーニング記録の上。見た目・期間の決め方・行のリンク・0 件の出し方・狭い幅（横スクロール）は最近のトレーニング記録に合わせる。
         公開済みも含めてすべて出し、状態は S-0313 と同じバッジ。未公開（なし・下書き）があれば見出しのあとに件数。
         会員の列のリンクを押したときは、行のリンク（S-0313）を動かさない。
         狭い幅では、列の見出しと回答日・会員・トレーニー・状態の列を折り返さず（text-nowrap）、表を横にスクロールする
         （1 文字ずつに押しつぶされないように）。タイプは「・」のあとでだけ折り返す。見出しの「（未公開 n件）」は途中で折り返さない --}}
    <div class="d-flex align-items-baseline mb-2">
        <h4 class="mb-0" data-recent-diagnoses-heading>過去30日の愛犬性格診断@if($unpublishedDiagnosisCount > 0)<span class="text-nowrap" data-unpublished-diagnosis-count>（未公開 {{ $unpublishedDiagnosisCount }}件）</span>@endif</h4>
    </div>

    @if($recentDiagnoses->isEmpty())
        <div class="alert alert-secondary mb-4" data-recent-diagnoses-empty>
            過去30日の愛犬性格診断はありません。
        </div>
    @else
        <div class="table-responsive mb-4">
            <table class="table table-hover" data-recent-diagnoses>
                <thead class="table-light">
                    <tr>
                        <th class="text-nowrap">回答日</th>
                        <th class="text-nowrap">会員</th>
                        <th class="text-nowrap">トレーニー</th>
                        <th class="text-nowrap">タイプ</th>
                        <th class="text-nowrap">状態</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentDiagnoses as $diagnosis)
                        <tr style="cursor: pointer;" onclick="location.href='{{ route('trainee-personality-diagnosis-results.show', [$diagnosis->trainee, $diagnosis]) }}'">
                            <td class="text-nowrap">{{ $diagnosis->answered_at->format('Y/m/d') }}</td>
                            <td class="text-nowrap"><a href="{{ route('clients.show', $diagnosis->trainee->client) }}" onclick="event.stopPropagation();">{{ $diagnosis->trainee->client->display_name }}</a></td>
                            <td class="text-nowrap">{{ $diagnosis->trainee->display_name }}</td>
                            {{-- タイプは「・」のあとでだけ折り返す（S-0309 の結果の表と同じ） --}}
                            <td style="word-break: keep-all;">{!! collect(explode('・', $diagnosis->personality_type_label))->map(fn ($part) => e($part))->implode('・<wbr>') !!}</td>
                            <td class="text-nowrap"><span class="badge {{ $diagnosis->evaluation_status_badge_class }}">{{ $diagnosis->evaluation_status_label }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- 最近のトレーニング記録（S-0201 セクション「最近のトレーニング記録」、2026-09 追加）。
         過去 1 週間（今日を含む 7 日間、未来日は含めない）を日付の新しい順で表示。
         見出しのすぐ右に「すべて表示」（S-0402 へ）、行の右端に絞り込みセレクト
         （「すべて」/「主担当のみ」、既定は「すべて」）を配置する。セレクトの変更で自動送信。
         詳細な設計方針は screen-design.md S-0201「設計方針」参照。 --}}
    <div class="d-flex justify-content-between align-items-baseline mb-2">
        <div class="d-flex align-items-baseline gap-3">
            <h4 class="mb-0">最近のトレーニング記録</h4>
            <a href="{{ route('training-records.index') }}" class="text-decoration-none">すべて表示</a>
        </div>
        {{-- 絞り込みセレクト：onchange で自動送信。ボタンは持たない（参考画面と同じ流儀）。
             現状ダッシュボードには他のクエリパラメータは無いため、素直に recent だけを送る。 --}}
        <form method="GET" action="{{ route('dashboard') }}" class="mb-0">
            <select name="recent" class="form-select form-select-sm" style="width: auto;"
                    onchange="this.form.submit()">
                <option value="all" {{ $recentFilter === 'all' ? 'selected' : '' }}>すべて</option>
                <option value="primary" {{ $recentFilter === 'primary' ? 'selected' : '' }}>主担当のみ</option>
            </select>
        </form>
    </div>

    @if($recentRecords->isEmpty())
        <div class="alert alert-secondary mb-4">
            過去1週間のトレーニング記録はありません。
        </div>
    @else
        <div class="table-responsive mb-4">
            <table class="table table-hover">
                <thead class="table-light">
                    {{-- 列構成は S-0402 と同じ 7 列（内部ID / 名前 / トレーニー / トレーニング日 / 担当1 / 担当2 / メディア）。
                         ダッシュボードは並べ替えなし（トレーニング日の新しい順で固定）のため、列見出しはリンクにしない。
                         詳細な設計方針は screen-design.md S-0201「設計方針」参照。 --}}
                    <tr>
                        <th>内部ID</th>
                        <th>名前</th>
                        <th>トレーニー</th>
                        <th>トレーニング日</th>
                        <th>担当1</th>
                        <th>担当2</th>
                        <th>メディア</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentRecords as $record)
                        <tr style="cursor: pointer;" onclick="location.href='{{ route('training-records.show', $record) }}'">
                            <td>{{ $record->client->internal_id ?? '' }}</td>
                            <td>{{ $record->client->display_name ?? '' }}</td>
                            <td>{{ $record->client->trainees_label ?? '' }}</td>
                            <td>{{ $record->training_date->format('Y/m/d') }}</td>
                            <td>{{ $record->trainer1->name ?? '' }}</td>
                            <td>{{ $record->trainer2->name ?? '' }}</td>
                            <td>{{ $record->media_records_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
