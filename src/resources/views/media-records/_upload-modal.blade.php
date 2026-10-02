{{-- メディア登録モーダル（S-1302-M02）。
     ファイルを presigned URL でストレージへ直アップロードし、メディアレコードを作成する
     再利用可能な部品。完了時に呼び出し元へ登録メディア一覧を渡して、呼び出し元が
     後続挙動（一覧更新／メディア紐づけ等）を決める。

     利用方法:
       @include('media-records._upload-modal')
       // ... JS から:
       window.mediaUploadModal.open({
           onComplete: (registeredMedia) => { ... },   // 全成功時。引数は登録された各メディア
           onClose:    () => { ... },                  // モーダル閉時（成功/失敗問わず・任意）
       });
--}}

@once
<div class="modal fade" id="mediaUploadModal" tabindex="-1" aria-labelledby="mediaUploadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mediaUploadModalLabel">メディア登録</h5>
                <button type="button" class="btn-close" id="mediaUploadHeaderCloseBtn" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                {{-- アップロード中表示（テキスト＋進捗バー） --}}
                <div id="mediaUploadInProgress" class="d-none mb-3">
                    <div id="mediaUploadProgressMessage" class="alert alert-warning mb-2">
                        アップロード中… 画面を閉じないでください。
                    </div>
                    <div class="progress" style="height: 24px;">
                        <div id="mediaUploadProgressBar"
                             class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar"
                             style="width: 0%"
                             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>
                </div>

                {{-- ファイル（複数選択可） --}}
                <div class="mb-3">
                    <label for="mediaUploadFileInput" class="form-label">ファイル <span class="text-danger">*</span></label>
                    <input type="file" id="mediaUploadFileInput" multiple
                           class="form-control"
                           accept=".jpg,.jpeg,.png,.heic,.heif,.mp4,.mov">
                    {{-- 送信前のブラウザ側確認（§2-7 の例外「大きなファイルを送る前の確認」）と
                         サーバー 422（ファイルが未選択の場合など、個別ファイル処理の前の違反）の文言は、
                         ここに invalid-feedback d-block で出す（JS が `showFileError` で class を付与する）。
                         静的な HTML では class を持たない：
                           - `invalid-feedback` を静的に残すと、入力エラーなしで画面を開いただけでも
                             「`invalid-feedback` が描画される」状態になり、他画面の描画テストで誤検知する
                           - エラーが出ているあいだだけ JS が class を付け、消えたら JS が class を外す --}}
                    <div id="mediaUploadFileError"></div>
                    <div class="form-text">
                        対応形式: 【写真】 jpeg, png, heic（最大20MB）、【動画】 mp4, mov（最大1GB）
                    </div>

                    {{-- 選択ファイル一覧 --}}
                    <div id="mediaUploadFileList" class="mt-3 d-none">
                        <div class="small text-muted mb-1">選択中: <span id="mediaUploadFileListCount">0</span> ファイル</div>
                        <ul id="mediaUploadFileListItems" class="list-group list-group-flush border rounded small"></ul>
                    </div>
                </div>

                {{-- 結果サマリ（全件処理後、失敗があれば表示） --}}
                <div id="mediaUploadResultSummary" class="d-none mt-3">
                    <div class="alert alert-warning mb-2">
                        <strong><span id="mediaUploadSummaryTotal">0</span> 個中 <span id="mediaUploadSummarySuccess">0</span> 個成功、<span id="mediaUploadSummaryFailure">0</span> 個失敗。</strong>
                        成功したファイルは登録済みです。失敗したファイルは登録されていません。
                        <ul id="mediaUploadSummaryFailureList" class="mb-0 mt-2"></ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="mediaUploadCloseBtn" data-bs-dismiss="modal">閉じる</button>
                <button type="button" class="btn btn-success" id="mediaUploadSubmitBtn">登録</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// 再利用可能な部品として実装。Blade の once ディレクティブと内部 idempotent ガードで二重 include に対応。
