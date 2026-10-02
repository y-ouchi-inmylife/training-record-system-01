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
                            <th>内部ID</th>
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
                                data-summarize-locked="{{ $audio->isProcessing() && !$audio->isStalled() ? '1' : '0' }}"
                                data-stalled="{{ $audio->isStalled() ? '1' : '0' }}"
                                style="cursor: pointer;">
                                {{-- 日時 --}}
                                <td>{{ $audio->created_at->format('m/d H:i') }}</td>
                                {{-- 内部ID・会員：他の一覧画面（S-0304・S-0402）と同じく内部ID を独立した列にする。
                                     会員が取れない場合は §2-4 に従い空欄（トレーニング記録一覧と同じ書き方） --}}
                                <td>{{ $audio->client->internal_id ?? '' }}</td>
                                <td>{{ $audio->client->display_name ?? '' }}</td>
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
                    <button type="submit" form="audio-update-form" id="save-audio-btn" class="btn btn-success">更新</button>
                    {{-- 音声ファイルのみ削除のフォーム。ボタンは音声ファイルの段（#audio-update-form の内側）に置き、
                         form 属性でこのフォームを送信する（フォームの入れ子を避けるため、フォーム自体はここに残す） --}}
                    <form id="delete-audio-form" method="POST" style="display: none;"
                          onsubmit="if (!this.action) { alert('削除対象が不明です。'); return false; } return confirm('音声ファイルのみ削除します。文字起こし・要約は残ります。よろしいですか?')">
                        @csrf
                        @method('DELETE')
                    </form>
                    <form id="delete-record-form" method="POST" style="display: none;"
                          onsubmit="if (!this.action) { alert('削除対象が不明です。'); return false; } return confirm('この音声記録（音声ファイル + 文字起こし + 要約）を完全に削除します。よろしいですか?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">削除</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                {{-- 文字起こし・要約が成功した後に読み込み直したときだけ出す完了メッセージ（中身は JS で作る）。
                     処理後は該当の行が画面の中央に来るため、画面上部のフラッシュではなく編集欄の中に出す --}}
                <div id="detail-done-message"></div>
                {{-- 非同期の保存・実行の入力エラー以外の失敗（409・500・通信の失敗など）を alert alert-danger で出す
                     ための枠。中身は共通 JS `FormErrors.showFormMessage` が作る（設計書 §2-7「非同期の保存（fetch）」） --}}
                <div id="detail-form-error"></div>
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
                         会員編集のメールアドレスと同じく form-control-plaintext にする。値はパネルを開くたびに JS で設定する。
                         値の右に、音声ファイルに関わる操作（文字起こし・音声ファイルのみ削除）を並べる --}}
                    <div class="row g-3 mb-2">
                        <div class="col-12">
                            <div class="row g-2 align-items-center">
                                <label class="col-md-auto col-form-label text-md-end form-label-fixed">音声ファイル</label>
                                <div class="col-12 col-md">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        {{-- w-auto：form-control-plaintext の幅 100% を内容に合わせ、ボタンを同じ行に置く --}}
                                        <div id="detail-has-audio" class="form-control-plaintext w-auto"></div>
                                        <button type="button" id="detail-transcribe-btn" class="btn btn-primary" style="display: none;">文字起こし</button>
                                        <button type="submit" form="delete-audio-form" id="detail-delete-audio-btn" class="btn btn-outline-danger" style="display: none;">音声ファイルのみ削除</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 文字起こし・要約：見比べながら編集できるよう左右に並べる（lg 未満では上下に積む）。
                         md で 2 等分すると 1 つあたりが狭く編集しにくいため col-lg-6 にする（設計書 S-0505 設計方針参照） --}}
                    {{-- ラベルの行：左は「文字起こし [要約]」（要約はこの文字起こしに対する操作なのでラベルの横に置く）。
                         要約ボタンの有無にかかわらず左右のテキストエリアの上端を揃えるため、左右のラベルの行に
                         通常サイズのボタン・入力欄と同じ最小の高さ（Bootstrap の $input-height と同じ式）を持たせる --}}
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="d-flex align-items-center gap-2 mb-2" style="min-height: calc(1.5em + .75rem + calc(var(--bs-border-width) * 2));">
                                <label for="transcription-text" class="form-label mb-0">文字起こし</label>
                                {{-- 要約：パネルで開いている記録に対して実行する。表示・押せる条件は行の data-* から切り替える --}}
                                <button type="button" id="detail-summarize-btn" class="btn btn-primary" style="display: none;">要約</button>
                            </div>
                            <textarea class="form-control" id="transcription-text" name="transcription_text"
                                      rows="10" placeholder="文字起こしテキストがここに表示されます"></textarea>
                        </div>
                        <div class="col-lg-6">
                            <div class="d-flex align-items-center gap-2 mb-2" style="min-height: calc(1.5em + .75rem + calc(var(--bs-border-width) * 2));">
                                <label for="summary-text" class="form-label mb-0">要約</label>
                            </div>
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
{{-- 非同期の保存の入力エラー・入力エラー以外の失敗を欄の下・パネルの上部に出す共通 JS
     （設計書 §2-7「非同期の保存（fetch）」。window.FormErrors に関数が載る） --}}
