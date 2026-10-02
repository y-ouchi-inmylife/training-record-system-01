{{-- トレーニング記録登録・編集共通フォーム（クライアントは常に固定モード） --}}
{{-- 動画サムネイル用▶オーバーレイ CSS を読み込む（本フォームはメディアカードを
     JS で動的生成するため、SVG マークアップは JS 側で組み立てる。CSS だけ先に
     styles スタックへ載せておく。@once のため二重定義にはならない） --}}
@include('media-records._video-play-overlay-styles')
<form method="POST" action="{{ $action }}" id="trainingRecordForm" novalidate>
    @csrf
    @if($method === 'PUT')
        @method('PUT')
    @endif

    {{-- 画面上部の 1 文の案内（設計書 §2-7）。novalidate は 2026-09 以前から付いており、
         入力の検証はサーバーに任せる（JS の独自チェックは段階 3-3 のリファクタで削除）。 --}}
    <x-form-error-summary />

    {{-- 基本情報 --}}
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">基本情報</h6>
        </div>
        <div class="card-body">
            {{-- 1段目: 会員 / トレーニング日 / 時刻 --}}
            <div class="row g-3 mb-2">
                {{-- 会員（クライアント詳細からの遷移で確定：変更不可） --}}
                <div class="col-md-4">
                    <div class="row g-2 align-items-center">
                        <label class="col-md-auto col-form-label text-md-end form-label-fixed">会員 <span class="text-danger">*</span></label>
                        <div class="col-12 col-md">
                            @php $fixedClient = $selectedClient ?? $record->client; @endphp
                            {{-- 読み取り専用の表示 input に is-invalid を付ける。hidden の client_id の
                                 サーバーエラー（通常は出ない）が出たとき、他の欄と同じ赤枠になる。
                                 invalid-feedback は readonly input の兄弟に置いても Bootstrap のセレクタで
                                 自動表示されるが、hidden input がその間に挟まるため念のため block を付ける
                                 （設計書 §2-7「兄弟の位置に置けないとき」）。 --}}
                            <input type="text" class="form-control bg-light @error('client_id') is-invalid @enderror"
                                   value="{{ $fixedClient->internal_id }} {{ $fixedClient->display_name }}"
                                   data-client-display-name="{{ $fixedClient->display_name }}"
                                   readonly>
                            <input type="hidden" name="client_id" value="{{ $fixedClient->id }}">
                            <x-form-error field="client_id" block />
                        </div>
                    </div>
                </div>

                {{-- トレーニング日 --}}
                <div class="col-md-4">
                    <div class="row g-2 align-items-center">
                        <label for="training_date" class="col-md-auto col-form-label text-md-end form-label-fixed">トレーニング日 <span class="text-danger">*</span></label>
                        <div class="col-12 col-md">
                            {{-- Flatpickr（.datepicker）は altInput を使っていないためオリジナルの input が
                                 残り、兄弟セレクタで invalid-feedback が自動表示される。block は付けない
                                 （3-1 と同じ扱い）。 --}}
                            <input type="text" name="training_date" id="training_date"
                                class="form-control datepicker @error('training_date') is-invalid @enderror"
                                value="{{ old('training_date', $record?->training_date?->format('Y-m-d') ?? date('Y-m-d')) }}"
                                placeholder="例: 2026-04-01" pattern="\d{4}-\d{2}-\d{2}" maxlength="10" required>
                            <x-form-error field="training_date" />
                        </div>
                    </div>
                </div>

                {{-- 時刻 --}}
                <div class="col-md-3">
                    <div class="row g-2 align-items-center">
                        <label for="training_time" class="col-md-auto col-form-label text-md-end form-label-fixed">時刻</label>
                        <div class="col-12 col-md">
                            <input type="time" name="training_time" id="training_time"
                                class="form-control @error('training_time') is-invalid @enderror"
                                value="{{ old('training_time', $record?->training_time ? substr($record->training_time, 0, 5) : '') }}">
                            <x-form-error field="training_time" />
                        </div>
                    </div>
                </div>
            </div>

            {{-- 2段目: 担当1 + 担当2 --}}
            <div class="row g-3">
                <div class="col-md-7">
                    <div class="row g-2 align-items-center">
                        <label for="trainer1_id" class="col-md-auto col-form-label text-md-end form-label-fixed">
                            担当1 <span class="text-danger">*</span> / 担当2
                        </label>
                        <div class="col-12 col-md">
                            <div class="row g-2">
                                <div class="col-6">
                                    <select name="trainer1_id" id="trainer1_id" class="form-select @error('trainer1_id') is-invalid @enderror" required>
                                        <option value=""></option>
                                        @foreach($trainers as $trainer)
                                            <option value="{{ $trainer->id }}"
                                                {{ old('trainer1_id', $record?->trainer1_id ?? auth()->id()) == $trainer->id ? 'selected' : '' }}>
                                                {{ $trainer->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <x-form-error field="trainer1_id" />
                                </div>
                                <div class="col-6">
                                    <select name="trainer2_id" id="trainer2_id" class="form-select @error('trainer2_id') is-invalid @enderror">
                                        <option value=""></option>
                                        @foreach($trainers as $trainer)
                                            <option value="{{ $trainer->id }}"
                                                {{ old('trainer2_id', $record?->trainer2_id) == $trainer->id ? 'selected' : '' }}>
                                                {{ $trainer->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <x-form-error field="trainer2_id" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- メディア（基本情報の直下に配置：設計書 S-0401 / S-0404） --}}
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">メディア</h6>
            <div class="d-flex gap-2">
                {{-- 新規登録して追加（S-1302-M02 メディア登録モーダルを開く） --}}
                <button type="button" class="btn btn-primary" id="mediaUploadOpenBtn">新規登録して追加</button>
                {{-- 追加（既存メディアを紐づける S-0401-M02 / S-0404-M01 を開く） --}}
                <button type="button" class="btn btn-primary" id="mediaAddBtn" disabled>追加</button>
            </div>
        </div>
        <div class="card-body">
            <div id="mediaSelectionEmpty" class="text-muted d-none">
                この記録のメディアはありません。
            </div>
            <div id="mediaSelectionGrid" class="row row-cols-2 row-cols-md-4 row-cols-xl-6 g-3 d-none"></div>
            {{-- hidden input は JS が items 順に再生成（フォーム送信で media_record_ids[] として送信される） --}}
            <div id="mediaSelectionHiddenInputs"></div>
        </div>
    </div>

    {{-- トレーナーからのノート --}}
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">トレーナーからのノート</h6>
            <button type="button" class="btn btn-outline-primary" id="insertSummaryBtn">
                音声記録の要約から入力
            </button>
        </div>
        <div class="card-body">
            <textarea name="record_content" id="record_content" rows="8"
                class="form-control @error('record_content') is-invalid @enderror"
                inputmode="text">{{ old('record_content', $record?->record_content) }}</textarea>
            <x-form-error field="record_content" />
        </div>
    </div>

    {{-- 所感 --}}
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">所感 <span class="text-muted">（会員には非開示）</span></h6>
        </div>
        <div class="card-body">
            <textarea name="impression" id="impression" rows="4"
                class="form-control @error('impression') is-invalid @enderror"
                inputmode="text">{{ old('impression', $record?->impression) }}</textarea>
            <x-form-error field="impression" />
        </div>
    </div>

    {{-- 送信ボタン --}}
    <div class="d-flex justify-content-end gap-2 mb-4">
        @if($record)
            <a href="{{ route('training-records.show', $record) }}" class="btn btn-secondary js-leave-link">キャンセル</a>
        @else
            <a href="{{ $selectedClientId ? route('clients.show', $selectedClientId) : route('training-records.index') }}" class="btn btn-secondary js-leave-link">キャンセル</a>
        @endif
        <button type="submit" class="btn btn-success">{{ $record ? '更新' : '登録' }}</button>
    </div>
</form>

{{-- 送信前の JS 独自のチェック（必須の判定・.validation-error-summary の動的
     生成・is-invalid の付け外し・「〜を選択してください。」などの文言）は
     段階 3-3 で削除し、サーバーの検証に一本化した（設計書 §2-7）。 --}}

{{-- メディア登録モーダル（S-1302-M02）。登録・編集の両画面から呼ぶ。 --}}
@include('media-records._upload-modal')

{{-- メディア追加モーダル（S-0401-M02 / S-0404-M01） --}}
<div class="modal fade" id="mediaAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">メディアを追加</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <label for="mediaAddTrainerFilter" class="form-label mb-0 text-nowrap">登録者:</label>
                    <select id="mediaAddTrainerFilter" class="form-select" style="width: auto;">
                        <option value="all">全員</option>
                        @foreach($trainers as $t)
                            <option value="{{ $t->id }}" {{ $t->id === auth()->id() ? 'selected' : '' }}>
                                {{ $t->name }}
                            </option>
                        @endforeach
                    </select>
                    <span class="text-muted small ms-auto">選択中: <span id="mediaAddSelectedCount">0</span> 件</span>
                </div>
                <div id="mediaAddLoading" class="text-center d-none">
                    <div class="spinner-border" role="status"><span class="visually-hidden">読み込み中...</span></div>
                </div>
                <div id="mediaAddError" class="alert alert-danger d-none"></div>
                <div id="mediaAddEmpty" class="text-muted small d-none">追加できるメディアがありません</div>
                <div id="mediaAddGrid" class="row row-cols-2 row-cols-md-4 row-cols-xl-6 g-3"></div>
                <nav id="mediaAddPagination" class="mt-3 d-none">
                    <ul class="pagination pagination-sm justify-content-center mb-0"></ul>
                </nav>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-primary" id="mediaAddConfirmBtn" disabled>追加</button>
            </div>
        </div>
    </div>
</div>

{{-- 要約選択モーダル --}}
<div class="modal fade" id="summaryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="summaryModalTitle">音声記録の要約から入力</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                {{-- 検索 --}}
                <div class="mb-3">
                    <input type="text" class="form-control" id="summarySearch" placeholder="表示名で検索...">
                </div>

                {{-- ローディング --}}
                <div id="summaryLoading" class="text-center d-none">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">読み込み中...</span>
                    </div>
                </div>

                {{-- 要約一覧 --}}
                <div id="summaryList"></div>

                {{-- エラーメッセージ --}}
                <div id="summaryError" class="alert alert-danger d-none"></div>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    /* メディアセクションの D&D 並べ替え（Step4並べ替え） */
    #mediaSelectionGrid .col { cursor: grab; }
    #mediaSelectionGrid .col:active { cursor: grabbing; }
    .media-card-ghost { opacity: 0.4; }
</style>
@endpush

@push('scripts')
{{-- Sortable.js: メディアセクションのドラッグ&ドロップ並べ替え用。
     登録・編集の両画面で使うため、_form 側に集約して読み込む。
     ビルドされた入口から window.Sortable に載せる（CDN は使わない）。 --}}
@vite(['resources/js/sortable.js'])
<script>
document.addEventListener('DOMContentLoaded', function() {
    const summaryModal = new bootstrap.Modal(document.getElementById('summaryModal'));
    let summaries = [];

    // 「要約から入力」ボタンクリック
    document.getElementById('insertSummaryBtn').addEventListener('click', function() {
        // 現在選択中の client_id を取得（hidden input から）
        var clientIdEl = document.querySelector('[name="client_id"]');
        var clientId = clientIdEl ? clientIdEl.value : '';
        if (!clientId) {
            // 通常は固定クライアントが必ずあるが、防御として残す
            return;
        }

        // モーダルタイトルを「○○ さんの音声記録の要約から入力」に動的更新（クライアント名を強調）
        var clientName = getCurrentClientDisplayName();
        var modalTitle = document.getElementById('summaryModalTitle');
        if (modalTitle) {
            if (clientName) {
                modalTitle.innerHTML = '<strong class="text-primary">' + escapeHtml(clientName) + '</strong> さんの音声記録の要約から入力';
            } else {
                modalTitle.textContent = '音声記録の要約から入力';
            }
        }

        loadSummaries(clientId);
        summaryModal.show();
    });

    // 現在選択中のクライアントの表示名を取得（readonly input の data-client-display-name から）
    function getCurrentClientDisplayName() {
        var readonlyInput = document.querySelector('input[data-client-display-name]');
        return readonlyInput ? (readonlyInput.dataset.clientDisplayName || null) : null;
    }

    // 検索フィルタ
    document.getElementById('summarySearch').addEventListener('input', function(e) {
        const filtered = summaries.filter(function(item) {
            return (item.title || item.file_name).toLowerCase().includes(e.target.value.toLowerCase());
        });
        renderSummaries(filtered);
    });

    // 要約一覧を読み込み（client_id でサーバー側フィルタ）
    async function loadSummaries(clientId) {
        const loading = document.getElementById('summaryLoading');
        const list = document.getElementById('summaryList');
        const error = document.getElementById('summaryError');

        loading.classList.remove('d-none');
        list.innerHTML = '';
        error.classList.add('d-none');

        try {
            const url = '/api/audio-records/summaries?client_id=' + encodeURIComponent(clientId);
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });

            if (!response.ok) throw new Error('データの取得に失敗しました');

            const data = await response.json();

            if (data.success) {
                summaries = data.data;
                renderSummaries(summaries);
            } else {
                throw new Error('データの取得に失敗しました');
            }
        } catch (err) {
            error.textContent = 'エラー: ' + err.message;
            error.classList.remove('d-none');
        } finally {
            loading.classList.add('d-none');
        }
    }

    // 要約一覧を表示
    function renderSummaries(items) {
        const list = document.getElementById('summaryList');

        if (items.length === 0) {
            list.innerHTML = '<p class="text-muted">要約済みファイルがありません。</p>';
            return;
        }

        list.innerHTML = items.map(function(item) {
            const preview = escapeHtml(item.summary_text).substring(0, 200);
            return '<div class="card mb-2">' +
                '<div class="card-body">' +
                '<h6 class="card-title">' + escapeHtml(item.title || item.file_name) + '</h6>' +
                '<p class="card-text text-muted small">' + formatDate(item.created_at) + '</p>' +
                '<div class="mb-2" style="max-height: 100px; overflow-y: auto; font-size: 0.9em;">' +
                preview + (item.summary_text.length > 200 ? '...' : '') +
                '</div>' +
                '<button type="button" class="btn btn-sm btn-primary btn-insert-summary" data-summary-id="' + item.id + '">' +
                'この要約を追加</button>' +
                '</div></div>';
        }).join('');

        // 挿入ボタンにイベント登録
        list.querySelectorAll('.btn-insert-summary').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = parseInt(this.dataset.summaryId);
                insertSummary(id);
            });
        });
    }

    // 要約をテキストエリアに挿入
    function insertSummary(id) {
        const item = summaries.find(function(s) { return s.id === id; });
        if (item) {
            const textarea = document.getElementById('record_content');
            const current = textarea.value;
            textarea.value = current ? current + '\n\n' + item.summary_text : item.summary_text;
            summaryModal.hide();
        }
    }

    // HTMLエスケープ
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 日時フォーマット
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString('ja-JP');
    }
});
</script>
<script>
// 録音画面からの連携: クエリパラメータで渡された情報を自動入力
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);

    // audio_record_id がある場合、要約を取得してトレーニング記録欄に挿入
    var audioRecordId = params.get('audio_record_id');
    if (audioRecordId) {
        fetch('/api/audio-records/' + audioRecordId + '/summary', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.summary_text) {
                var textarea = document.getElementById('record_content');
                if (textarea && !textarea.value) {
                    textarea.value = data.summary_text;
                }
            }
        })
        .catch(function() { /* 要約取得失敗は無視 */ });
    }

    // training_date がある場合、日付を設定
    var trainingDate = params.get('training_date');
    if (trainingDate) {
        var dateInput = document.getElementById('training_date');
        if (dateInput) dateInput.value = trainingDate;
    }

    // trainer1_id がある場合、担当1を設定
    var trainer1Id = params.get('trainer1_id');
    if (trainer1Id) {
        var trainer1Select = document.getElementById('trainer1_id');
        if (trainer1Select) trainer1Select.value = trainer1Id;
    }

    // trainer2_id がある場合、担当2を設定
    var trainer2Id = params.get('trainer2_id');
    if (trainer2Id) {
        var trainer2Select = document.getElementById('trainer2_id');
        if (trainer2Select) trainer2Select.value = trainer2Id;
    }

});
</script>
{{-- メディアセクションの状態管理。
     init で初期メディアを反映（edit）または空配列で初期化（create）。
     add は登録/追加モーダル、remove/move はサムネ × と D&D で呼び出す。 --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    const gridRoot = document.getElementById('mediaSelectionGrid');
    const hiddenRoot = document.getElementById('mediaSelectionHiddenInputs');
    const emptyMessage = document.getElementById('mediaSelectionEmpty');
    if (!gridRoot || !hiddenRoot) return;

    // 動画サムネイルの中央にオーバーレイする▶（半透明の黒丸 + 白い三角）を返す。
    // CSS(.video-play-overlay-sm)は _video-play-overlay-styles.blade.php が
    // @@once @@push('styles') でページに載せる。Blade 側の
    // _video-play-overlay.blade.php と同じ意匠を JS DOM で組み立てたもの。
    function buildVideoPlayOverlaySvg() {
        const svgNs = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(svgNs, 'svg');
        svg.setAttribute('class', 'video-play-overlay-sm');
        svg.setAttribute('viewBox', '0 0 80 80');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        const circle = document.createElementNS(svgNs, 'circle');
        circle.setAttribute('cx', '40');
        circle.setAttribute('cy', '40');
        circle.setAttribute('r', '38');
        circle.setAttribute('fill', 'rgba(0,0,0,0.55)');
        const triangle = document.createElementNS(svgNs, 'polygon');
        triangle.setAttribute('points', '33,25 33,55 58,40');
        triangle.setAttribute('fill', '#fff');
        svg.appendChild(circle);
        svg.appendChild(triangle);
        return svg;
    }

    // 既存メディア一覧（index.blade.php）と同型のカード DOM を組み立てる。
    // 右上に × ボタンを重ねて紐づけを仮解除できるようにする（Step4解除）。
    // D&D ハンドル・再生は付けない（次段で追加）。
    function buildCard(item) {
        const col = document.createElement('div');
        col.className = 'col';

        const card = document.createElement('div');
        card.className = 'card h-100 media-card position-relative';
        card.dataset.mediaId = String(item.id);

        // 右上に × ボタン（紐づけ解除・仮状態）。
        // 設計書 S-0404「各サムネイル右上の×で紐づけを解除」。
        // type="button" は必須（指定しないと submit になりフォーム送信される）。
        const removeWrap = document.createElement('div');
        removeWrap.className = 'position-absolute top-0 end-0 m-1 bg-white rounded p-1';
        removeWrap.style.zIndex = '2';
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn-close media-remove-btn';
        removeBtn.setAttribute('aria-label', '紐づけ解除');
        removeBtn.dataset.mediaId = String(item.id);
        removeWrap.appendChild(removeBtn);
        card.appendChild(removeWrap);

        // 種別ラベル（img.alt に載せる。名前を出さない方針のため種別だけを入れる。
        // 設計書 S-0401 / S-0404 セクション2 メディア参照）
        const typeLabel = item.type === 'photo' ? '写真' : (item.type === 'video' ? '動画' : '');

        const ratio = document.createElement('div');
        ratio.className = 'ratio ratio-1x1 bg-light d-flex align-items-center justify-content-center';
        if (item.thumbnailUrl) {
            const img = document.createElement('img');
            img.src = item.thumbnailUrl;
            img.alt = typeLabel;
            img.className = 'img-fluid';
            ratio.appendChild(img);
            // 動画のときだけ中央に▶オーバーレイ（写真・プレースホルダには付けない）
            if (item.type === 'video') {
                ratio.appendChild(buildVideoPlayOverlaySvg());
            }
        } else {
            const span = document.createElement('span');
            span.className = 'text-muted';
            span.textContent = typeLabel;
            ratio.appendChild(span);
        }
        card.appendChild(ratio);

        // サムネイル下の表示名ラベルは出さない（設計書 S-0401 / S-0404）。
        // card-body ごと生成しない — カード内はサムネイル（ratio）のみで完結する。

        col.appendChild(card);
        return col;
    }

    const mediaSelection = {
        items: [],
        // D&D 用 Sortable インスタンス。グリッドが可視になった初回 render で生成して保持する
        sortable: null,

        init(initial) {
            this.items = Array.isArray(initial) ? initial.slice() : [];
            this.render();
        },
        add(media) {                     // 5c-2 で呼ぶ
            if (this.items.some(i => i.id === media.id)) return;
            this.items.push(media);
            window.unsavedChangesGuard?.markDirty();
            this.render();
        },
        remove(id) {                     // Step4 解除で呼ぶ
            this.items = this.items.filter(i => i.id !== id);
            window.unsavedChangesGuard?.markDirty();
            this.render();
        },
        move(from, to) {                 // Step4 並べ替えで呼ぶ
            const [it] = this.items.splice(from, 1);
            this.items.splice(to, 0, it);
            window.unsavedChangesGuard?.markDirty();
            this.render();
        },
        render() {
            // グリッド再構築
            gridRoot.innerHTML = '';
            this.items.forEach(item => gridRoot.appendChild(buildCard(item)));

            // hidden input 再構築（items 順 → PUT で配列順 → 5a が sort_order=index で採番）
            hiddenRoot.innerHTML = '';
            this.items.forEach(item => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'media_record_ids[]';
                inp.value = String(item.id);
                hiddenRoot.appendChild(inp);
            });

            if (emptyMessage) {
                emptyMessage.classList.toggle('d-none', this.items.length > 0);
            }
            // 空のときはグリッドも非表示にして .row の負マージンが空メッセージに食い込まないようにする
            gridRoot.classList.toggle('d-none', this.items.length === 0);

            // Sortable はカード1枚の状態で bind すると degraded モードで初期化され、
            // その後カードが増えてもネイティブ HTML5 drag にフォールバックして
            // 並び替えが効かなくなる（create で発生）。並び替えが意味を持つ2枚以上に
            // なってから bind することで、正規モードでの初期化を保証する。
            // 一度 bind すれば以降は container bind が維持され、innerHTML 再構築でも有効。
            // CDN 障害時の握り潰しのため typeof でガード。
            if (!this.sortable && this.items.length >= 2 && typeof Sortable !== 'undefined') {
                this.sortable = new Sortable(gridRoot, {
                    animation: 150,
                    ghostClass: 'media-card-ghost',
                    // × ボタン自身とその子（btn-close 内 SVG 等）をドラッグ対象から除外
                    filter: '.media-remove-btn, .media-remove-btn *',
                    // × の click（解除）に干渉しないよう preventDefault を抑止
                    preventOnFilter: false,
                    onEnd: function (evt) {
                        if (evt.oldIndex === evt.newIndex) return;
                        mediaSelection.move(evt.oldIndex, evt.newIndex);
                    },
                });
            }
        },
    };

    // 編集画面グリッドへのイベント委譲：× クリックで紐づけ解除（仮状態）。
    // render() で DOM が毎回作り直されても、リスナは gridRoot に1つ張るだけで全カードに有効。
    // 解除確定は [更新] 時（5a の総入れ替えで中間テーブルから消える）。
    gridRoot.addEventListener('click', function (e) {
        const btn = e.target.closest('.media-remove-btn');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const id = parseInt(btn.dataset.mediaId, 10);
        mediaSelection.remove(id);
    });

    // 5c-2 のモーダル callback / Step4 が触れるよう公開
    window.mediaSelection = mediaSelection;
    mediaSelection.init(@json($mediaInitial ?? []));

    // 段2 新規登録して追加 → メディア登録モーダル（S-1302-M02）を開く。
    // 完了後、登録メディアを mediaSelection.add で仮状態紐づけ（[更新]で確定）。
    // ※ partial の API は snake_case (thumbnail_url/conversion_status)、
    //   mediaSelection.items は camelCase。意図的にここで変換する。
    //   display_title は編集画面グリッドの buildCard で使わなくなったため
    //   items に含めない（設計書 S-0401 / S-0404 セクション2 メディア参照）。
    document.getElementById('mediaUploadOpenBtn')?.addEventListener('click', function () {
        window.mediaUploadModal.open({
            onComplete: function (registeredMedia) {
                registeredMedia.forEach(function (m) {
                    window.mediaSelection.add({
                        id: m.id,
                        type: m.type,
                        thumbnailUrl: m.thumbnail_url,
                        conversionStatus: m.conversion_status,
                    });
                });
            },
        });
    });

});
</script>

