@extends('layouts.app')

@section('title', 'メディア一覧')

@section('content')
<div class="container">
    <h2 class="mb-3">メディア一覧</h2>

    {{-- 登録者フィルタ＋新規登録ボタン --}}
    <div class="d-flex align-items-center gap-2 mb-4">
        <label for="trainer-filter" class="form-label mb-0 text-nowrap">登録者:</label>
        <select id="trainer-filter" class="form-select" style="width: 200px;">
            <option value="all" {{ $selectedTrainerId == 'all' ? 'selected' : '' }}>全員</option>
            @foreach($trainers as $trainer)
                <option value="{{ $trainer->id }}" {{ $selectedTrainerId == $trainer->id ? 'selected' : '' }}>
                    {{ $trainer->name }}
                </option>
            @endforeach
        </select>
        <button type="button" class="btn btn-primary ms-auto" id="mediaUploadOpenBtn">新規登録</button>
    </div>

    {{-- メディアグリッド --}}
    @if($mediaRecords->isEmpty())
        <div class="alert alert-info">
            データがありません。メディア登録から追加してください。
        </div>
    @else
        {{-- レスポンシブグリッド（2列〜6列）。
             サムネイル生成済み（thumbnail_url あり）なら .ratio 内に <img>、
             それ以外（未生成・生成中・失敗・3b-2 未対応の動画）は今まで通りプレースホルダ表示。

             日付グループ見出し：登録日時の日付が変わるところに Y/m/d の見出しを 1 行フル幅で挿入する
             （設計書 S-1302 メディア一覧参照）。実装は Paginator の getCollection() を groupBy して
             2 段の @foreach にする。$mediaRecords は Paginator のまま維持し、下の $mediaRecords->links()
             を無傷にする。@php ディレクティブは一切使わない（既存 @php ... @endphp ブロックの直後に
             インライン @php(...) を置くとパースが破綻する事故が過去にあったため排除）。 --}}
        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-6 g-3">
            @foreach($mediaRecords->getCollection()->groupBy(fn($m) => $m->created_at->format('Y/m/d')) as $dateKey => $items)
                {{-- 見出しは 1 行フル幅で占有する。col-12 は左右パディング（ガター）を維持する
                     ためで、幅は w-100 で確定させる。col-12 単体では md 以上のブレークポイントで
                     .row-cols-md-* > *（メディアクエリ内・後定義）に負けて 1 カラム分の幅になる
                     ため、.w-100 の !important で強制上書きする。 --}}
                <div class="col-12 w-100">
                    <h6 class="text-muted mb-0">{{ $dateKey }}</h6>
                </div>
                @foreach($items as $media)
                    <div class="col">
                        <div class="card h-100 media-card" data-media-id="{{ $media->id }}" style="cursor: pointer;" role="button" tabindex="0">
                            <div class="ratio ratio-1x1 bg-light d-flex align-items-center justify-content-center">
                                @if($mediaModalData[$media->id]['thumbnail_url'] ?? null)
                                    <img src="{{ $mediaModalData[$media->id]['thumbnail_url'] }}" alt="{{ $media->display_title }}" class="img-fluid">
                                    {{-- 動画のときだけ中央に▶をオーバーレイ（写真・プレースホルダには出さない） --}}
                                    @if($media->type === \App\Models\MediaRecord::TYPE_VIDEO)
                                        @include('media-records._video-play-overlay')
                                    @endif
                                @else
                                    <span class="text-muted">
                                        {{ $media->type === \App\Models\MediaRecord::TYPE_PHOTO ? '写真' : '動画' }}
                                    </span>
                                @endif
                            </div>
                            <div class="card-body p-2 small">
                                {{-- 時刻のみ（日付は上の日付見出しに集約） --}}
                                <div class="text-muted">{{ $media->created_at->format('H:i') }}</div>
                                <div class="text-truncate" title="{{ $media->display_title }}">@if(empty($media->title))({{ $media->display_title }})@else{{ $media->display_title }}@endif</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>

        <div class="mt-3">
            {{ $mediaRecords->links() }}
        </div>
    @endif
</div>