@vite(['resources/js/form-errors.js'])
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
    const detailDoneMessage = document.getElementById('detail-done-message');
    // 非同期の保存・実行の入力エラー以外の失敗の文言を出す枠（§2-7「非同期の保存（fetch）」。共通 JS が中身を作る）
    const detailFormError = document.getElementById('detail-form-error');
    // 処理後の完了メッセージ（URL の done の値 → 文言）。文言はコントローラーの応答を使わず画面側で持つ
    // 完了メッセージを 5 秒後に自動で閉じるタイマー（新しく出すとき・消すときに取り消す）
    let doneMessageTimer = null;
    const DONE_MESSAGE_AUTO_DISMISS_MS = 5000; // レイアウトの「更新」の保存メッセージ（data-auto-dismiss）と同じ秒数
    const DONE_MESSAGES = {
        // 「更新」の保存のとき（段階 5-3a 追補。fetch 化で消えたサーバーのフラッシュと同じ文言）
        saved: '音声記録を保存しました。',
        transcription: '文字起こしが完了し、保存しました。',
        summary: '要約が完了し、保存しました。',
    };
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

    // 保存ボタン（フォーム submit）は非同期の保存に差し替える（§2-7「非同期の保存（fetch）」。段階 5-3a）。
    // ネイティブの画面遷移をやめ、fetch で PUT し、成功時は navigateWithHighlight でページ読み込み直し、
    // 失敗時は欄の下（422）または編集パネルの上部（409・500・通信の失敗）に表示する。
    if (audioUpdateForm) {
        audioUpdateForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!currentAudioId) return;
            saveAudioRecord();
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

            // ここで選択が変わることが確定する（閉じる／別の行を開く）ので、完了メッセージを消す。
            // あわせて、前のエラーの表示（欄の下・パネルの上部）も消す（§2-7「新しく保存するとき・別の行を選んだ
            // とき・パネルを閉じたときは、前のエラーの表示を消す」。段階 5-3a）
            clearDoneMessage();
            clearPanelErrors();

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
                // 音声ファイルの有無（「音声ファイルのみ削除」ボタンの判定と同じ has_audio_file を使う）。
                // §2-5 の配色に沿い、あり＝緑（bg-success）、なし＝グレー（bg-secondary）の標準バッジで表示する
                const hasAudioBadge = document.createElement('span');
                hasAudioBadge.className = data.has_audio_file ? 'badge bg-success' : 'badge bg-secondary';
                hasAudioBadge.textContent = data.has_audio_file ? 'あり' : 'なし';
                detailHasAudio.textContent = '';
                detailHasAudio.appendChild(hasAudioBadge);

                // フォームのアクションURLを設定
                audioUpdateForm.action = '/audio-records/' + audioId;

                // 音声ファイル削除ボタンの表示制御（フォームは送信先の保持だけに使い、表示はボタン側で切り替える）
                const deleteAudioForm = document.getElementById('delete-audio-form');
                const deleteAudioBtn = document.getElementById('detail-delete-audio-btn');
                if (data.has_audio_file && data.can_delete && data.delete_audio_url) {
                    deleteAudioForm.action = data.delete_audio_url;
                    deleteAudioBtn.style.display = 'inline-block';
                } else {
                    deleteAudioForm.action = '';
                    deleteAudioBtn.style.display = 'none';
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
                document.getElementById('detail-delete-audio-btn').style.display = 'none';
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
        // 要約は常に表示する（手入力した文字起こしでも要約できるように。空かどうかは押したときに判定する）
        detailSummarizeBtn.style.display = 'inline-block';
        // 文字起こし中・要約中（止まっていないもの）は要約を押せなくする
        detailSummarizeBtn.disabled = d.summarizeLocked === '1';
        detailStalledNotice.style.display = d.stalled === '1' ? 'block' : 'none';
    }

    // ロック時に変えた前の状態を保存して、失敗時に戻せるようにする（§2-7「失敗したら処理中のロックを
    // 解除して入力を直せる状態に戻す」）。null のときはロックしていない状態。
    let lockedState = null;

    // 実行中は、パネルの他のボタン・行の選択・登録者の絞り込みを操作できなくし、行のバッジを処理中にする。
    // status を null にすると、バッジは変えない（保存ボタンから呼ぶときに使う。進行中の表示はボタンの
    // スピナーで示す）。
    function lockForAction(audioId, clickedBtn, status) {
        isRunningAction = true;
        clearDoneMessage();
        clearPanelErrors(); // 新しい実行を始めるので、前のエラーの表示を消す（§2-7）
        // 音声ファイルのみ削除のボタンはフォームの外（音声ファイルの段）にあるため id で指定する
        const buttons = [detailTranscribeBtn, detailSummarizeBtn,
            document.getElementById('save-audio-btn'),
            document.getElementById('detail-delete-audio-btn')]
            .concat(Array.from(document.querySelectorAll('#delete-record-form button')));
        const originalBtnHtml = clickedBtn.innerHTML;
        buttons.forEach(function(el) { el.disabled = true; });
        clickedBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>処理中...';

        const filter = document.getElementById('trainer-filter');
        if (filter) filter.disabled = true;

        let originalBadgeClass = null;
        let originalBadgeText = null;
        if (status !== null) {
            const row = document.querySelector('.audio-row[data-audio-id="' + audioId + '"]');
            const badge = row ? row.querySelector('.audio-status-badge') : null;
            if (badge) {
                originalBadgeClass = badge.className;
                originalBadgeText = badge.textContent;
                badge.className = 'badge audio-status-badge ' + (STATUS_BADGE_CLASSES[status] || 'bg-secondary');
                badge.textContent = STATUS_LABELS[status] || '';
            }
        }

        lockedState = {
            audioId: audioId,
            clickedBtn: clickedBtn,
            originalBtnHtml: originalBtnHtml,
            buttons: buttons,
            originalBadgeClass: originalBadgeClass,
            originalBadgeText: originalBadgeText,
        };
    }

    // 失敗時に lockForAction で変えたものをすべて元に戻す（入力した値は消さない）
    function unlockAfterAction() {
        if (!lockedState) return;
        lockedState.clickedBtn.innerHTML = lockedState.originalBtnHtml;
        lockedState.buttons.forEach(function(el) { el.disabled = false; });
        const filter = document.getElementById('trainer-filter');
        if (filter) filter.disabled = false;
        if (lockedState.originalBadgeClass !== null) {
            const row = document.querySelector('.audio-row[data-audio-id="' + lockedState.audioId + '"]');
            const badge = row ? row.querySelector('.audio-status-badge') : null;
            if (badge) {
                badge.className = lockedState.originalBadgeClass;
                badge.textContent = lockedState.originalBadgeText;
            }
        }
        isRunningAction = false;
        lockedState = null;
    }

    // 編集パネルのエラーの表示（欄の下の is-invalid・入力エラー以外の失敗の alert）を消す
    function clearPanelErrors() {
        if (window.FormErrors) {
            window.FormErrors.clearFieldErrors(audioUpdateForm);
            window.FormErrors.clearFormMessage(detailFormError);
        }
    }

    // 保存ボタン・フォーム submit から呼ぶ：PUT /audio-records/{id} を fetch で呼ぶ
    function saveAudioRecord() {
        if (!currentAudioId) return;
        const saveBtn = document.getElementById('save-audio-btn');
        // 保存は実行中のロックに加える（文字起こし・要約と同じく他のボタン・絞り込みを無効化）。
        // 進行中はボタンのスピナーで示し、行のバッジは触らない（status=null）
        lockForAction(currentAudioId, saveBtn, null);

        fetch(audioUpdateForm.action, {
            method: 'POST', // _method=PUT を FormData に含めるため POST で送る（saveBeforeSummarize と同じ）
            body: new FormData(audioUpdateForm),
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
        .then(function(response) {
            if (response.status === 200) {
                // 成功：画面を読み込み直し、該当の行の編集パネルを開き直す（文字起こし・要約の成功時と同じ
                // navigateWithHighlight を使う）。done=saved を付けて、読み込み直した後に完了メッセージ
                // 「音声記録を保存しました。」を文字起こし・要約と同じ仕組みで表示する（段階 5-3a 追補。
                // fetch 化で消えたサーバーのフラッシュを画面側で持ち直す）。
                hasUnsavedChanges = false;
                navigateWithHighlight(currentAudioId, 'saved');
                return;
            }
            if (response.status === 422) {
                return response.json().then(function(data) {
                    unlockAfterAction();
                    // 422 のエラーは欄の下に出す。欄が見つからないキーは入力エラー以外の失敗として
                    // パネルの上部に回す（取りこぼし防止）
                    const orphans = window.FormErrors.showFieldErrors(audioUpdateForm, data && data.errors ? data.errors : {});
                    if (orphans.length > 0) {
                        window.FormErrors.showFormMessage(detailFormError, orphans.join('\n'));
                    }
                });
            }
            if (response.status === 409) {
                return response.json().then(function(data) {
                    unlockAfterAction();
                    const message = (data && data.error && data.error.message) || '保存に失敗しました。';
                    window.FormErrors.showFormMessage(detailFormError, message);
                });
            }
            // 500・その他：JSON が読めるときは中身を使い、読めないときは固定文言
            return response.json().then(function(data) {
                unlockAfterAction();
                const message = (data && data.error && data.error.message) || '保存に失敗しました。';
                window.FormErrors.showFormMessage(detailFormError, message);
            }).catch(function() {
                unlockAfterAction();
                window.FormErrors.showFormMessage(detailFormError, '保存に失敗しました。');
            });
        })
        .catch(function(error) {
            console.error('保存エラー:', error);
            unlockAfterAction();
            window.FormErrors.showFormMessage(detailFormError, '保存に失敗しました。');
        });
    }

    // パネルで開いている記録（currentAudioId）に対して文字起こし・要約を実行する
    function runDetailAction(kind) {
        if (isRunningAction || !currentAudioId) return;
        // 対象の id は押した時点の currentAudioId を取っておき、以後はそれを使う
        const audioId = currentAudioId;
        const row = document.querySelector('.audio-row[data-audio-id="' + audioId + '"]');
        if (!row) return;

        const isTranscribe = kind === 'transcription';

        // 要約：文字起こし欄が空なら実行しない（確認ダイアログより先）。サーバーは前後の空白を除いて
        // 空を null にするので trim して揃える。要約の API の 409 の文言は空であることを伝えないため画面側で判定する。
        // 何を直せばよいかが文字起こしの欄にあるので、文字起こしの欄の下に出す（§2-7。段階 5-3a）
        if (!isTranscribe && transcriptionText.value.trim() === '') {
            if (window.FormErrors) {
                window.FormErrors.clearFormMessage(detailFormError);
                window.FormErrors.showFieldErrors(audioUpdateForm, {
                    transcription_text: ['文字起こしが空のため、要約できません。'],
                });
            }
            return;
        }

        // 初回・再実行で分けず、常に同じ 3 行の文言にする（ユーザーの判断。設計書 S-0505 参照）。
        // 最後の行で、手で保存する「更新」との違い（結果は自動で保存される）を押す前に伝える
        const confirmMessage = isTranscribe
            ? '文字起こしを実行しますか？\n既存の文字起こしは上書きされます。\n結果は自動で保存されます。'
            : '要約を実行しますか？\n既存の要約は上書きされます。\n結果は自動で保存されます。';
        if (!confirm(confirmMessage)) return;

        // 要約は DB に保存済みの文字起こしを使うため、未保存の変更があれば先に保存してから要約する
        // （未保存の変更がなければ保存は飛ばす）。保存に失敗したら要約せず、編集内容を残して終わる
        if (!isTranscribe && hasUnsavedChanges) {
            saveBeforeSummarize().then(function (saved) {
                if (saved) startDetailAction(audioId, kind);
            });
            return;
        }

        startDetailAction(audioId, kind);
    }

    // 要約の前の保存（PUT /audio-records/{id} を JSON で呼ぶ）。保存できたら true を返す。
    // 保存中は別の行を選べなくし、「要約」ボタンを「保存中...」にする。失敗したら元に戻し、ページは読み込み直さない。
    // 入力エラーは欄の下、入力エラー以外の失敗は編集パネルの上部に出す（§2-7「非同期の保存（fetch）」。段階 5-3a）
    function saveBeforeSummarize() {
        isRunningAction = true;
        clearPanelErrors(); // 新しい保存を始めるので前のエラーの表示を消す
        const originalHtml = detailSummarizeBtn.innerHTML;
        const originalDisabled = detailSummarizeBtn.disabled;
        detailSummarizeBtn.disabled = true;
        detailSummarizeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中...';

        const restore = function () {
            detailSummarizeBtn.innerHTML = originalHtml;
            detailSummarizeBtn.disabled = originalDisabled;
            isRunningAction = false;
        };

        // FormData には _token・_method=PUT・表示名・文字起こし・要約が入る（フォームの送信と同じ内容）
        return fetch(audioUpdateForm.action, {
            method: 'POST',
            body: new FormData(audioUpdateForm),
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
        .then(function (response) {
            if (response.status === 200) {
                hasUnsavedChanges = false;
                return true;
            }
            if (response.status === 422) {
                return response.json().then(function (data) {
                    restore();
                    const orphans = window.FormErrors.showFieldErrors(audioUpdateForm, data && data.errors ? data.errors : {});
                    if (orphans.length > 0) {
                        window.FormErrors.showFormMessage(detailFormError, orphans.join('\n'));
                    }
                    return false;
                });
            }
            if (response.status === 409) {
                return response.json().then(function (data) {
                    restore();
                    const message = (data && data.error && data.error.message) || '保存に失敗しました。';
                    window.FormErrors.showFormMessage(detailFormError, message);
                    return false;
                });
            }
            restore();
            window.FormErrors.showFormMessage(detailFormError, '保存に失敗しました。');
            return false;
        })
        .catch(function (error) {
            console.error('要約前の保存エラー:', error);
            restore();
            window.FormErrors.showFormMessage(detailFormError, '保存に失敗しました。');
            return false;
        });
    }

    // 文字起こし・要約の API を呼ぶ（処理中のロック → 呼び出し → 成功・失敗後に読み込み直し）
    function startDetailAction(audioId, kind) {
        const isTranscribe = kind === 'transcription';
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
            // 成功したときだけ、読み込み直した後に完了メッセージを出すため処理の種類を渡す
            navigateWithHighlight(audioId, kind);
        })
        .catch(error => {
            console.error(isTranscribe ? '文字起こしエラー:' : '要約エラー:', error);
            // 失敗時はページを読み込み直さず、処理中のロックを解除して入力を直せる状態に戻し、
            // 入力エラー以外の失敗として編集パネルの上部に文言を出す（§2-7。段階 5-3a）
            unlockAfterAction();
            const fallback = isTranscribe ? '文字起こしに失敗しました。' : '要約に失敗しました。';
            const message = (error && error.error && error.error.message) || fallback;
            window.FormErrors.showFormMessage(detailFormError, message);
        });
    }

    if (detailTranscribeBtn) {
        detailTranscribeBtn.addEventListener('click', function() { runDetailAction('transcription'); });
    }
    if (detailSummarizeBtn) {
        detailSummarizeBtn.addEventListener('click', function() { runDetailAction('summary'); });
    }

    // --- highlight付きURLに遷移するヘルパー（読み込み直した後、その記録の編集パネルを開く） ---
    // done（'saved' | 'transcription' | 'summary'）は成功したときだけ渡し、読み込み直した後の完了メッセージに使う
    function navigateWithHighlight(audioId, done) {
        const url = new URL(window.location.href);
        url.searchParams.set('highlight', audioId);
        if (done) {
            url.searchParams.set('done', done);
        } else {
            url.searchParams.delete('done');
        }
        window.location.href = url.toString();
    }

    // --- 完了メッセージ ---
    // 見た目は「更新」の保存メッセージ（レイアウトのフラッシュ）に揃える。閉じるボタンで要素ごと消えても
    // 次に出すときに作り直せるよう、外側の #detail-done-message の中に毎回作る
    function showDoneMessage(text) {
        const alertEl = document.createElement('div');
        alertEl.className = 'alert alert-success alert-dismissible fade show';
        alertEl.setAttribute('role', 'alert');
        alertEl.textContent = text;
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close';
        closeBtn.setAttribute('data-bs-dismiss', 'alert');
        alertEl.appendChild(closeBtn);
        cancelDoneMessageTimer(); // 前のタイマーが新しいメッセージを閉じないように取り消す
        detailDoneMessage.textContent = '';
        detailDoneMessage.appendChild(alertEl);

        // 「更新」の保存メッセージと同じく、5 秒後に bootstrap.Alert の close() で閉じる（フェードして消える）。
        // その時点で要素がすでに消えている（× で閉じた・別の行を選んだ）ときは何もしない
        doneMessageTimer = setTimeout(function () {
            doneMessageTimer = null;
            if (alertEl.isConnected) {
                bootstrap.Alert.getOrCreateInstance(alertEl).close();
            }
        }, DONE_MESSAGE_AUTO_DISMISS_MS);
    }

    function cancelDoneMessageTimer() {
        if (doneMessageTimer !== null) {
            clearTimeout(doneMessageTimer);
            doneMessageTimer = null;
        }
    }

    function clearDoneMessage() {
        cancelDoneMessageTimer();
        if (detailDoneMessage) detailDoneMessage.textContent = '';
    }

    // --- ページ読み込み時の自動展開 ---
    const params = new URLSearchParams(window.location.search);
    const highlightId = params.get('highlight');
    if (highlightId) {
        const targetRow = document.querySelector('.audio-row[data-audio-id="' + highlightId + '"]');
        if (targetRow) {
            targetRow.click();
            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // 自動のクリック（その中で完了メッセージを消す）の後に表示する。done が想定外の値なら何も出さない
            // （constructor などの組み込みのキーを拾わないよう、自前で定義したキーだけを見る）
            const done = params.get('done');
            if (Object.prototype.hasOwnProperty.call(DONE_MESSAGES, done)) showDoneMessage(DONE_MESSAGES[done]);
        }
        // URLからhighlight・tab・doneパラメータを除去（履歴を汚さない。再読み込みやブックマークで完了メッセージを再表示しない。
        // tab は以前のタブ切り替え用の名残で、古い URL に付いていても消す）
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('highlight');
        cleanUrl.searchParams.delete('tab');
        cleanUrl.searchParams.delete('done');
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