// （※ JS コメント内に Blade ディレクティブ綴り「アットマーク+once」を書くと
//   Blade パーサが拾って ParseError になるため、表現を分けている）
(function () {
    // ガード条件を「open 関数の存在」で判定。空オブジェクト {} が先にあっても再代入される。
    if (window.mediaUploadModal && typeof window.mediaUploadModal.open === 'function') return;

    // サーバ側定数を SSoT として渡す
    const PHOTO_MIMES = @json(\App\Models\MediaRecord::PHOTO_MIME_TYPES);
    const VIDEO_MIMES = @json(\App\Models\MediaRecord::VIDEO_MIME_TYPES);
    const PHOTO_EXTS = @json(\App\Models\MediaRecord::PHOTO_EXTENSIONS);
    const VIDEO_EXTS = @json(\App\Models\MediaRecord::VIDEO_EXTENSIONS);
    const MAX_PHOTO_SIZE = @json(\App\Models\MediaRecord::MAX_PHOTO_SIZE);
    const MAX_VIDEO_SIZE = @json(\App\Models\MediaRecord::MAX_VIDEO_SIZE);

    let modal = null;
    let modalEl = null;
    let csrfToken = null;
    let currentOptions = {};
    let isUploading = false;
    // 部分失敗時：閉じた時に成功分を反映するため reload が要るかを保持
    let needsReloadOnClose = false;

    // 要素参照（DOMContentLoaded 後に取得）
    let submitBtn, headerCloseBtn, closeBtn, fileInput, fileErrorEl;
    let inProgressEl, progressMessage, progressBar;
    let fileListEl, fileListCountEl, fileListItemsEl;
    let resultSummaryEl, summaryTotalEl, summarySuccessEl, summaryFailureEl, summaryFailureListEl;

    // 選択中のファイル配列と、各ファイルに対応する行 DOM・状態の Map
    let selectedFiles = [];
    const fileRowMap = new Map();

    // ===== 公開 API を IIFE 冒頭で即座に公開 =====
    // ensureModal / resetState は function 宣言なので hoisting され、
    // ここで参照しても valid。これにより partial スクリプト読み込み完了時点で
    // window.mediaUploadModal.open が確実に関数として存在する。
    function openModal(options) {
        currentOptions = options || {};
        ensureModal();
        resetState();
        if (modal) modal.show();
    }
    window.mediaUploadModal = { open: openModal };

    // ファイルを写真／動画に分類
    function classifyFile(file) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const mime = file.type;
        if (PHOTO_MIMES.includes(mime) || PHOTO_EXTS.includes(ext)) return 'photo';
        if (VIDEO_MIMES.includes(mime) || VIDEO_EXTS.includes(ext)) return 'video';
        return null;
    }

    // 送信前のチェック（§2-7 の例外「大きなファイルを送る前の確認」。段階 5-3b で文言を整え、
    // サーバーの文言と食い違わない形に揃えた。音声ファイル〔段階 5-2〕と同じ流儀で、
    // ファイル名と大きさを添えて、後半はサーバーの文言と同じ）。
    function validateFile(file) {
        const type = classifyFile(file);
        if (type === null) {
            return {
                ok: false,
                message: '選択したファイル「' + file.name + '」は対応形式ではありません。写真(jpeg/png/heic)・動画(mp4/mov)のみ登録できます。',
            };
        }
        // 大きさは 1MB = 1024 × 1024 バイトで計算し、小数点以下 1 桁で切り上げる
        // （四捨五入・切り捨てだと、上限をわずかに超えたファイルが「20.0MB」と表示されて
        //  「20MB 以下にしてください」の文と矛盾するため）。
        if (type === 'photo' && file.size > MAX_PHOTO_SIZE) {
            const sizeMb = (Math.ceil(file.size / 1024 / 1024 * 10) / 10).toFixed(1);
            return {
                ok: false,
                message: '選択したファイル（' + file.name + '、' + sizeMb + 'MB）は 20MB 以下にしてください。',
            };
        }
        if (type === 'video' && file.size > MAX_VIDEO_SIZE) {
            const sizeMb = (Math.ceil(file.size / 1024 / 1024 * 10) / 10).toFixed(1);
            return {
                ok: false,
                message: '選択したファイル（' + file.name + '、' + sizeMb + 'MB）は 1GB 以下にしてください。',
            };
        }
        return { ok: true };
    }

    function validateAllFiles(files) {
        for (let i = 0; i < files.length; i++) {
            const r = validateFile(files[i]);
            if (!r.ok) return r;
        }
        return { ok: true };
    }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1024 * 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
        return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB';
    }

    function buildFileList(files) {
        fileListItemsEl.innerHTML = '';
        fileRowMap.clear();
        files.forEach(function (file) {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center gap-2';

            const left = document.createElement('div');
            left.className = 'text-truncate';
            left.style.flex = '1 1 auto';
            left.style.minWidth = '0';
            const nameSpan = document.createElement('span');
            nameSpan.className = 'fw-medium';
            nameSpan.textContent = file.name;
            const metaSpan = document.createElement('span');
            metaSpan.className = 'text-muted ms-2';
            const t = classifyFile(file);
            const typeLabel = t === 'photo' ? '写真' : (t === 'video' ? '動画' : '不明');
            metaSpan.textContent = '（' + typeLabel + ', ' + formatSize(file.size) + '）';
            left.appendChild(nameSpan);
            left.appendChild(metaSpan);

            const statusEl = document.createElement('div');
            statusEl.className = 'flex-shrink-0 d-flex align-items-center gap-2';
            statusEl.innerHTML = '<span class="badge bg-secondary">待機</span>';

            li.appendChild(left);
            li.appendChild(statusEl);
            fileListItemsEl.appendChild(li);

            fileRowMap.set(file, { statusEl: statusEl, status: 'pending', error: null });
        });
        fileListCountEl.textContent = files.length;
        fileListEl.classList.toggle('d-none', files.length === 0);
    }

    function setRowStatus(file, status, errorMessage) {
        const row = fileRowMap.get(file);
        if (!row) return;
        row.status = status;
        row.error = errorMessage || null;
        let html = '';
        switch (status) {
            case 'pending':
                html = '<span class="badge bg-secondary">待機</span>';
                break;
            case 'processing':
                html = '<span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>'
                     + '<span class="small">処理中</span>';
                break;
            case 'done':
                html = '<span class="badge bg-success">完了</span>';
                break;
            case 'error':
                html = '<span class="badge bg-danger">失敗</span>';
                break;
        }
        row.statusEl.innerHTML = html;
        if (status === 'error' && errorMessage) {
            const li = row.statusEl.closest('li');
            const old = li.querySelector('.row-error-message');
            if (old) old.remove();
            const msg = document.createElement('div');
            msg.className = 'row-error-message text-danger small w-100';
            msg.textContent = errorMessage;
            li.classList.add('flex-wrap');
            li.appendChild(msg);
        }
    }

    // 状態遷移（idle / uploading）。busy 中は閉じ防止
    function setState(state) {
        const busy = (state === 'uploading');
        isUploading = busy;
        submitBtn.disabled = busy;
        fileInput.disabled = busy;
        // 閉じ系ボタンを無効化（× / 閉じる）
        headerCloseBtn.disabled = busy;
        closeBtn.disabled = busy;
        inProgressEl.classList.toggle('d-none', !busy);
        if (!busy) setProgress(0);
    }

    function setProgress(pct) {
        progressBar.style.width = pct + '%';
        progressBar.textContent = pct + '%';
        progressBar.setAttribute('aria-valuenow', String(pct));
    }

    let currentProgressContext = { idx: 0, total: 0, filename: '' };

    function setPhase(phase) {
        const ctx = currentProgressContext;
        const prefix = (ctx.idx + 1) + ' / ' + ctx.total + ' 個目: ' + ctx.filename;
        let suffix;
        if (phase === 'converting') {
            suffix = ' を変換中…';
        } else if (phase === 'thumbnail') {
            suffix = ' のサムネイル生成中…';
        } else {
            suffix = ' をアップロード中…';
        }
        progressMessage.textContent = prefix + suffix + ' 画面を閉じないでください。';

        if (phase === 'uploading') {
            setProgress(0);
        } else {
            progressBar.style.width = '100%';
            progressBar.textContent = '';
            progressBar.setAttribute('aria-valuenow', '100');
        }
    }

    // サーバーのエラーレスポンスから利用者向けの文言を取り出す。
    // 段階 5-3b で、サーバー側（MediaRecordController::uploadUrl / store）が独自の
    // `{"error": {...}}` 形式をやめて Laravel 標準の 422（`{message, errors: {キー: [文言…]}}`）に
    // 揃えたため、`body.errors` と `body.message` だけを読む。
    async function readErrorMessage(res, fallback) {
        try {
            const body = await res.json();
            if (body && body.errors) {
                return Object.values(body.errors).flat().join('\n');
            }
            if (body && body.message) return body.message;
        } catch (e) { /* ignore */ }
        return fallback;
    }

    function putWithProgress(url, file) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', url);
            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) setProgress(Math.round((e.loaded / e.total) * 100));
            });
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    setProgress(100);
                    resolve();
                } else {
                    reject(new Error('ストレージへのアップロードに失敗しました（HTTP ' + xhr.status + '）'));
                }
            };
            xhr.onerror = function () { reject(new Error('ストレージへの通信に失敗しました。')); };
            xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
            xhr.send(file);
        });
    }

    // 1 ファイル分の処理（upload-url → 直PUT → store → convert? → generate-thumbnail?）。
    // 成功時は store で取得した media object を返す（呼び出し側のループで蓄積）。
    async function processOneFile(file) {
        const uploadUrlRes = await fetch('/api/media-records/upload-url', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ original_filename: file.name, mime_type: file.type, file_size: file.size }),
        });
        if (!uploadUrlRes.ok) {
            throw new Error(await readErrorMessage(uploadUrlRes, '署名URLの発行に失敗しました。'));
        }
        const uploadUrlBody = await uploadUrlRes.json();
        const uploadUrl = uploadUrlBody.data.upload_url;
        const storageKey = uploadUrlBody.data.storage_key;

        await putWithProgress(uploadUrl, file);

        const storeRes = await fetch('/api/media-records', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({
                storage_key: storageKey,
                original_filename: file.name,
                mime_type: file.type,
                file_size: file.size,
            }),
        });
        if (!storeRes.ok) {
            throw new Error(await readErrorMessage(storeRes, 'メディアレコードの作成に失敗しました。'));
        }
        const storeBody = await storeRes.json();
        const media = storeBody.data || {};

        if (media.conversion_status === 'pending' && (media.type === 'photo' || media.type === 'video')) {
            setPhase('converting');
            const convertRes = await fetch('/api/media-records/' + encodeURIComponent(media.id) + '/convert', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            if (!convertRes.ok) {
                throw new Error(await readErrorMessage(convertRes, '表示用変換に失敗しました。'));
            }
        }

        if (media.thumbnail_status === 'pending' && (media.type === 'photo' || media.type === 'video')) {
            setPhase('thumbnail');
            const thumbRes = await fetch('/api/media-records/' + encodeURIComponent(media.id) + '/generate-thumbnail', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            if (!thumbRes.ok) {
                throw new Error(await readErrorMessage(thumbRes, 'サムネイル生成に失敗しました。'));
            }
            // generate-thumbnail のレスポンスを media にマージ。
            // thumbnail_url / display_title / 最新の thumbnail_status などが入る。
            // onComplete 受信側（段2 の記録編集）が mediaSelection.add で必要なフィールドを得るため。
            const thumbBody = await thumbRes.json();
            Object.assign(media, thumbBody.data || {});
        }

        return media;
    }

    function setOverallProgress(currentIdx, total, filename) {
        currentProgressContext = { idx: currentIdx, total: total, filename: filename };
        setPhase('uploading');
    }

    function showResultSummary(results) {
        const successCount = results.filter(function (r) { return r.ok; }).length;
        const failureCount = results.length - successCount;
        const failures = results.filter(function (r) { return !r.ok; });

        summaryTotalEl.textContent = String(results.length);
        summarySuccessEl.textContent = String(successCount);
        summaryFailureEl.textContent = String(failureCount);
        summaryFailureListEl.innerHTML = '';
        failures.forEach(function (r) {
            const li = document.createElement('li');
            li.textContent = r.file.name + ' — ' + r.error;
            summaryFailureListEl.appendChild(li);
        });
        resultSummaryEl.classList.remove('d-none');
        inProgressEl.classList.add('d-none');
    }

    // モーダル再オープン時のリセット（前回の状態を残さない）
    function resetState() {
        selectedFiles = [];
        fileRowMap.clear();
        if (fileInput) fileInput.value = '';
        // ファイルの選択の欄の下のエラーを消す（showFileError で付けた class・text を外す）
        if (fileErrorEl) { fileErrorEl.className = ''; fileErrorEl.textContent = ''; }
        if (fileInput) fileInput.classList.remove('is-invalid');
        if (fileListEl) fileListEl.classList.add('d-none');
        if (fileListItemsEl) fileListItemsEl.innerHTML = '';
        if (resultSummaryEl) resultSummaryEl.classList.add('d-none');
        if (inProgressEl) inProgressEl.classList.add('d-none');
        if (summaryFailureListEl) summaryFailureListEl.innerHTML = '';
        setProgress(0);
        needsReloadOnClose = false;
        isUploading = false;
        if (submitBtn) submitBtn.disabled = false;
        if (headerCloseBtn) headerCloseBtn.disabled = false;
        if (closeBtn) closeBtn.disabled = false;
    }

    // modal インスタンスと DOM 要素取得・イベント結線。
    // 初回 open または DOMContentLoaded のいずれか先に来た方で実行され、2回目以降は早期 return。
    // open を IIFE 直下で公開しつつ、modal 初期化を遅延させることで、スクリプト評価直後の
    // open 呼び出しでも確実に動作する（DOMContentLoaded を待たない）。
    function ensureModal() {
        if (modal) return;
        modalEl = document.getElementById('mediaUploadModal');
        if (!modalEl) return;

        csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        modal = new bootstrap.Modal(modalEl);

        submitBtn = document.getElementById('mediaUploadSubmitBtn');
        headerCloseBtn = document.getElementById('mediaUploadHeaderCloseBtn');
        closeBtn = document.getElementById('mediaUploadCloseBtn');
        fileInput = document.getElementById('mediaUploadFileInput');
        fileErrorEl = document.getElementById('mediaUploadFileError');
        inProgressEl = document.getElementById('mediaUploadInProgress');
        progressMessage = document.getElementById('mediaUploadProgressMessage');
        progressBar = document.getElementById('mediaUploadProgressBar');
        fileListEl = document.getElementById('mediaUploadFileList');
        fileListCountEl = document.getElementById('mediaUploadFileListCount');
        fileListItemsEl = document.getElementById('mediaUploadFileListItems');
        resultSummaryEl = document.getElementById('mediaUploadResultSummary');
        summaryTotalEl = document.getElementById('mediaUploadSummaryTotal');
        summarySuccessEl = document.getElementById('mediaUploadSummarySuccess');
        summaryFailureEl = document.getElementById('mediaUploadSummaryFailure');
        summaryFailureListEl = document.getElementById('mediaUploadSummaryFailureList');

        // ファイルの選択の欄の下のエラーの出し方を 1 か所に集める（§2-7「欄の下」の見た目に揃える）。
        // 静的な HTML には `invalid-feedback` を残さず、エラーがあるあいだだけ JS が class を付与する
        // （他画面の描画テストで `invalid-feedback` が無関係に拾われないよう）。
        function showFileError(message) {
            fileErrorEl.className = 'invalid-feedback d-block';
            fileErrorEl.textContent = message;
            fileInput.classList.add('is-invalid');
        }
        function clearFileError() {
            fileErrorEl.className = '';
            fileErrorEl.textContent = '';
            fileInput.classList.remove('is-invalid');
        }

        // ファイル選択：選択ファイル配列の更新・一覧描画・事前バリデーション
        fileInput.addEventListener('change', function () {
            // ファイルを選び直したときは、前のエラーの表示を消す（§2-7。段階 5-3b）
            clearFileError();
            // 前回登録結果のサマリは新たな選択で隠す
            resultSummaryEl.classList.add('d-none');

            selectedFiles = Array.from(this.files);
            buildFileList(selectedFiles);
            if (selectedFiles.length === 0) return;

            const result = validateAllFiles(selectedFiles);
            if (!result.ok) {
                showFileError(result.message);
            }
        });

        // 登録ボタン
        submitBtn.addEventListener('click', async function () {
            const files = selectedFiles;
            // alert をやめ、ファイルの選択の欄の下に invalid-feedback で出す（§2-7。段階 5-3b）
            if (files.length === 0) {
                showFileError('ファイルを選択してください。');
                return;
            }
            const v = validateAllFiles(files);
            if (!v.ok) {
                showFileError(v.message);
                return;
            }
            // 新しいアップロードを始めるので、前のエラーの表示を消す
            clearFileError();

            resultSummaryEl.classList.add('d-none');
            files.forEach(function (f) { setRowStatus(f, 'pending'); });
            fileListItemsEl.querySelectorAll('.row-error-message').forEach(function (el) { el.remove(); });
            fileListItemsEl.querySelectorAll('li.flex-wrap').forEach(function (el) { el.classList.remove('flex-wrap'); });

            setState('uploading');
            const results = [];
            const succeeded = [];
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                setOverallProgress(i, files.length, file.name);
                setRowStatus(file, 'processing');
                try {
                    const media = await processOneFile(file);
                    setRowStatus(file, 'done');
                    results.push({ file: file, ok: true, media: media });
                    succeeded.push(media);
                } catch (e) {
                    console.error(e);
                    const msg = e.message || '登録に失敗しました。';
                    setRowStatus(file, 'error', msg);
                    results.push({ file: file, ok: false, error: msg });
                }
            }
            // ループ完了で busy 解除（× / 閉じる が押せるように）
            setState('idle');

            const failureCount = results.filter(function (r) { return !r.ok; }).length;
            if (failureCount === 0) {
                // 全成功：onComplete を呼んでから閉じる（呼出側が reload か append を判断）
                try { currentOptions.onComplete?.(succeeded); } catch (e) { console.error(e); }
                modal.hide();
            } else if (succeeded.length > 0) {
                // 部分失敗：結果サマリ表示。閉じた時に成功分を反映するため reload フラグ立て
                showResultSummary(results);
                needsReloadOnClose = true;
            } else {
                // 全失敗：結果サマリ表示のみ。reload しない
                showResultSummary(results);
                needsReloadOnClose = false;
            }
        });

        // 処理中の閉じ防止（× / 背景 / Esc いずれも hide.bs.modal が走るので preventDefault）
        modalEl.addEventListener('hide.bs.modal', function (e) {
            if (isUploading) e.preventDefault();
        });

        // 閉じた時：onClose 呼出、部分失敗の reload フラグが立っていれば location.reload
        modalEl.addEventListener('hidden.bs.modal', function () {
            try { currentOptions.onClose?.(); } catch (e) { console.error(e); }
            const shouldReload = needsReloadOnClose;
            // 次回オープンの初期状態に戻す
            resetState();
            if (shouldReload) window.location.reload();
        });

        // タブクローズ等の警告（既存パターン：recording-v2 / audio）
        window.addEventListener('beforeunload', function (e) {
            if (isUploading) {
                e.preventDefault();
                e.returnValue = 'アップロード中です。本当にページを離れますか？';
            }
        });
    }

    // 念のため事前 warm up（DOMContentLoaded 時点で modal を初期化しておく）。
    // 初回 open 時に ensureModal を呼ぶので無くても動くが、初回 open の体感を速くする。
    document.addEventListener('DOMContentLoaded', ensureModal);

    // 公開 API は本 IIFE の冒頭で既にセット済み（hoisting される ensureModal を参照）。
    // これにより、partial スクリプト読み込み完了時点で window.mediaUploadModal.open が
    // 確実に function として存在する状態を保証する。
    //
    // ただし、万一冒頭の代入後に他コードで window.mediaUploadModal が空 {} 等で上書きされた
    // ケースに備え、IIFE 末尾でも再度公開を試みる（保険）。
    if (!window.mediaUploadModal || typeof window.mediaUploadModal.open !== 'function') {
        window.mediaUploadModal = { open: openModal };
    }
})();
</script>
@endpush
@endonce