{{-- 詳細モーダル（S-1302-M01） --}}
<div class="modal fade" id="mediaDetailModal" tabindex="-1" aria-labelledby="mediaDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            {{-- ヘッダー右側に主要アクション（更新・削除）+ × を集約。
                 システム他画面の流儀（タイトル左 / 操作ボタン右 / 削除は最右） に揃え、
                 × と重複していた「閉じる」ボタンは廃止した。 --}}
            <div class="modal-header">
                <h5 class="modal-title" id="mediaDetailModalLabel">メディア詳細</h5>
                {{-- ms-auto で右端に押し出す。Bootstrap の .modal-header は justify-content:space-between だが、
                     本来は .btn-close 単独に margin-left:auto を当てる前提のため、btn-close を div でまとめたケースでは
                     ms-auto を明示する方が確実。 --}}
                <div class="d-flex gap-2 align-items-center ms-auto">
                    <button type="button" class="btn btn-success" id="mediaUpdateBtn">更新</button>
                    <button type="button" class="btn btn-danger" id="mediaDeleteBtn">削除</button>
                    <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
            </div>
            <div class="modal-body">
                {{-- 非同期の保存の入力エラー以外の失敗（500・通信の失敗など）を alert alert-danger で出す枠。
                     中身は共通 JS `FormErrors.showFormMessage` が作る（設計書 §2-7「非同期の保存（fetch）」） --}}
                <div id="mediaDetailFormError"></div>
                {{-- 左右2カラム（md 未満では Bootstrap の挙動で自動的に縦積み） --}}
                <div class="row g-3">
                    {{-- 左カラム：メディア表示エリア（JSで img / video / 非対応メッセージを差し込む） --}}
                    <div class="col-md-7">
                        <div id="mediaDisplayArea" class="text-center" style="min-height: 200px;"></div>
                    </div>

                    {{-- 右カラム：メタ情報 --}}
                    <div class="col-md-5">
                        {{-- メタ情報（表示のみ / 編集可能の混在）--}}
                        {{-- align-items-center で、入力欄のある行（表示名）の dt を dd の縦中央に揃える --}}
                        <dl class="row mb-0 small align-items-center">
                            <dt class="col-sm-3">登録日時</dt>
                            <dd class="col-sm-9" id="mediaMetaCreatedAt"></dd>

                            <dt class="col-sm-3">表示名</dt>
                            <dd class="col-sm-9">
                                {{-- name="title" は共通 JS FormErrors が [name="title"] で欄を見つけるため
                                     （詳細モーダルは fetch 送信のため name 属性は送信に使わないが、エラーの
                                     対象を特定する手がかりとして付ける。§2-7「非同期の保存（fetch）」） --}}
                                <input type="text" id="mediaEditTitle" name="title" class="form-control form-control-sm" maxlength="255" placeholder="未入力時は元ファイル名を表示">
                            </dd>

                            <dt class="col-sm-3">元ファイル名</dt>
                            <dd class="col-sm-9" id="mediaMetaOriginalFilename"></dd>

                            <dt class="col-sm-3">種別</dt>
                            <dd class="col-sm-9" id="mediaMetaType"></dd>

                            <dt class="col-sm-3">登録者</dt>
                            <dd class="col-sm-9" id="mediaMetaTrainer"></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- メディア原寸ライトボックス（自前オーバーレイ・S-1302-M01 拡張） --}}
<div id="mediaLightbox" class="media-lightbox" hidden>
    <button type="button" class="media-lightbox-close" id="mediaLightboxClose" aria-label="閉じる">&times;</button>
    <div class="media-lightbox-content" id="mediaLightboxContent"></div>
</div>