{{-- メディア追加モーダル制御（S-0404-M01）。
     available-media（5b）を fetch して候補を表示、チェック選択して mediaSelection.add で
     編集画面グリッドへ反映する。 --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    // edit では作成済み記録の id、create では null（登録画面ではクエリに含めない）
    const trainingRecordId = @json($record?->id);
    const defaultTrainerId = @json(auth()->id());
    const addBtn = document.getElementById('mediaAddBtn');
    const modalEl = document.getElementById('mediaAddModal');
    if (!addBtn || !modalEl) return;

    const modal = new bootstrap.Modal(modalEl);
    const trainerFilter = document.getElementById('mediaAddTrainerFilter');
    const loading = document.getElementById('mediaAddLoading');
    const errorEl = document.getElementById('mediaAddError');
    const empty = document.getElementById('mediaAddEmpty');
    const grid = document.getElementById('mediaAddGrid');
    const pagination = document.getElementById('mediaAddPagination');
    const confirmBtn = document.getElementById('mediaAddConfirmBtn');
    const selectedCountEl = document.getElementById('mediaAddSelectedCount');

    // モーダル内ローカルな選択状態（mediaSelection.items とは別物）。
    // ページ送り時はリセットされる（同一ページ内で選んで [追加] する運用）。
    const selectedIds = new Set();
    // 直近 fetch の data[]（確定時に id で拾って camelCase 変換するために保持）
    let currentPageData = [];

    // 5c-1 で disabled だった [追加] ボタンを活性化
    addBtn.disabled = false;

    // モーダルを開く: 選択リセット → フィルタを既定（ログイン中）に戻す → 1ページ目を読み込み
    addBtn.addEventListener('click', function () {
        selectedIds.clear();
        updateSelectedCount();
        trainerFilter.value = String(defaultTrainerId);
        loadPage(1);
        modal.show();
    });

    // フィルタ変更: 1ページ目に戻る（選択は loadPage 内でリセット）
    trainerFilter.addEventListener('change', function () {
        loadPage(1);
    });

    // ページ送り: 「«前」「次»」ボタンの data-page でジャンプ
    pagination.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-page]');
        if (btn) loadPage(parseInt(btn.dataset.page, 10));
    });

    // カードまたはチェックボックスクリックで選択トグル
    grid.addEventListener('click', function (e) {
        const card = e.target.closest('.media-card');
        if (!card) return;
        const id = parseInt(card.dataset.mediaId, 10);
        const cb = card.querySelector('input[type="checkbox"]');
        // チェックボックスを直接クリックしたときの二重トグルを避ける
        if (e.target !== cb) cb.checked = !cb.checked;
        if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
        card.classList.toggle('border-primary', cb.checked);
        updateSelectedCount();
    });

    // 確定 [追加]: 選択中メディアを mediaSelection.add に流す。
    // ※ available-media JSON は snake_case (thumbnail_url / conversion_status)、
    //    mediaSelection.items は camelCase (thumbnailUrl / conversionStatus) のため、
    //    API のキー名が異なる。意図的にここで変換する。
    //    display_title は編集画面グリッドの buildCard で使わなくなったため
    //    items に含めない（設計書 S-0401 / S-0404 セクション2 メディア参照）。
    confirmBtn.addEventListener('click', function () {
        currentPageData
            .filter(function (m) { return selectedIds.has(m.id); })
            .forEach(function (m) {
                window.mediaSelection.add({
                    id: m.id,
                    type: m.type,
                    thumbnailUrl: m.thumbnail_url,
                    conversionStatus: m.conversion_status,
                });
            });
        modal.hide();
    });

    function updateSelectedCount() {
        selectedCountEl.textContent = String(selectedIds.size);
        confirmBtn.disabled = selectedIds.size === 0;
    }

    async function loadPage(page) {
        // ページ送り・フィルタ変更で選択はリセット（仕様）
        selectedIds.clear();
        updateSelectedCount();

        loading.classList.remove('d-none');
        errorEl.classList.add('d-none');
        empty.classList.add('d-none');
        grid.innerHTML = '';
        pagination.classList.add('d-none');

        const params = new URLSearchParams({
            trainer_id: trainerFilter.value,
            page: String(page),
        });
        // create では trainingRecordId が null。クエリに含めず候補全件取得（除外なし）
        if (trainingRecordId !== null) {
            params.set('training_record_id', String(trainingRecordId));
        }
        try {
            const res = await fetch(
                '/api/training-records/available-media?' + params.toString(),
                { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
            );
            if (!res.ok) throw new Error('候補の取得に失敗しました');
            const body = await res.json();
            currentPageData = body.data || [];
            renderGrid(currentPageData);
            renderPagination(body.meta);
            if (currentPageData.length === 0 && body.meta && body.meta.current_page === 1) {
                empty.classList.remove('d-none');
            }
        } catch (e) {
            errorEl.textContent = e.message || '候補の取得に失敗しました';
            errorEl.classList.remove('d-none');
        } finally {
            loading.classList.add('d-none');
        }
    }

    function renderGrid(items) {
        grid.innerHTML = '';
        // 日付グループ見出し（設計書 S-0401-M02 / S-0404-M01 参照）。
        // items は API から登録日時降順で来ており、日付が変わるところに Y/m/d の
        // 見出しを 1 行フル幅（col-12）で挟む。ページ跨ぎで同じ日付の見出しが
        // 複数ページに出るのは許容仕様（各ページ先頭で必ず見出しが出る）。
        // 日付の取り出しは split(' ')[0] を使う：
        //   - created_at はサーバ側で 'Y/m/d H:i' 形式（例：'2026/09/16 12:37'）
        //   - substring(0, 10) はフォーマット依存でフォーマット変更時に静かに壊れる
        //   - split(' ')[0] は「時刻の手前が日付」の意図がコードで読み取りやすく、
        //     時刻の桁数が変わっても、時刻が抜けたフォーマットにも対応できる
        var prevDateKey = null;
        items.forEach(function (m) {
            var dateKey = (m.created_at || '').split(' ')[0];
            if (dateKey !== prevDateKey) {
                var headerCol = document.createElement('div');
                // 一覧側（media-records/index.blade.php）と完全一致。
                // col-12 は左右パディング（ガター）を維持するため、幅は w-100 で確定させる
                // （md 以上で .row-cols-md-* > * に負けないよう !important で強制）。
                headerCol.className = 'col-12 w-100';
                var h6 = document.createElement('h6');
                h6.className = 'text-muted mb-0';
                h6.textContent = dateKey;
                headerCol.appendChild(h6);
                grid.appendChild(headerCol);
                prevDateKey = dateKey;
            }
            grid.appendChild(buildModalCard(m));
        });
    }

    // 動画サムネイルの中央にオーバーレイする▶（半透明の黒丸 + 白い三角）を返す。
    // 上段の buildCard 側（別 DOMContentLoaded スコープ）と同じヘルパの複製。
    // CSS(.video-play-overlay-sm)は _video-play-overlay-styles.blade.php が
    // @@once @@push('styles') でページに載せる。
    function buildVideoPlayOverlaySvg() {
        const svgNs = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(svgNs, 'svg');
        svg.setAttribute('class', 'video-play-overlay-sm');
        svg.setAttribute('viewBox', '0 0 80 80');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        const circle = document.createElementNS(svgNs, 'circle');
        circle.setAttribute('cx', '40');
        circle.setAttribute('cy', '40');
        circle.setAttribute('r', '38');
        circle.setAttribute('fill', 'rgba(0,0,0,0.55)');
        const triangle = document.createElementNS(svgNs, 'polygon');
        triangle.setAttribute('points', '33,25 33,55 58,40');
        triangle.setAttribute('fill', '#fff');
        svg.appendChild(circle);
        svg.appendChild(triangle);
        return svg;
    }

    // 5c-1 buildCard と同じカード構造に、右上のチェックボックスを重ねる。
    // ×・D&D・再生は付けない（モーダルでは候補選択だけが目的）。
    function buildModalCard(m) {
        const col = document.createElement('div');
        col.className = 'col';

        const card = document.createElement('div');
        card.className = 'card h-100 media-card position-relative';
        card.dataset.mediaId = String(m.id);
        card.style.cursor = 'pointer';

        // チェックボックス（右上、白背景で視認性確保。クリックでカードと同じトグル）
        const checkWrap = document.createElement('div');
        checkWrap.className = 'form-check position-absolute top-0 end-0 m-1 bg-white rounded p-1';
        checkWrap.style.zIndex = '2';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'form-check-input m-0';
        cb.value = String(m.id);
        cb.setAttribute('aria-label', '選択');
        checkWrap.appendChild(cb);
        card.appendChild(checkWrap);

        // サムネイル or フォールバック
        const ratio = document.createElement('div');
        ratio.className = 'ratio ratio-1x1 bg-light d-flex align-items-center justify-content-center';
        if (m.thumbnail_url) {
            const img = document.createElement('img');
            img.src = m.thumbnail_url;
            img.alt = m.display_title || '';
            img.className = 'img-fluid';
            ratio.appendChild(img);
            // 動画のときだけ中央に▶オーバーレイ（写真・プレースホルダには付けない）
            if (m.type === 'video') {
                ratio.appendChild(buildVideoPlayOverlaySvg());
            }
        } else {
            const span = document.createElement('span');
            span.className = 'text-muted';
            span.textContent = m.type === 'photo' ? '写真' : (m.type === 'video' ? '動画' : '');
            ratio.appendChild(span);
        }
        card.appendChild(ratio);

        // 表示名
        const body = document.createElement('div');
        body.className = 'card-body p-2 small';
        const titleDiv = document.createElement('div');
        titleDiv.className = 'text-truncate';
        titleDiv.title = m.display_title || '';
        titleDiv.textContent = m.display_title || '';
        body.appendChild(titleDiv);
        card.appendChild(body);

        col.appendChild(card);
        return col;
    }

    function renderPagination(meta) {
        const ul = pagination.querySelector('ul');
        ul.innerHTML = '';
        if (!meta || meta.last_page <= 1) return;

        function mk(label, page, disabled, active) {
            const li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'page-link';
            btn.textContent = label;
            if (page != null && !disabled && !active) btn.dataset.page = String(page);
            li.appendChild(btn);
            return li;
        }

        ul.appendChild(mk('«前', meta.current_page - 1, meta.current_page <= 1, false));
        ul.appendChild(mk(meta.current_page + ' / ' + meta.last_page, null, false, true));
        ul.appendChild(mk('次»', meta.current_page + 1, meta.current_page >= meta.last_page, false));
        pagination.classList.remove('d-none');
    }
});
</script>
@endpush
