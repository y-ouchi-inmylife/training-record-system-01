@extends('layouts.app')

@section('title', '音声記録一覧')

@section('content')
<div class="container">
    <h2 class="mb-3">音声記録一覧</h2>

    {{-- 登録者フィルタ＋登録ボタン（2026-09 にナビから移した。設計書 S-0505 設計方針参照）。
         メディア一覧と同じく右端にボタンを置き、幅が狭いときはボタンのまとまりが下の行に折り返す --}}
    <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
        <label for="trainer-filter" class="form-label mb-0 text-nowrap">登録者:</label>
        <select id="trainer-filter" class="form-select" style="width: auto;">
            <option value="all" {{ $selectedTrainerId == 'all' ? 'selected' : '' }}>全員</option>
            @foreach($trainers as $trainer)
                <option value="{{ $trainer->id }}" {{ $selectedTrainerId == $trainer->id ? 'selected' : '' }}>
                    {{ $trainer->name }}
                </option>
            @endforeach
        </select>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <a href="{{ route('recording-v2.index') }}" class="btn btn-primary">録音</a>
            <a href="{{ route('audio-records.upload.create') }}" class="btn btn-primary">音声ファイル</a>
            <a href="{{ route('audio-records.text-paste.create') }}" class="btn btn-primary">文字起こしテキスト</a>
        </div>
    </div>

    {{-- 音声ファイル一覧 --}}
    @if($audioRecords->isEmpty())
        <div class="alert alert-info">
            {{-- スマホ幅ではボタンが右上に来ないため、位置を表す言葉は使わない --}}
            データがありません。「録音」「音声ファイル」「文字起こしテキスト」のボタンから登録してください。
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>日時</th>
                            <th>会員</th>
                            <th>表示名</th>
                            <th>登録者</th>
                            <th>再生</th>
                            <th>再生時間</th>
                            <th></th>
                            <th></th>
                            <th>状態</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($audioRecords as $audio)
                            <tr class="audio-row"
                                data-audio-id="{{ $audio->id }}"
                                style="cursor: pointer;">
                                {{-- 日時 --}}
                                <td>{{ $audio->created_at->format('m/d H:i') }}</td>
                                {{-- クライアント --}}
                                <td>{{ $audio->client->internal_id }} {{ $audio->client->display_name }}</td>
                                {{-- タイトル --}}
                                <td>{{ $audio->title ?? $audio->file_name }}</td>
                                {{-- 担当トレーナー --}}
                                <td>{{ $audio->trainer->name ?? '-' }}</td>
                                {{-- 再生 --}}
                                <td>
                                    @if($audio->file_path)
                                        @php
                                            $ext = strtolower(pathinfo($audio->file_name, PATHINFO_EXTENSION));
                                            $audioMimeTypes = ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'mp4' => 'audio/mp4', 'webm' => 'audio/webm'];
                                            $audioMime = $audioMimeTypes[$ext] ?? 'audio/mpeg';
                                            $isWebM = $ext === 'webm';
                                        @endphp
                                        @if($isWebM)
                                            <div class="webm-player-container">
                                                <audio controls preload="none" playsinline style="height: 32px; width: 200px;" class="webm-audio">
                                                    <source src="{{ route('audio-records.play', $audio) }}" type="{{ $audioMime }}">
                                                </audio>
                                                <div class="webm-not-supported" style="display: none;">
                                                    <small class="text-muted">このデバイスでは再生できません</small>
                                                </div>
                                            </div>
                                        @else
                                            <audio controls preload="none" playsinline style="height: 32px; width: 200px;">
                                                <source src="{{ route('audio-records.play', $audio) }}" type="{{ $audioMime }}">
                                            </audio>
                                        @endif
                                    @endif
                                </td>
                                {{-- 時間 --}}
                                <td>{{ $audio->formatted_duration ?? '-' }}</td>
                                {{-- 文字起こし --}}
                                <td>
                                    @if($audio->canTranscribe())
                                        <button type="button" class="btn btn-sm btn-primary btn-transcribe"
                                                data-audio-id="{{ $audio->id }}"
                                                data-has-transcription="{{ !empty($audio->transcription_text) ? '1' : '0' }}">
                                            文字起こし
                                        </button>
                                        @if($audio->isStalledTranscribing())
                                            <div class="small text-warning mt-1">
                                                処理が中断された可能性があります。もう一度実行してください。
                                            </div>
                                        @endif
                                    @endif
                                </td>
                                {{-- 要約 --}}
                                <td>
                                    @if(!empty($audio->transcription_text))
                                        <button type="button" class="btn btn-sm btn-primary btn-summarize"
                                                data-audio-id="{{ $audio->id }}"
                                                data-has-summary="{{ !empty($audio->summary_text) ? '1' : '0' }}"
                                                {{ $audio->status === \App\Models\AudioRecord::STATUS_SUMMARIZING && !$audio->isStalled() ? 'disabled' : '' }}>
                                            要約
                                        </button>
                                        @if($audio->isStalledSummarizing())
                                            <div class="small text-warning mt-1">
                                                処理が中断された可能性があります。もう一度実行してください。
                                            </div>
                                        @endif
                                    @endif
                                </td>
                                {{-- 状態 --}}
                                <td>
                                    <span class="badge {{ $audio->status_badge_class }}">{{ $audio->status_label }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            {{ $audioRecords->links() }}
        </div>

        {{-- 詳細エリア --}}
        <div id="detail-area" class="card mt-4" style="display: none;">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span>音声記録編集</span>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" form="audio-update-form" id="save-audio-btn" class="btn btn-success">更新</button>
                    <form id="delete-audio-form" method="POST" style="display: none;"
                          onsubmit="if (!this.action) { alert('削除対象が不明です。'); return false; } return confirm('音声ファイルのみ削除します。文字起こし・要約は残ります。よろしいですか?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger">音声ファイルのみ削除</button>
                    </form>
                    <form id="delete-record-form" method="POST" style="display: none;"
                          onsubmit="if (!this.action) { alert('削除対象が不明です。'); return false; } return confirm('この音声記録（音声ファイル + 文字起こし + 要約）を完全に削除します。よろしいですか?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">音声記録を削除</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <form id="audio-update-form" method="POST" action="">
                    @csrf
                    @method('PUT')

                    {{-- 表示名 --}}
                    <div class="mb-3">
                        <label for="detail-title" class="form-label">表示名 <span class="text-danger">*</span></label>
                        <input type="text" id="detail-title" name="title" class="form-control" maxlength="255" required>
                    </div>

                    {{-- タブ --}}
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="transcription-tab" data-bs-toggle="tab"
                                    data-bs-target="#transcription-pane" type="button" role="tab">
                                文字起こし
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="summary-tab" data-bs-toggle="tab"
                                    data-bs-target="#summary-pane" type="button" role="tab">
                                要約
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content mt-3">
                        {{-- 文字起こしタブ --}}
                        <div class="tab-pane fade show active" id="transcription-pane" role="tabpanel">
                            <textarea class="form-control" id="transcription-text" name="transcription_text"
                                      rows="10" placeholder="文字起こしテキストがここに表示されます"></textarea>
                        </div>

                        {{-- 要約タブ --}}
                        <div class="tab-pane fade" id="summary-pane" role="tabpanel">
                            <textarea class="form-control" id="summary-text" name="summary_text"
                                      rows="10" placeholder="要約テキストがここに表示されます"></textarea>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    @endif
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const detailArea = document.getElementById('detail-area');
    const detailTitle = document.getElementById('detail-title');
    const transcriptionText = document.getElementById('transcription-text');
    const summaryText = document.getElementById('summary-text');
    const audioUpdateForm = document.getElementById('audio-update-form');
    let currentAudioId = null;
    let pendingTab = null; // 自動展開時に開くタブ（'transcription' or 'summary'）
    let hasUnsavedChanges = false; // 表示名・文字起こし・要約テキストの未保存変更フラグ
    const UNSAVED_CONFIRM_MESSAGE = '保存されていない変更があります。移動しますか？';

    // 入力欄・テキストエリアの変更検知
    if (detailTitle) {
        detailTitle.addEventListener('input', function() {
            hasUnsavedChanges = true;
        });
    }
    if (transcriptionText) {
        transcriptionText.addEventListener('input', function() {
            hasUnsavedChanges = true;
        });
    }
    if (summaryText) {
        summaryText.addEventListener('input', function() {
            hasUnsavedChanges = true;
        });
    }

    // 保存ボタン（フォームsubmit）でフラグクリア。beforeunload確認を抑制
    if (audioUpdateForm) {
        audioUpdateForm.addEventListener('submit', function() {
            hasUnsavedChanges = false;
        });
    }

    // ページ離脱時の警告（ブラウザの戻る・ページネーション・フィルタ変更等）
    window.addEventListener('beforeunload', function(e) {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = ''; // Chrome/Edge用
        }
    });

    // タブ切り替え時の未保存変更確認（Bootstrap 5のshow.bs.tabイベント）
    document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(function(tabEl) {
        tabEl.addEventListener('show.bs.tab', function(e) {
            if (hasUnsavedChanges && !confirm(UNSAVED_CONFIRM_MESSAGE)) {
                e.preventDefault();
                return;
            }
            // 切替を許可した場合はフラグをリセット（新タブのテキストを基準値とする）
            hasUnsavedChanges = false;
        });
    });

    // 行クリックで詳細エリアを展開
    document.querySelectorAll('.audio-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            // ボタン・フォーム・音声プレイヤークリック時は無視
            if (e.target.closest('form') || e.target.closest('button.btn-transcribe') || e.target.closest('button.btn-summarize') || e.target.closest('audio')) return;

            const audioId = this.dataset.audioId;

            // 未保存の変更がある場合に確認
            if (hasUnsavedChanges && !confirm(UNSAVED_CONFIRM_MESSAGE)) {
                return;
            }

            // 同じ行を再度クリックしたら閉じる
            if (currentAudioId === audioId && detailArea.style.display !== 'none') {
                detailArea.style.display = 'none';
                currentAudioId = null;
                hasUnsavedChanges = false;
                document.querySelectorAll('.audio-row').forEach(r => r.classList.remove('table-active'));
                return;
            }

            currentAudioId = audioId;

            // 行のハイライト
            document.querySelectorAll('.audio-row').forEach(r => r.classList.remove('table-active'));
            this.classList.add('table-active');

            // Ajax で詳細を取得
            fetch('/audio-records/' + audioId, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                }
            })
            .then(response => response.json())
            .then(result => {
                const data = result.data;
                detailTitle.value = data.title || '';
                transcriptionText.value = data.transcription_text || '';
                summaryText.value = data.summary_text || '';

                // フォームのアクションURLを設定
                audioUpdateForm.action = '/audio-records/' + audioId;

                // 音声ファイル削除ボタンの表示制御
                const deleteAudioForm = document.getElementById('delete-audio-form');
                if (data.has_audio_file && data.can_delete && data.delete_audio_url) {
                    deleteAudioForm.action = data.delete_audio_url;
                    deleteAudioForm.style.display = 'inline';
                } else {
                    deleteAudioForm.action = '';
                    deleteAudioForm.style.display = 'none';
                }

                // 音声記録（完全）削除ボタンの表示制御
                const deleteRecordForm = document.getElementById('delete-record-form');
                if (data.can_delete) {
                    deleteRecordForm.action = '/audio-records/' + audioId;
                    deleteRecordForm.style.display = 'inline';
                } else {
                    deleteRecordForm.action = '';
                    deleteRecordForm.style.display = 'none';
                }

                detailArea.style.display = 'block';
                detailArea.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

                // 新しい行のデータをロードしたので未保存フラグをリセット
                hasUnsavedChanges = false;

                // 自動展開時のタブ切り替え
                if (pendingTab) {
                    const tabId = pendingTab === 'summary' ? 'summary-tab' : 'transcription-tab';
                    const tabEl = document.getElementById(tabId);
                    if (tabEl) new bootstrap.Tab(tabEl).show();
                    pendingTab = null;
                }
            })
            .catch(error => {
                console.error('詳細の取得に失敗しました:', error);
                // エラー時は削除ボタンをリセット
                const deleteAudioForm = document.getElementById('delete-audio-form');
                deleteAudioForm.action = '';
                deleteAudioForm.style.display = 'none';
                const deleteRecordForm = document.getElementById('delete-record-form');
                deleteRecordForm.action = '';
                deleteRecordForm.style.display = 'none';
            });
        });
    });

    // --- 文字起こしボタン ---
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    document.querySelectorAll('.btn-transcribe').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const audioId = this.dataset.audioId;
            const hasTranscription = this.dataset.hasTranscription === '1';

            const confirmMessage = hasTranscription
                ? '文字起こしを再実行しますか？既存の文字起こしは上書きされます。'
                : '文字起こしを実行しますか？';
            if (!confirm(confirmMessage)) return;

            // 文字起こし列を「処理中...」に更新
            const cell = this.closest('td');
            cell.innerHTML = '<span class="text-warning"><span class="spinner-border spinner-border-sm me-1" role="status"></span>処理中...</span>';

            fetch('/api/audio-records/' + audioId + '/transcribe', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(data => { throw data; });
                }
                return response.json();
            })
            .then(result => {
                navigateWithHighlight(audioId, 'transcription');
            })
            .catch(error => {
                console.error('文字起こしエラー:', error);
                alert(error?.error?.message || '文字起こしに失敗しました。');
                navigateWithHighlight(audioId, 'transcription');
            });
        });
    });

    // --- 要約ボタン ---
    document.querySelectorAll('.btn-summarize').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const audioId = this.dataset.audioId;
            const hasSummary = this.dataset.hasSummary === '1';

            // 確認ダイアログ
            const confirmMessage = hasSummary
                ? '要約を再実行しますか？既存の要約は上書きされます。'
                : '要約を実行しますか？';
            if (!confirm(confirmMessage)) return;

            // ボタンを無効化してローディング表示
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>処理中...';

            fetch('/api/audio-records/' + audioId + '/summarize', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(data => { throw data; });
                }
                return response.json();
            })
            .then(result => {
                navigateWithHighlight(audioId, 'summary');
            })
            .catch(error => {
                console.error('要約エラー:', error);
                alert(error?.error?.message || '要約に失敗しました。');
                navigateWithHighlight(audioId, 'summary');
            });
        });
    });

    // --- highlight付きURLに遷移するヘルパー ---
    function navigateWithHighlight(audioId, tab) {
        const url = new URL(window.location.href);
        url.searchParams.set('highlight', audioId);
        if (tab) url.searchParams.set('tab', tab);
        window.location.href = url.toString();
    }

    // --- ページ読み込み時の自動展開 ---
    const params = new URLSearchParams(window.location.search);
    const highlightId = params.get('highlight');
    if (highlightId) {
        pendingTab = params.get('tab') || null;
        const targetRow = document.querySelector('.audio-row[data-audio-id="' + highlightId + '"]');
        if (targetRow) {
            targetRow.click();
            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        // URLからhighlight・tabパラメータを除去（履歴を汚さない）
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('highlight');
        cleanUrl.searchParams.delete('tab');
        history.replaceState(null, '', cleanUrl.toString());
    }

    // iOS/iPadデバイスではWebMファイルの再生プレイヤーを非表示にし、メッセージを表示
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    if (isIOS) {
        document.querySelectorAll('.webm-player-container').forEach(function(container) {
            const audioElement = container.querySelector('.webm-audio');
            const notSupportedDiv = container.querySelector('.webm-not-supported');
            if (audioElement && notSupportedDiv) {
                audioElement.style.display = 'none';
                notSupportedDiv.style.display = 'block';
            }
        });
    }

    // トレーナーフィルタの変更時に画面遷移
    const trainerFilter = document.getElementById('trainer-filter');
    if (trainerFilter) {
        trainerFilter.addEventListener('change', function() {
            const value = this.value;
            const url = new URL(window.location.href);
            // ページをリセット
            url.searchParams.delete('page');
            if (value === 'all') {
                url.searchParams.set('trainer_id', 'all');
            } else {
                url.searchParams.set('trainer_id', value);
            }
            window.location.href = url.toString();
        });
    }

});
</script>
@endpush
