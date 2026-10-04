<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <title>録音実行</title>

    {{-- Bootstrap（CSS・JS）と、非同期の保存のエラー表示（§2-7）の共通 JS を同じ @vite から
         まとめて読み込む。本画面は layouts.app を使わない独立 HTML のため、ここで直接
         @vite する。recording-session.js は Bootstrap の CSS を取り込み、window.bootstrap に
         Bootstrap を載せる（下のスクリプトが `new bootstrap.Modal(...)` の形で使っている）。
         module 読み込みは defer 相当で遅延するが、使うのは「作成する」などユーザー操作時なので間に合う。 --}}
    @vite(['resources/js/recording-session.js', 'resources/js/form-errors.js'])

    <style>
        /* 基本スタイル */
        body {
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        /* 録音中画面 */
        #recording-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            z-index: 10000;
            padding: 20px;
        }

        .recording-title {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .recording-title.waiting-warning {
            color: #ff8c00;
        }

        .recording-title.paused-title {
            color: #ff8c00;
        }

        .timer {
            font-size: 48px;
            margin: 10px 0;
            font-family: 'Courier New', monospace;
            color: #dc3545;
        }

        .timer.paused {
            color: #ffc107;
            animation: blink 1s step-end infinite;
        }

        .recording-indicator {
            color: #dc3545;
            font-size: 36px;
            animation: blink 1.5s infinite;
        }

        @keyframes blink {
            0%, 49% { opacity: 1; }
            50%, 100% { opacity: 0.3; }
        }

        /* 音声レベルメーター */
        .level-meter-container {
            margin: 10px 0;
            padding: 6px;
            background: #f8f9fa;
            border-radius: 8px;
            width: auto;
            max-width: 100%;
            display: inline-block;
        }

        #level-meter {
            display: block;
            width: 330px;
            max-width: 80vw;
            height: 15px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background: #ffffff;
        }

        /* ボタン */
        .button-container {
            margin: 15px 0;
            display: flex;
            justify-content: center;
            gap: 20px;
        }

        .btn-control {
            font-size: 18px;
            padding: 12px 40px;
            min-width: 140px;
        }

        /* 注意書き */
        .notice {
            margin-top: 20px;
            color: #666;
            font-size: 16px;
        }

        .notice-small {
            margin-top: 10px;
            color: #999;
            font-size: 14px;
        }

        /* 録音開始前の状態 */
        .recording-indicator.waiting {
            color: #6c757d;
            animation: none;
        }

        .timer.waiting {
            color: #6c757d;
        }

    </style>