{{-- メディア登録モーダル（S-1302-M02）。再利用部品。 --}}
@include('media-records._upload-modal')
@endsection

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<style>
    /* 詳細モーダルの画像/動画の高さを制限。
       縦長メディアでも右カラムの情報・ボタンが画面外に追い出されないようにする。 */
    #mediaDisplayArea img,
    #mediaDisplayArea video {
        max-height: 70vh;
        object-fit: contain;
    }
    /* 写真は「クリックで拡大」を示唆 */
    #mediaDisplayArea img {
        cursor: zoom-in;
    }
    /* 動画プレビューは「クリックで再生（ライトボックスを開く）」を示唆 */
    #mediaDisplayArea .video-preview-wrapper {
        cursor: pointer;
    }

    /* 動画プレビュー（詳細モーダル内）：ファーストフレーム + 中央に再生アイコン */
    .video-preview-wrapper {
        position: relative;
        display: inline-block;
        line-height: 0; /* video 下の隙間を消す */
    }
    .video-play-overlay {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 5rem;
        height: 5rem;
        pointer-events: none; /* クリックは video（→ライトボックス起動）に届くようにする */
    }

    /* 原寸ライトボックス（自前オーバーレイ） */
    .media-lightbox {
        position: fixed;
        inset: 0;
        background-color: rgba(0, 0, 0, 0.88);
        z-index: 1080; /* Bootstrap modal(1055) より上 */
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        cursor: zoom-out; /* 背景クリックで閉じる示唆 */
    }
    .media-lightbox[hidden] {
        display: none;
    }
    .media-lightbox-content {
        cursor: default; /* メディア本体クリックでは閉じない */
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .media-lightbox-content img,
    .media-lightbox-content video {
        max-width: 95vw;
        max-height: 95vh;
        object-fit: contain;
        display: block;
    }
    .media-lightbox-close {
        position: absolute;
        top: 1rem;
        right: 1rem;
        width: 3rem;
        height: 3rem;
        border: 0;
        border-radius: 50%;
        background-color: rgba(0, 0, 0, 0.55);
        color: #fff;
        font-size: 1.75rem;
        line-height: 1;
        cursor: pointer;
        z-index: 1; /* オーバーレイ内で最前面 */
    }
    .media-lightbox-close:hover {
        background-color: rgba(0, 0, 0, 0.85);
    }

    /* 写真の「全体表示↔原寸表示」トグル。
       原寸時は overflow:auto でスクロール可能にし、content に margin:auto を当てる。
       中央寄せに align-items/justify-content を使うと、画像が画面より大きいときに
       上端が切れて scroll で到達できない flex の罠が起きる。
       margin:auto は余り領域が無くなれば 0 になる（負にならない）ため、
       小さい画像は中央・大きい画像は上端から配置されてスクロールで全範囲到達できる。 */
    .media-lightbox:not(.is-actual-size) .media-lightbox-content img {
        cursor: zoom-in;
    }
    .media-lightbox.is-actual-size {
        overflow: auto;
    }
    .media-lightbox.is-actual-size .media-lightbox-content {
        margin: auto;
    }
    .media-lightbox.is-actual-size .media-lightbox-content img {
        max-width: none;
        max-height: none;
        width: auto;
        height: auto;
        cursor: zoom-out;
    }

    /* 詳細モーダル右カラムの dl 各行の高さを揃える。
       入力欄の行（表示名 input ≒ 31px）と
       テキストのみの行（≒ 21px）で高さがバラついていたのを統一し、
       既存の align-items-center と組み合わせてラベル(dt)が縦に等間隔に並ぶようにする。
       sm 以上で適用（縦積み時は dt/dd が独立行になり、min-height を効かせると
       テキスト行の dd 枠に無駄な空白が出るため無効化する）。 */
    @media (min-width: 576px) {
        #mediaDetailModal .modal-body dl.row > dt,
        #mediaDetailModal .modal-body dl.row > dd {
            min-height: 2.5rem;
        }
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
{{-- 非同期の保存の入力エラー・入力エラー以外の失敗を欄の下・モーダルの先頭に出す共通 JS
     （設計書 §2-7「非同期の保存（fetch）」。window.FormErrors に関数が載る） --}}
@vite(['resources/js/form-errors.js'])
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 登録者フィルタの変更で trainer_id クエリを差し替えて再読み込み（ページはリセット）
    const trainerFilter = document.getElementById('trainer-filter');
    if (trainerFilter) {
        trainerFilter.addEventListener('change', function() {
            const value = this.value;
            const url = new URL(window.location.href);
            url.searchParams.delete('page');
            url.searchParams.set('trainer_id', value);
            window.location.href = url.toString();
        });
    }

    // 新規登録ボタン → メディア登録モーダル（S-1302-M02）を開く。
    // 完了後は location.reload で一覧を更新する（段1）。
    document.getElementById('mediaUploadOpenBtn')?.addEventListener('click', function () {
        window.mediaUploadModal.open({
            onComplete: () => window.location.reload(),
        });
    });

    // 詳細モーダル用データ（id → メタ情報辞書）
    const mediaModalData = @json($mediaModalData ?? new \stdClass());

    const modalEl = document.getElementById('mediaDetailModal');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);
    const displayArea = document.getElementById('mediaDisplayArea');
    const metaCreatedAt = document.getElementById('mediaMetaCreatedAt');
    const metaType = document.getElementById('mediaMetaType');
    const metaOriginalFilename = document.getElementById('mediaMetaOriginalFilename');
    const metaTrainer = document.getElementById('mediaMetaTrainer');
    const editTitle = document.getElementById('mediaEditTitle');
    const updateBtn = document.getElementById('mediaUpdateBtn');
    const deleteBtn = document.getElementById('mediaDeleteBtn');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // 編集中のメディアID（モーダル close 時にクリア）
    let currentMediaId = null;

    // メディア表示エリアに alert メッセージを差し込む
    function setDisplayAlert(text, level) {
        displayArea.innerHTML = '';
        const div = document.createElement('div');
        div.className = 'alert alert-' + level + ' mb-0';
        div.textContent = text;
        displayArea.appendChild(div);
    }

    // カードクリック → メタ情報セット → モーダル表示 → play fetch → メディア差し込み
    document.querySelectorAll('.media-card').forEach(function(card) {
        card.addEventListener('click', function() {
            const id = this.dataset.mediaId;
            const meta = mediaModalData[id];
            if (!meta) return;

            currentMediaId = id;

            // 表示のみ項目（XSS回避のため textContent）
            metaCreatedAt.textContent = meta.created_at || '';
            metaType.textContent = meta.type === 'photo' ? '写真' : (meta.type === 'video' ? '動画' : meta.type);
            metaOriginalFilename.textContent = meta.original_filename || '';
            metaTrainer.textContent = meta.trainer_name || '（削除済み）';

            // 編集可能項目（input の値をセット）
            editTitle.value = meta.title_raw || '';

            // 一旦「読み込み中」を出してからモーダルを開く
            setDisplayAlert('読み込み中…', 'secondary');
            modal.show();

            // 表示可否は conversion_status で判定する：
            //   not_required（jpeg/png/mp4 など原本がそのまま表示可能）
            //   done（変換済み・display_path に変換後ファイルがセット済み）
            // これ以外（pending/processing/error）は display_path が未確定で
            // play が 409 を返すため、呼ぶ前に状態併記の文言を出す。
            const canDisplay =
                meta.conversion_status === 'not_required' ||
                meta.conversion_status === 'done';
            if (!canDisplay) {
                setDisplayAlert(
                    '現在このメディアは表示できません（変換状態: ' + meta.conversion_status + '）。',
                    'warning'
                );
                return;
            }

            // play で presigned GET URL を取得
            fetch('/api/media-records/' + encodeURIComponent(id) + '/play', {
                headers: { 'Accept': 'application/json' }
            })
            .then(function(res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function(body) {
                const url = body.data && body.data.url;
                if (!url) { throw new Error('URL欠落'); }

                displayArea.innerHTML = '';
                if (meta.type === 'photo') {
                    const img = document.createElement('img');
                    img.src = url;
                    img.alt = meta.display_title || '';
                    img.className = 'img-fluid';
                    displayArea.appendChild(img);
                } else {
                    // 動画は wrapper + video + 再生アイコン SVG。
                    // controls は外し、クリックで原寸ライトボックスに引き渡す（写真と操作を統一）。
                    // src 末尾に #t=0.1 を付け、Safari 等で黒画面になりがちな
                    // preload="metadata" でもファーストフレームが描画されるようにする
                    // （# 以降はサーバに送信されないため presigned URL の署名には影響しない）。
                    const wrapper = document.createElement('div');
                    wrapper.className = 'video-preview-wrapper';

                    const video = document.createElement('video');
                    video.src = url + '#t=0.1';
                    video.preload = 'metadata';
                    video.muted = true;
                    video.playsInline = true;
                    video.className = 'img-fluid';
                    wrapper.appendChild(video);

                    // 中央に「半透明の丸 + 白い▶」の再生アイコン
                    const svgNs = 'http://www.w3.org/2000/svg';
                    const svg = document.createElementNS(svgNs, 'svg');
                    svg.setAttribute('class', 'video-play-overlay');
                    svg.setAttribute('viewBox', '0 0 80 80');
                    svg.setAttribute('aria-hidden', 'true');
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
                    wrapper.appendChild(svg);

                    displayArea.appendChild(wrapper);
                }
            })
            .catch(function(e) {
                console.error(e);
                setDisplayAlert('メディアの取得に失敗しました。', 'danger');
            });
        });
    });

    // モーダルの詳細エリアと表示名の入力欄にエラー表示を出すときの container（§2-7「非同期の保存（fetch）」）
    const detailModalBody = modalEl.querySelector('.modal-body');
    const detailFormErrorEl = document.getElementById('mediaDetailFormError');

    function clearModalErrors() {
        if (window.FormErrors) {
            window.FormErrors.clearFieldErrors(detailModalBody);
            window.FormErrors.clearFormMessage(detailFormErrorEl);
        }
    }

    // モーダルclose時にクリーンアップ（動画停止・次回ちらつき防止・編集状態クリア）。
    // 開き直したときに前のエラーの表示が残らないよう、エラー表示も消す（§2-7。段階 5-3a）
    modalEl.addEventListener('hidden.bs.modal', function() {
        const video = displayArea.querySelector('video');
        if (video) {
            video.pause();
            video.removeAttribute('src');
            video.load();
        }
        displayArea.innerHTML = '';
        currentMediaId = null;
        editTitle.value = '';
        clearModalErrors();
    });

    // 更新ボタン: title を PUT（§2-7「非同期の保存（fetch）」。段階 5-3a で alert を欄の下・モーダル先頭に移した）
    updateBtn.addEventListener('click', async function() {
        if (!currentMediaId) return;
        const title = editTitle.value.trim();

        // 新しい保存を始めるので、前のエラーの表示を消す
        clearModalErrors();
        updateBtn.disabled = true;
        try {
            const res = await fetch('/media-records/' + encodeURIComponent(currentMediaId), {
                method: 'PUT',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    title: title || null,
                }),
            });
            if (res.ok) {
                modal.hide();
                window.location.reload();
                return;
            }
            if (res.status === 422) {
                const body = await res.json();
                // 入力エラーは欄の下に出す（表示名の欄）。欄が見つからないキーはモーダル先頭に回す
                const orphans = window.FormErrors.showFieldErrors(detailModalBody, body && body.errors ? body.errors : {});
                if (orphans.length > 0) {
                    window.FormErrors.showFormMessage(detailFormErrorEl, orphans.join('\n'));
                }
                return;
            }
            // 500・その他：JSON が読めるときは中身を使い、読めないときは固定文言
            let message = '更新に失敗しました。';
            try {
                const body = await res.json();
                if (body && body.message) message = body.message;
                else if (body && body.error && body.error.message) message = body.error.message;
            } catch (e) { /* ignore */ }
            window.FormErrors.showFormMessage(detailFormErrorEl, message);
        } catch (e) {
            console.error(e);
            // 通信の失敗など
            window.FormErrors.showFormMessage(detailFormErrorEl, '更新に失敗しました。');
        } finally {
            updateBtn.disabled = false;
        }
    });

    // 原寸ライトボックス：詳細モーダルの画像/動画クリックで開く（自前オーバーレイ）
    const lightboxEl = document.getElementById('mediaLightbox');
    const lightboxContent = document.getElementById('mediaLightboxContent');
    const lightboxCloseBtn = document.getElementById('mediaLightboxClose');

    function openLightbox(tagName, srcUrl, altText) {
        lightboxContent.innerHTML = '';
        lightboxEl.classList.remove('is-actual-size'); // 必ず全体表示から始める
        if (tagName === 'IMG') {
            const img = document.createElement('img');
            img.src = srcUrl;
            img.alt = altText || '';
            lightboxContent.appendChild(img);
        } else {
            // ライトボックスを開いた直後は再生せず、停止状態（ファーストフレーム表示）で待機。
            // srcUrl には詳細モーダル側で付けた #t=0.1 が含まれているのでそのまま使う。
            // これにより preload="metadata" でも Safari 等で黒画面にならずファーストフレームが描画される。
            // ユーザーが controls の再生ボタンを押すと 0.1 秒地点から再生開始。
            const video = document.createElement('video');
            video.src = srcUrl;
            video.controls = true;
            video.preload = 'metadata';
            video.playsInline = true;
            lightboxContent.appendChild(video);
        }
        lightboxEl.removeAttribute('hidden');
    }

    function closeLightbox() {
        if (lightboxEl.hasAttribute('hidden')) return;
        const video = lightboxContent.querySelector('video');
        if (video) {
            video.pause();
            video.removeAttribute('src');
            video.load();
        }
        lightboxContent.innerHTML = '';
        lightboxEl.classList.remove('is-actual-size'); // 次回オープン時に持ち越さない
        lightboxEl.setAttribute('hidden', '');
    }

    // 写真のみ：クリックで全体表示↔原寸表示をトグル。
    // 原寸に切り替えた瞬間、写真の中央が画面の中央に来るようスクロール位置を初期化する。
    function toggleActualSize() {
        const img = lightboxContent.querySelector('img');
        if (!img) return; // 動画は対象外
        const nowActual = lightboxEl.classList.toggle('is-actual-size');
        if (nowActual) {
            requestAnimationFrame(function() {
                lightboxEl.scrollLeft = (lightboxEl.scrollWidth - lightboxEl.clientWidth) / 2;
                lightboxEl.scrollTop = (lightboxEl.scrollHeight - lightboxEl.clientHeight) / 2;
            });
        }
    }

    // 詳細モーダルの mediaDisplayArea にイベント委譲。
    // 写真は img を直接クリック。動画は wrapper 内の video をクリック
    // （再生アイコン SVG は pointer-events:none なのでクリックは video に届く）。
    displayArea.addEventListener('click', function(e) {
        const target = e.target;
        if (target.tagName === 'IMG') {
            openLightbox('IMG', target.src, target.alt);
        } else if (target.tagName === 'VIDEO') {
            openLightbox('VIDEO', target.src, '');
        }
    });

    // 背景クリックで閉じる（メディア本体クリックは content の cursor:default + ここで弾く）
    lightboxEl.addEventListener('click', function(e) {
        if (e.target === lightboxEl) closeLightbox();
    });
    lightboxCloseBtn.addEventListener('click', closeLightbox);

    // ライトボックス内の写真クリックで全体↔原寸トグル（動画は無反応）
    lightboxContent.addEventListener('click', function(e) {
        if (e.target.tagName === 'IMG') toggleActualSize();
    });

    // Esc キーで閉じる。Bootstrap modal も Esc で閉じるため、
    // capture phase で先取りして stopPropagation し、詳細モーダルまで閉じないようにする。
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !lightboxEl.hasAttribute('hidden')) {
            e.stopPropagation();
            closeLightbox();
        }
    }, true);

    // 詳細モーダル自体が閉じられたときも、念のためライトボックスを閉じてクリーンアップ
    modalEl.addEventListener('hidden.bs.modal', function() {
        closeLightbox();
    });

    // 削除ボタン: 確認 → DELETE（成功時 reload）
    deleteBtn.addEventListener('click', async function() {
        if (!currentMediaId) return;
        if (!confirm('このメディアを削除します。レコードとストレージ上のファイルがともに削除され、元に戻せません。よろしいですか?')) {
            return;
        }
        // 新しい削除を始めるので、前のエラーの表示を消す
        clearModalErrors();
        deleteBtn.disabled = true;
        try {
            const res = await fetch('/media-records/' + encodeURIComponent(currentMediaId), {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            });
            if (res.ok) {
                modal.hide();
                window.location.reload();
                return;
            }
            // 削除の失敗は入力エラーではないため、モーダルの先頭に出す（§2-7「非同期の保存（fetch）」。段階 5-3a）
            let message = '削除に失敗しました。';
            try {
                const body = await res.json();
                if (body && body.message) message = body.message;
                else if (body && body.error && body.error.message) message = body.error.message;
            } catch (e) { /* ignore */ }
            window.FormErrors.showFormMessage(detailFormErrorEl, message);
        } catch (e) {
            console.error(e);
            window.FormErrors.showFormMessage(detailFormErrorEl, '削除に失敗しました。');
        } finally {
            deleteBtn.disabled = false;
        }
    });
});
</script>
@endpush
