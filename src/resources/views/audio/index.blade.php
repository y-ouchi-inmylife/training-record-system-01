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
                            <th>状態</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($audioRecords as $audio)
                            {{-- data-* は編集パネルの「文字起こし」「要約」ボタンの表示・押せる条件と、中断の案内に使う
                                 （パネルを開いたときに JS が読む。設計書 S-0505 音声記録編集の画面項目参照） --}}
                            <tr class="audio-row"
                                data-audio-id="{{ $audio->id }}"
                                data-can-transcribe="{{ $audio->canTranscribe() ? '1' : '0' }}"
                                data-has-transcription="{{ !empty($audio->transcription_text) ? '1' : '0' }}"
                                data-summarize-locked="{{ $audio->isProcessing() && !$audio->isStalled() ? '1' : '0' }}"
                                data-has-summary="{{ !empty($audio->summary_text) ? '1' : '0' }}"
                                data-stalled="{{ $audio->isStalled() ? '1' : '0' }}"
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
                                {{-- 状態 --}}
                                <td>
                                    <span class="badge audio-status-badge {{ $audio->status_badge_class }}">{{ $audio->status_label }}</span>
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
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span>音声記録編集</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    {{-- 文字起こし・要約：パネルで開いている記録に対して実行する。表示・押せる条件は行の data-* から切り替える --}}
                    <button type="button" id="detail-transcribe-btn" class="btn btn-primary" style="display: none;">文字起こし</button>
                    <button type="button" id="detail-summarize-btn" class="btn btn-primary" style="display: none;">要約</button>
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
                {{-- 処理中のまま止まった記録を開いたときだけ表示する中断の案内 --}}
                <div id="detail-stalled-notice" class="small text-warning mb-2" style="display: none;">
                    <i class="bi bi-exclamation-triangle"></i> 処理が中断された可能性があります。もう一度実行してください。
                </div>
                <form id="audio-update-form" method="POST" action="">
                    @csrf
                    @method('PUT')

                    {{-- 表示名：§4-5 の水平レイアウト（会員の登録・編集と同じ組み方。項目が 1 つなので外側の列は全幅） --}}
                    <div class="row g-3 mb-2">
                        <div class="col-12">
                            <div class="row g-2 align-items-center">
                                <label for="detail-title" class="col-md-auto col-form-label text-md-end form-label-fixed">
                                    表示名 <span class="text-danger">*</span>
                                </label>
                                <div class="col-12 col-md">
                                    <input type="text" id="detail-title" name="title" class="form-control" maxlength="255" required
                                           autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 音声ファイルの有無：表示のみ（送信しない）。§4-5 の水平レイアウトで、表示だけの項目は
                         会員編集のメールアドレスと同じく form-control-plaintext にする。値はパネルを開くたびに JS で設定する --}}
                    <div class="row g-3 mb-2">
                        <div class="col-12">
                            <div class="row g-2 align-items-center">
                                <label class="col-md-auto col-form-label text-md-end form-label-fixed">音声ファイル</label>
                                <div class="col-12 col-md">
                                    <div id="detail-has-audio" class="form-control-plaintext"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 文字起こし・要約：見比べながら編集できるよう左右に並べる（lg 未満では上下に積む）。
                         md で 2 等分すると 1 つあたりが狭く編集しにくいため col-lg-6 にする（設計書 S-0505 設計方針参照） --}}
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label for="transcription-text" class="form-label">文字起こし</label>
                            <textarea class="form-control" id="transcription-text" name="transcription_text"
                                      rows="10" placeholder="文字起こしテキストがここに表示されます"></textarea>
                        </div>
                        <div class="col-lg-6">
                            <label for="summary-text" class="form-label">要約</label>
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
    const detailHasAudio = document.getElementById('detail-has-audio');
    const transcriptionText = document.getElementById('transcription-text');
    const summaryText = document.getElementById('summary-text');
    const audioUpdateForm = document.getElementById('audio-update-form');
    let currentAudioId = null;
    let hasUnsavedChanges = false; // 表示名・文字起こし・要約テキストの未保存変更フラグ
    let isRunningAction = false; // 編集パネルから文字起こし・要約を実行中か（応答後にページを読み込み直すまで true）
    const detailTranscribeBtn = document.getElementById('detail-transcribe-btn');
    const detailSummarizeBtn = document.getElementById('detail-summarize-btn');
    const detailStalledNotice = document.getElementById('detail-stalled-notice');
    // 処理中のバッジの文言・色は、サーバーが描画するときと同じ対応表を使う
    const STATUS_LABELS = @json(\App\Models\AudioRecord::statusLabels());
    const STATUS_BADGE_CLASSES = @json(\App\Models\AudioRecord::statusBadgeClasses());
    const STATUS_TRANSCRIBING = @json(\App\Models\AudioRecord::STATUS_TRANSCRIBING);
    const STATUS_SUMMARIZING = @json(\App\Models\AudioRecord::STATUS_SUMMARIZING);
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

    // 行クリックで詳細エリアを展開
    document.querySelectorAll('.audio-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            // フォーム・音声プレイヤークリック時は無視
            if (e.target.closest('form') || e.target.closest('audio')) return;

            // 文字起こし・要約の実行中は、別の記録を選べないようにする（処理後にページを読み込み直すため）
            if (isRunningAction) return;

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

            // 編集パネルの文字起こし・要約ボタンと中断の案内を、この行の状態に合わせる
            applyDetailActions(this);

            // 詳細を取得するまでは音声ファイルの有無を空にする（前の記録の値を残さない）
            detailHasAudio.textContent = '';

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
                // 音声ファイルの有無（「音声ファイルのみ削除」ボタンの判定と同じ has_audio_file を使う）
                detailHasAudio.textContent = data.has_audio_file ? 'あり' : 'なし';

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

    // CSRF トークン（編集パネルの文字起こし・要約の呼び出しで使う）
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // --- 編集パネルの文字起こし・要約ボタン ---

    // 選んだ行の data-* から、ボタンの表示・押せる条件と中断の案内を切り替える
    function applyDetailActions(row) {
        const d = row.dataset;
        detailTranscribeBtn.style.display = d.canTranscribe === '1' ? 'inline-block' : 'none';
        detailSummarizeBtn.style.display = d.hasTranscription === '1' ? 'inline-block' : 'none';
        // 文字起こし中・要約中（止まっていないもの）は要約を押せなくする
        detailSummarizeBtn.disabled = d.summarizeLocked === '1';
        detailStalledNotice.style.display = d.stalled === '1' ? 'block' : 'none';
    }

    // 実行中は、パネルの他のボタン・行の選択・登録者の絞り込みを操作できなくし、行のバッジを処理中にする
    function lockForAction(audioId, clickedBtn, status) {
        isRunningAction = true;
        [detailTranscribeBtn, detailSummarizeBtn, document.getElementById('save-audio-btn')]
            .concat(Array.from(document.querySelectorAll('#delete-audio-form button, #delete-record-form button')))
            .forEach(function(el) { el.disabled = true; });
        clickedBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>処理中...';

        const filter = document.getElementById('trainer-filter');
        if (filter) filter.disabled = true;

        const row = document.querySelector('.audio-row[data-audio-id="' + audioId + '"]');
        const badge = row ? row.querySelector('.audio-status-badge') : null;
        if (badge) {
            badge.className = 'badge audio-status-badge ' + (STATUS_BADGE_CLASSES[status] || 'bg-secondary');
            badge.textContent = STATUS_LABELS[status] || '';
        }
    }

    // パネルで開いている記録（currentAudioId）に対して文字起こし・要約を実行する
    function runDetailAction(kind) {
        if (isRunningAction || !currentAudioId) return;
        const audioId = currentAudioId;
        const row = document.querySelector('.audio-row[data-audio-id="' + audioId + '"]');
        if (!row) return;

        const isTranscribe = kind === 'transcription';
        const hasExisting = isTranscribe ? row.dataset.hasTranscription === '1' : row.dataset.hasSummary === '1';
        const confirmMessage = isTranscribe
            ? (hasExisting ? '文字起こしを再実行しますか？既存の文字起こしは上書きされます。' : '文字起こしを実行しますか？')
            : (hasExisting ? '要約を再実行しますか？既存の要約は上書きされます。' : '要約を実行しますか？');
        if (!confirm(confirmMessage)) return;

        lockForAction(audioId, isTranscribe ? detailTranscribeBtn : detailSummarizeBtn,
            isTranscribe ? STATUS_TRANSCRIBING : STATUS_SUMMARIZING);

        fetch('/api/audio-records/' + audioId + (isTranscribe ? '/transcribe' : '/summarize'), {
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
            navigateWithHighlight(audioId);
        })
        .catch(error => {
            console.error(isTranscribe ? '文字起こしエラー:' : '要約エラー:', error);
            alert(error?.error?.message || (isTranscribe ? '文字起こしに失敗しました。' : '要約に失敗しました。'));
            navigateWithHighlight(audioId);
        });
    }

    if (detailTranscribeBtn) {
        detailTranscribeBtn.addEventListener('click', function() { runDetailAction('transcription'); });
    }
    if (detailSummarizeBtn) {
        detailSummarizeBtn.addEventListener('click', function() { runDetailAction('summary'); });
    }

    // --- highlight付きURLに遷移するヘルパー（読み込み直した後、その記録の編集パネルを開く） ---
    function navigateWithHighlight(audioId) {
        const url = new URL(window.location.href);
        url.searchParams.set('highlight', audioId);
        window.location.href = url.toString();
    }

    // --- ページ読み込み時の自動展開 ---
    const params = new URLSearchParams(window.location.search);
    const highlightId = params.get('highlight');
    if (highlightId) {
        const targetRow = document.querySelector('.audio-row[data-audio-id="' + highlightId + '"]');
        if (targetRow) {
            targetRow.click();
            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        // URLからhighlight・tabパラメータを除去（履歴を汚さない。tab は以前のタブ切り替え用の名残で、古い URL に付いていても消す）
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