</head>
<body>
    <!-- 録音画面（最初から表示） -->
    <div id="recording-container">
        <h2 class="recording-title waiting-warning" id="recording-title">停止中</h2>

        <!-- タイマー -->
        <div class="timer waiting" id="timer-display">
            <span class="recording-indicator waiting" id="recording-indicator-dot">●</span>
            <span id="recording-timer">00:00:00</span>
        </div>

        <!-- 音声レベルメーター -->
        <div class="level-meter-container">
            <canvas id="level-meter" height="15"></canvas>
        </div>

        <!-- ボタン -->
        <div class="button-container">
            <button id="btn-pause-recording" class="btn btn-warning btn-control d-none" disabled>
                一時停止
            </button>
            <button id="btn-start-recording" class="btn btn-danger btn-control">
                録音開始
            </button>
        </div>

        <p class="text-center mt-3" style="font-size: 1.5em;">
            <span class="text-warning">⚠</span>
            <strong>トレーナー以外は操作しないでください</strong>
        </p>
    </div>

    {{-- モーダル0: アップロード失敗（失敗した時点で音声はブラウザのメモリにしかないため、
         閉じるボタンは付けず「もう一度送る」だけを置く） --}}
    <div class="modal fade" id="modal-upload-failed" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">アップロードに失敗しました</h5>
                </div>
                <div class="modal-body">
                    <p class="mb-0">音声ファイルのアップロードに失敗しました。通信の状態を確かめて、もう一度送ってください。</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="btn-retry-upload">もう一度送る</button>
                </div>
            </div>
        </div>
    </div>

    {{-- モーダル1: 録音完了 --}}
    <div class="modal fade" id="modal-recording-complete" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">録音完了</h5>
                </div>
                <div class="modal-body">
                    <p>録音が完了しました。</p>
                    <p class="text-muted mb-0">音声記録一覧で確認できます。</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="btn-confirm-complete">OK</button>
                </div>
            </div>
        </div>
    </div>

    {{-- モーダル2: トレーニング記録作成確認 --}}
    <div class="modal fade" id="modal-confirm-create-record" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">トレーニング記録の作成</h5>
                </div>
                <div class="modal-body">
                    <p>このままトレーニング記録を作成しますか？</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="btn-no-create-record">いいえ</button>
                    <button type="button" class="btn btn-primary" id="btn-yes-create-record">はい、作成する</button>
                </div>
            </div>
        </div>
    </div>

    {{-- モーダル3: トレーニング記録登録フォーム --}}
    <div class="modal fade" id="modal-create-record" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">トレーニング記録の作成</h5>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">会員</label>
                        <input type="text" class="form-control bg-light" id="record-client-name" readonly>
                    </div>

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">日付</label>
                            <input type="text" class="form-control bg-light" value="{{ date('Y年m月d日') }}（今日）" readonly>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">時刻</label>
                            <input type="text" class="form-control bg-light" id="record-training-time" readonly>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="trainer1_select" class="form-label">担当1 <span class="text-danger">*</span></label>
                            {{-- name 属性は共通 JS `FormErrors.showFieldErrors` が [name="…"] で欄を見つけるため
                                 （送信は fetch で JS がキーごとに body を組み立てるので name は送信に使わないが、
                                 エラーの対象を特定する手がかりとして付ける。§2-7「非同期の保存（fetch）」） --}}
                            <select class="form-select" id="trainer1_select" name="trainer1_id" required>
                                <option value=""></option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="trainer2_select" class="form-label">担当2</label>
                            <select class="form-select" id="trainer2_select" name="trainer2_id">
                                <option value=""></option>
                            </select>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="btn-cancel-create-record">キャンセル</button>
                    <button type="button" class="btn btn-primary" id="btn-submit-create-record">作成する</button>
                </div>
            </div>
        </div>
    </div>

    {{-- モーダル4: 送信中（「作成する」の受け付けの応答を待つ間。閉じるボタンは付けない。
         文字起こし・要約・記録の作成は後ろで進めるため、ここでは受け付けの応答だけを待つ。2026-10） --}}
    <div class="modal fade" id="modal-processing" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">トレーニング記録の作成</h5>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">送信中...</span>
                    </div>
                    <p class="mb-0">送信中... このまましばらくお待ちください。</p>
                </div>
            </div>
        </div>
    </div>

    {{-- モーダル5: 受け付け（文字起こし・要約・記録の作成を後ろで進める。2026-10） --}}
    <div class="modal fade" id="modal-record-created" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">トレーニング記録の作成</h5>
                </div>
                <div class="modal-body">
                    <p class="mb-0">文字起こし・要約・トレーニング記録の作成は、後ろで進めます。結果は音声記録一覧・トレーニング記録一覧で確かめてください。</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="btn-confirm-record-created">OK</button>
                </div>
            </div>
        </div>
    </div>

    {{-- モーダル6: ログアウト --}}
    <div class="modal fade" id="modal-logout" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-5">
                    <h4 class="mb-3">自動ログアウトします</h4>
                    <div class="spinner-border text-primary mt-3" role="status">
                        <span class="visually-hidden">読み込み中...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ログアウト用フォーム（POST送信） -->
    <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display: none;">
        @csrf
    </form>

    {{-- Bootstrap の JS は head の @vite(['resources/js/recording-session.js', ...]) から window.bootstrap に載せている。 --}}

    <script>
        // ブラウザの戻るボタンを無効化
        history.pushState(null, null, location.href);
        window.addEventListener('popstate', function() {
            history.pushState(null, null, location.href);
            alert('録音セッション中は他の画面に移動できません。');
        });

        // ページ離脱の警告（録音中と、録音を止めてからアップロードに成功するまでの間）
        let allowLeave = false;
        let isRecording = false;
        // 録音を止めてから、アップロードに成功するまで true。この間、音声はブラウザの
        // メモリ（audioChunks）にしかなく、画面を離れると録音が失われるため警告を出す。
        let uploadPending = false;

        window.addEventListener('beforeunload', function(e) {
            if ((isRecording && !allowLeave) || uploadPending) {
                e.preventDefault();
                e.returnValue = '録音中です。本当にページを離れますか？';
            }
        });

        // グローバル変数
        let mediaRecorder = null;
        let audioChunks = [];
        let timerInterval = null;
        let seconds = 0;
        let stream = null;
        let audioContext = null;
        let analyser = null;
        let dataArray = null;
        let animationId = null;
        let uploadedAudioRecordId = null;
        let recordingStartTime = null; // 録音開始時刻（トレーニング記録の training_time 用）

        // Dateオブジェクトを「HH:MM」形式の文字列に変換（nullの場合は空文字）
        function formatTimeHHMM(date) {
            if (!(date instanceof Date)) return '';
            return String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
        }

        // クライアント情報（必須）
        // 業務方針: 音声記録は必ずクライアントに紐付ける（飛び込みケース未想定）
        const clientId = {{ $client->id }};
        const clientName = @json($client->internal_id . ' ' . $client->display_name);

        // 認証情報
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const currentUserId = {{ Auth::id() }};
        const currentDate = '{{ date("Y-m-d") }}';

        // 担当1・担当2 の入力エラーの文言は、サーバーの文言（lang/ja/validation.php の custom.*）と
        // 画面側で揃える。段階 5 で画面側の直書きをやめ、言語ファイルから取り出す形に変えた。
        const TRAINER1_REQUIRED_MESSAGE = @json(__('validation.custom.trainer1_id.required'));
        const TRAINER2_DIFFERENT_MESSAGE = @json(__('validation.custom.trainer2_id.different'));

        // ========================================
        // タイマー
        // ========================================

        function startTimer() {
            timerInterval = setInterval(() => {
                seconds++;
                const h = Math.floor(seconds / 3600).toString().padStart(2, '0');
                const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
                const s = (seconds % 60).toString().padStart(2, '0');
                document.getElementById('recording-timer').textContent = `${h}:${m}:${s}`;
            }, 1000);
        }

        function stopTimer() {
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
        }

        // ========================================
        // 音声レベルメーター
        // ========================================

        function drawLevelMeter() {
            const canvas = document.getElementById('level-meter');
            const canvasContext = canvas.getContext('2d');

            // canvasの描画解像度を表示サイズに合わせる
            canvas.width = canvas.offsetWidth;
            canvas.height = canvas.offsetHeight;
            const width = canvas.width;
            const height = canvas.height;

            function draw() {
                animationId = requestAnimationFrame(draw);

                // 録音の前と一時停止中は、メーターを空の表示（背景と枠線だけ）にする。
                // マイクの音は画面を開いたときから拾っているが、録音していないときにメーターが動くと
                // 録音しているように見えるため（2026-10）。録音中（一時停止中を除く）だけ動かす。
                // 録音の前は mediaRecorder が null のため、null のときも例外にならないように判定する
                const isPausedNow = mediaRecorder !== null && mediaRecorder.state === 'paused';
                if (!isRecording || isPausedNow) {
                    canvasContext.fillStyle = '#f0f0f0';
                    canvasContext.fillRect(0, 0, width, height);
                    canvasContext.strokeStyle = '#dee2e6';
                    canvasContext.lineWidth = 2;
                    canvasContext.strokeRect(0, 0, width, height);
                    return;
                }

                analyser.getByteFrequencyData(dataArray);

                // 平均音量を計算
                let sum = 0;
                for (let i = 0; i < dataArray.length; i++) {
                    sum += dataArray[i];
                }
                const average = sum / dataArray.length;

                // キャンバスをクリア
                canvasContext.fillStyle = '#f0f0f0';
                canvasContext.fillRect(0, 0, width, height);

                // バーの長さを計算（感度を上げて0-100%にマッピング）
                const level = Math.min(100, (average / 80) * 100);
                const barLength = (level / 100) * width;

                // グラデーションをバーの長さに合わせて作成
                if (barLength > 0) {
                    const gradient = canvasContext.createLinearGradient(0, 0, barLength, 0);
                    gradient.addColorStop(0, '#4ecdc4');
                    if (level > 30) gradient.addColorStop(Math.min(0.4, 30 / level), '#6bcf7f');
                    if (level > 60) gradient.addColorStop(Math.min(0.7, 60 / level), '#ffd93d');
                    if (level > 80) gradient.addColorStop(1, '#ff6b6b');
                    else gradient.addColorStop(1, level > 60 ? '#ffd93d' : '#6bcf7f');

                    canvasContext.fillStyle = gradient;
                    canvasContext.fillRect(0, 0, barLength, height);
                }

                // 枠線を描画
                canvasContext.strokeStyle = '#dee2e6';
                canvasContext.lineWidth = 2;
                canvasContext.strokeRect(0, 0, width, height);
            }

            draw();
        }

        function stopLevelMeter() {
            if (animationId) {
                cancelAnimationFrame(animationId);
                animationId = null;
            }
        }

        // ========================================
        // マイク初期化（レベルメーター用）
        // ========================================

        async function initMicrophone() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });

                // 音声レベルメーター用の AudioContext
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
                analyser = audioContext.createAnalyser();
                const source = audioContext.createMediaStreamSource(stream);
                source.connect(analyser);

                analyser.fftSize = 256;
                const bufferLength = analyser.frequencyBinCount;
                dataArray = new Uint8Array(bufferLength);

                drawLevelMeter();
            } catch (error) {
                console.error('マイク初期化エラー:', error);
                alert('マイクへのアクセスが拒否されました。ブラウザの設定を確認してください。');
            }
        }

        // ========================================
        // 録音
        // ========================================

        async function startRecording() {
            try {
                // マイクが未初期化の場合は初期化
                if (!stream) {
                    await initMicrophone();
                }

                // iOS/iPadデバイスの判定
                const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

                // mimeTypeの選択
                let mimeType;
                if (isIOS) {
                    if (MediaRecorder.isTypeSupported('audio/mp4')) {
                        mimeType = 'audio/mp4';
                    } else if (MediaRecorder.isTypeSupported('audio/mpeg')) {
                        mimeType = 'audio/mpeg';
                    } else {
                        mimeType = 'audio/webm';
                    }
                } else {
                    if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                        mimeType = 'audio/webm;codecs=opus';
                    } else {
                        mimeType = 'audio/webm';
                    }
                }

                console.log('選択されたmimeType:', mimeType);

                mediaRecorder = new MediaRecorder(stream, {
                    mimeType: mimeType,
                    // 音声ビットレート（64 kbps）。話し声の聞き取りと文字起こしに十分な音質を維持しつつ、
                    // 60 分の録音を約 29MB に抑える。ブラウザによっては指定どおりにならない場合がある。
                    audioBitsPerSecond: 64000,
                });

                mediaRecorder.ondataavailable = function(e) {
                    if (e.data.size > 0) {
                        audioChunks.push(e.data);
                    }
                };

                mediaRecorder.onstop = async function() {
                    stopTimer();
                    stopLevelMeter();
                    isRecording = false;
                    uploadPending = true;

                    stream.getTracks().forEach(track => track.stop());

                    if (audioContext) {
                        audioContext.close();
                        audioContext = null;
                    }

                    // 録音中の離脱警告を無効化（アップロードに成功するまでは uploadPending で警告を出す）
                    allowLeave = true;

                    await uploadRecording();
                };

                mediaRecorder.start(1000);
                startTimer();
                isRecording = true;

            } catch (error) {
                console.error('録音開始エラー:', error);
                alert('マイクへのアクセスが拒否されました。ブラウザの設定を確認してください。');
            }
        }

        // 一時停止ボタン
        document.getElementById('btn-pause-recording').addEventListener('click', function() {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                mediaRecorder.pause();
                this.textContent = '再開';
                this.classList.remove('btn-warning');
                this.classList.add('btn-danger');
                stopTimer();
                document.getElementById('timer-display').classList.add('paused');
                // タイトルを「一時停止中」に変更
                var pauseTitleEl = document.getElementById('recording-title');
                pauseTitleEl.textContent = '一時停止中';
                pauseTitleEl.classList.add('paused-title');
            } else if (mediaRecorder && mediaRecorder.state === 'paused') {
                mediaRecorder.resume();
                this.textContent = '一時停止';
                this.classList.remove('btn-danger');
                this.classList.add('btn-warning');
                startTimer();
                document.getElementById('timer-display').classList.remove('paused');
                // タイトルを「録音中」に戻す
                var resumeTitleEl = document.getElementById('recording-title');
                resumeTitleEl.textContent = '録音中';
                resumeTitleEl.classList.remove('paused-title');
            }
        });

        // 注: 停止機能は「開始/停止」ボタン（btn-start-recording）に統合

        // ========================================
        // アップロード
        // ========================================

        async function uploadRecording() {
            try {
                const blob = new Blob(audioChunks, { type: mediaRecorder.mimeType });

                let extension = 'webm';
                if (mediaRecorder.mimeType.includes('mp4')) {
                    extension = 'm4a';
                } else if (mediaRecorder.mimeType.includes('mpeg')) {
                    extension = 'mp3';
                }

                const now = new Date();
                const fileName = now.getFullYear().toString() +
                    String(now.getMonth() + 1).padStart(2, '0') +
                    String(now.getDate()).padStart(2, '0') + '_' +
                    String(now.getHours()).padStart(2, '0') +
                    String(now.getMinutes()).padStart(2, '0') +
                    String(now.getSeconds()).padStart(2, '0') + '.' + extension;

                const formData = new FormData();
                formData.append('file', blob, fileName);
                formData.append('client_id', clientId);

                // 録音画面を非表示
                document.getElementById('recording-container').style.display = 'none';

                const response = await fetch('{{ route("audio-records.recording.store") }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: formData
                });

                if (response.ok) {
                    const data = await response.json();
                    uploadedAudioRecordId = data.data.id;
                    // 音声はサーバーに保存できたので、離脱警告を外す（このあとの自動ログアウトで警告を出さない）
                    uploadPending = false;

                    showRecordingCompleteModal();
                } else {
                    const errorData = await response.json();
                    console.error('アップロードエラー:', errorData);
                    showUploadFailedModal();
                }

            } catch (error) {
                console.error('アップロードエラー:', error);
                showUploadFailedModal();
            }
        }

        // ========================================
        // モーダル0: アップロード失敗
        // ========================================

        // 失敗した時点では、録音した音声はブラウザのメモリ（audioChunks）にしかなく、
        // 画面を離れると失われる。audioChunks は失敗の経路でも空にしないため、
        // 「もう一度送る」で同じ uploadRecording を呼び直して、同じ音声を送り直す（何度でも可）。
        let uploadFailedModal = null;
        const btnRetryUpload = document.getElementById('btn-retry-upload');

        function showUploadFailedModal() {
            if (!uploadFailedModal) {
                uploadFailedModal = new bootstrap.Modal(document.getElementById('modal-upload-failed'));
            }
            // 送り直しが失敗したときは、ボタンを押せる状態に戻す（モーダルは開いたまま）
            btnRetryUpload.disabled = false;
            btnRetryUpload.textContent = 'もう一度送る';
            uploadFailedModal.show();
        }

        btnRetryUpload.addEventListener('click', async function() {
            // 二重に押されないよう、送信中はボタンを押せなくする
            btnRetryUpload.disabled = true;
            btnRetryUpload.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>送信中...';

            await uploadRecording();

            // 成功したら（音声記録の ID が入る）、このモーダルを閉じる。
            // 「録音完了」のモーダルは uploadRecording の成功の経路で開かれる。
            if (uploadedAudioRecordId !== null) {
                uploadFailedModal.hide();
            }
        });

        // ========================================
        // モーダル1: 録音完了
        // ========================================

        function showRecordingCompleteModal() {
            const modal = new bootstrap.Modal(document.getElementById('modal-recording-complete'));
            modal.show();

            document.getElementById('btn-confirm-complete').addEventListener('click', function() {
                modal.hide();
                showConfirmCreateRecordModal();
            }, { once: true });
        }

        // ========================================
        // モーダル2: トレーニング記録作成確認
        // ========================================

        function showConfirmCreateRecordModal() {
            const modal = new bootstrap.Modal(document.getElementById('modal-confirm-create-record'));
            modal.show();

            // 「いいえ」→ ログアウト
            document.getElementById('btn-no-create-record').addEventListener('click', function() {
                modal.hide();
                showLogoutModal();
            }, { once: true });

            // 「はい、作成する」→ トレーニング記録登録フォーム
            document.getElementById('btn-yes-create-record').addEventListener('click', async function() {
                modal.hide();
                await showCreateRecordModal();
            }, { once: true });
        }

        // ========================================
        // モーダル3: トレーニング記録登録フォーム
        // ========================================

        // トレーナー一覧を読み込み
        async function loadTrainers() {
            var response = await fetch('/api/trainers', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            var trainers = await response.json();

            // 担当1（デフォルト: ログインユーザー）
            var trainer1Options = trainers.map(function(c) {
                return '<option value="' + c.id + '"' + (c.id === currentUserId ? ' selected' : '') + '>' + c.name + '</option>';
            }).join('');
            document.getElementById('trainer1_select').innerHTML = '<option value=""></option>' + trainer1Options;

            // 担当2（デフォルト: なし）
            var trainer2Options = trainers.map(function(c) {
                return '<option value="' + c.id + '">' + c.name + '</option>';
            }).join('');
            document.getElementById('trainer2_select').innerHTML = '<option value=""></option>' + trainer2Options;
        }

        async function showCreateRecordModal() {
            // クライアント名を表示
            document.getElementById('record-client-name').value = clientName;

            // 録音開始時刻を表示（HH:MM形式）
            document.getElementById('record-training-time').value = formatTimeHHMM(recordingStartTime);

            // トレーナー一覧を読み込み
            await loadTrainers();

            // 参加者の初期行を1つ追加
            var modalEl = document.getElementById('modal-create-record');
            var modal = new bootstrap.Modal(modalEl);

            // 担当1・担当2 の選択を変えたら、その欄のエラーの表示を消す（§2-7。段階 5）。
            // FormErrors.clearFieldErrors はコンテナ全体をクリアするため、ここでは
            // 変更された欄だけをピンポイントで消す（他方のエラー表示を残す）。
            function clearFieldErrorByName(name) {
                var sel = modalEl.querySelector('[name="' + name + '"]');
                if (!sel) return;
                sel.classList.remove('is-invalid');
                // showFieldErrors が付けた [data-form-errors-field] の兄弟 div を取り除く
                var next = sel.nextElementSibling;
                while (next) {
                    var toCheck = next;
                    next = next.nextElementSibling;
                    if (toCheck.hasAttribute && toCheck.hasAttribute('data-form-errors-field')) {
                        toCheck.remove();
                    }
                }
            }
            document.getElementById('trainer1_select').addEventListener('change', function () { clearFieldErrorByName('trainer1_id'); });
            document.getElementById('trainer2_select').addEventListener('change', function () { clearFieldErrorByName('trainer2_id'); });

            // モーダルを開くときに、前のエラーの表示を消す（§2-7。段階 5。モーダルは
            // この画面では 1 度だけ開くが、将来の再利用に備える）。
            if (window.FormErrors) window.FormErrors.clearFieldErrors(modalEl);

            modal.show();

            // 「キャンセル」→ ログアウト
            document.getElementById('btn-cancel-create-record').addEventListener('click', function() {
                modal.hide();
                showLogoutModal();
            }, { once: true });

            // 「作成する」→ 処理開始
            var btnSubmit = document.getElementById('btn-submit-create-record');
            var submitHandler = async function() {
                // 新しい保存を始めるので、前のエラーの表示を消す（§2-7。段階 5）
                if (window.FormErrors) window.FormErrors.clearFieldErrors(modalEl);

                var trainer1Id = document.getElementById('trainer1_select').value;
                if (!trainer1Id) {
                    // alert をやめ、担当1 の欄の下に出す（§2-7。段階 5。文言は言語ファイルから）
                    window.FormErrors.showFieldErrors(modalEl, { trainer1_id: [TRAINER1_REQUIRED_MESSAGE] });
                    return;
                }

                var trainer2Id = document.getElementById('trainer2_select').value;
                // 担当1=担当2 は文字起こし（高コスト処理）の前に弾く（無駄な外部APIコストを防ぐ）
                if (trainer2Id && trainer2Id === trainer1Id) {
                    // alert をやめ、担当2 の欄の下に出す（§2-7。段階 5。文言は言語ファイルから）
                    window.FormErrors.showFieldErrors(modalEl, { trainer2_id: [TRAINER2_DIFFERENT_MESSAGE] });
                    return;
                }

                // バリデーション通過後、リスナーを解除してフォームを閉じる
                btnSubmit.removeEventListener('click', submitHandler);
                modal.hide();
                var processingModal = new bootstrap.Modal(document.getElementById('modal-processing'));
                processingModal.show();

                try {
                    // 文字起こし → 要約 → トレーニング記録の作成を、ひとつながりで受け付けてもらう（2026-10）。
                    // サーバーはキューに渡してすぐ返す（本番）。sync（開発）のときは、ここで 3 つとも終わってから返る
                    var createResponse = await fetch('/api/audio-records/' + uploadedAudioRecordId + '/auto-create-training-record', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            client_id: clientId,
                            training_date: currentDate,
                            training_time: formatTimeHHMM(recordingStartTime) || null,
                            trainer1_id: trainer1Id,
                            trainer2_id: trainer2Id || null
                        })
                    });

                    var result = await createResponse.json();

                    if (createResponse.ok && result.success) {
                        processingModal.hide();
                        showRecordCreatedModal();
                    } else {
                        throw new Error(result?.error?.message || result.message || 'トレーニング記録の作成を受け付けられませんでした。');
                    }

                } catch (error) {
                    processingModal.hide();
                    // 音声は保存済みなので、音声記録一覧からやり直せることを添える
                    alert('エラーが発生しました: ' + error.message + '\n音声は保存済みです。音声記録一覧から、文字起こし・要約をやり直せます。');
                    showLogoutModal();
                }
            };
            btnSubmit.addEventListener('click', submitHandler);
        }

        // ========================================
        // モーダル5: 受け付け（文字起こし・要約・記録の作成を後ろで進める）
        // ========================================

        function showRecordCreatedModal() {
            var modal = new bootstrap.Modal(document.getElementById('modal-record-created'));
            modal.show();

            document.getElementById('btn-confirm-record-created').addEventListener('click', function() {
                modal.hide();
                showLogoutModal();
            }, { once: true });
        }

        // ========================================
        // モーダル6: ログアウト
        // ========================================

        function showLogoutModal() {
            var modal = new bootstrap.Modal(document.getElementById('modal-logout'));
            modal.show();

            // 3秒後にログアウト（POSTで送信）
            setTimeout(function() {
                document.getElementById('logout-form').submit();
            }, 3000);
        }

        // ========================================
        // 初期化
        // ========================================

        // ページ読み込み時にマイクを初期化（レベルメーター表示用）
        initMicrophone();

        // 「開始/停止」ボタンのクリックイベント
        const btnStartStop = document.getElementById('btn-start-recording');
        btnStartStop.addEventListener('click', function() {
            if (!isRecording) {
                // 録音開始時刻を記録（トレーニング記録の training_time 用）
                recordingStartTime = new Date();

                // 録音開始
                startRecording().then(() => {
                    // UIを録音中状態に切り替え
                    const titleEl = document.getElementById('recording-title');
                    titleEl.textContent = '録音中';
                    titleEl.classList.remove('waiting-warning');
                    document.getElementById('timer-display').classList.remove('waiting');
                    document.getElementById('recording-indicator-dot').classList.remove('waiting');
                    document.getElementById('btn-pause-recording').classList.remove('d-none');
                    document.getElementById('btn-pause-recording').disabled = false;

                    // 「開始」→「停止」に変更
                    btnStartStop.textContent = '停止';
                    btnStartStop.classList.remove('btn-danger');
                    btnStartStop.classList.add('btn-secondary');
                });
            } else {
                // 録音停止
                if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                    mediaRecorder.stop();
                }
            }
        });
    </script>
</body>
</html>
