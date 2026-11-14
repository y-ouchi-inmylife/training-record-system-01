{{-- トレーナー用写真の選択モーダル（S-0309-M02。2026-11 追加。要件定義書 6-16-20）。
     写真の枠の右上に重ねた［写真］のボタン（data-trainer-photo-open）で開く。メディア一覧（ライブラリ）から
     写真を 1 枚だけ選び、［更新］で POST /trainees/{trainee}/trainer-photo に送る（通常の form の送信）。
     トレーナー用写真を設定済み（$hasTrainerPhoto）のときだけ、フッターの左に［外す］を出す（2026-11 変更：当初は
     写真の枠の下に置いていた）。外すフォーム（DELETE）は選ぶフォーム（POST）と入れ子にできないため、モーダルの外に置き、
     ［外す］のボタンを form 属性で結ぶ。

     見た目はメディア追加モーダル（S-0401-M02 / S-0404-M01。training-records/_form.blade.php の #mediaAddModal）に
     そろえる。JS は記録のフォームと一体なので共通化せず、次を複製して 1 枚だけ選ぶ形に変えた：
       - 登録者の絞り込み・ページ送り（loadPage / renderPagination）
       - 日付見出し（formatDateHeading と、見出しのクラスの組み合わせ col-12 w-100〔2 つ目以降は pt-3〕
         と h6.fw-bold.mb-0.pb-1.border-bottom。メディア一覧 media-records/index.blade.php とも同じ）
       - カード（buildModalCard。チェックボックスは置かず、選んだカードの枠を青くする）
     候補は GET /api/training-records/available-media?type=photo（写真で、表示用の画像があるものだけ）。

     エラーで戻ったとき（入力エラー media_record_id・変換や保存の失敗 form）は、hidden の _trainer_photo_modal が
     old() に残っているので、このモーダルを開き直してエラーを出す（計測値モーダルの _trainee_id と同じ考え方）。
     モーダルなので上部の案内（x-form-error-summary）は出さない（設計書 §2-7「モーダルの中のフォーム」）。 --}}
<div class="modal fade" id="trainerPhotoModal" tabindex="-1" aria-labelledby="trainerPhotoModalLabel" aria-hidden="true"
     data-reopen="{{ old('_trainer_photo_modal') && ($errors->has('media_record_id') || $errors->has('form')) ? '1' : '0' }}">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="{{ route('trainee-trainer-photo.store', $trainee) }}" class="modal-content" novalidate
              id="trainerPhotoForm">
            @csrf
            <input type="hidden" name="_trainer_photo_modal" value="1">
            <input type="hidden" name="media_record_id" id="trainerPhotoMediaId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="trainerPhotoModalLabel">トレーナー用写真を選ぶ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                {{-- 入力エラー以外の失敗（変換・保存の失敗）は form のキーで本文の上に出す（§2-7） --}}
                @if(old('_trainer_photo_modal'))
                    @error('form')
                        <div class="alert alert-danger">{{ $message }}</div>
                    @enderror
                @endif
                <div class="d-flex align-items-center gap-2 mb-3">
                    <label for="trainerPhotoTrainerFilter" class="form-label mb-0 text-nowrap">登録者:</label>
                    <select id="trainerPhotoTrainerFilter" class="form-select" style="width: auto;">
                        <option value="all">全員</option>
                        @foreach($trainers as $t)
                            <option value="{{ $t->id }}" {{ $t->id === auth()->id() ? 'selected' : '' }}>
                                {{ $t->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div id="trainerPhotoLoading" class="text-center d-none">
                    <div class="spinner-border" role="status"><span class="visually-hidden">読み込み中...</span></div>
                </div>
                <div id="trainerPhotoError" class="alert alert-danger d-none"></div>
                <div id="trainerPhotoEmpty" class="text-muted small d-none">選べる写真がありません</div>
                <div id="trainerPhotoGrid" class="row row-cols-2 row-cols-md-4 row-cols-xl-6 g-3"></div>
                {{-- 入力エラー（未選択・使えないメディア）はグリッドの下に出す（§2-7。欄の下に相当） --}}
                @if(old('_trainer_photo_modal'))
                    <x-form-error field="media_record_id" block />
                @endif
                <nav id="trainerPhotoPagination" class="mt-3 d-none">
                    <ul class="pagination pagination-sm justify-content-center mb-0"></ul>
                </nav>
            </div>
            <div class="modal-footer">
                @if($hasTrainerPhoto ?? false)
                    {{-- 設定済みのときだけ左に［外す］。モーダルの中の削除なので白抜きの赤（設計書 4-4）。
                         このモーダルのフォーム（POST）ではなく、外のフォーム（DELETE）を送る --}}
                    <button type="submit" form="trainerPhotoRemoveForm" class="btn btn-outline-danger me-auto" id="trainerPhotoRemoveBtn">外す</button>
                @endif
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                {{-- 保存の操作なので緑（設計書 4-4「データ保存」。ほかの画面の［更新］と同じ btn-success。2026-11 変更：当初の名前は［設定する］）。
                     写真を選ぶまでは押せない --}}
                <button type="submit" class="btn btn-success" id="trainerPhotoSubmitBtn" disabled>更新</button>
            </div>
        </form>
    </div>
</div>
@if($hasTrainerPhoto ?? false)
    {{-- トレーナー用写真を外すフォーム（DELETE）。選ぶフォームの外に置き、モーダルのフッターの［外す］（form="trainerPhotoRemoveForm"）で送る。
         送る前に確認ダイアログを出す（設計書 S-0309-M02「外す」） --}}
    <form method="POST" action="{{ route('trainee-trainer-photo.destroy', $trainee) }}" id="trainerPhotoRemoveForm" class="d-none"
          onsubmit="return confirm('トレーナー用写真を外しますか？（会員写真と、選んだもとのメディアはそのまま残ります）');">
        @csrf
        @method('DELETE')
    </form>
@endif

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const defaultTrainerId = @json(auth()->id());
    const modalEl = document.getElementById('trainerPhotoModal');
    if (!modalEl) return;

    const modal = new bootstrap.Modal(modalEl);
    const form = document.getElementById('trainerPhotoForm');
    const trainerFilter = document.getElementById('trainerPhotoTrainerFilter');
    const loading = document.getElementById('trainerPhotoLoading');
    const errorEl = document.getElementById('trainerPhotoError');
    const empty = document.getElementById('trainerPhotoEmpty');
    const grid = document.getElementById('trainerPhotoGrid');
    const pagination = document.getElementById('trainerPhotoPagination');
    const submitBtn = document.getElementById('trainerPhotoSubmitBtn');
    const mediaIdInput = document.getElementById('trainerPhotoMediaId');

    // 選んだメディアの id（1 枚だけ）。ページ送り・絞り込みで外す（設計書 S-0309-M02）
    let selectedId = null;

    // ［写真を選ぶ］［変更］で開く：選択を外す → 絞り込みを既定（ログイン中）に戻す → 1 ページ目を読み込む
    document.querySelectorAll('[data-trainer-photo-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            trainerFilter.value = String(defaultTrainerId);
            loadPage(1);
            modal.show();
        });
    });

    // エラーで戻ったときは開き直す（エラーの文言は Blade で出している）
    if (modalEl.dataset.reopen === '1') {
        loadPage(1);
        modal.show();
    }

    trainerFilter.addEventListener('change', function () {
        loadPage(1);
    });

    pagination.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-page]');
        if (btn) loadPage(parseInt(btn.dataset.page, 10));
    });

    // カードを押すと選ぶ（1 枚だけ。別のカードを押すと選び直す）
    grid.addEventListener('click', function (e) {
        const card = e.target.closest('.media-card');
        if (!card) return;
        select(parseInt(card.dataset.mediaId, 10));
    });
    // キーボード（Enter・スペース）でも選べるようにする
    grid.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const card = e.target.closest('.media-card');
        if (!card) return;
        e.preventDefault();
        select(parseInt(card.dataset.mediaId, 10));
    });

    // 二重に押されないよう、送信したら押せなくする
    form.addEventListener('submit', function () {
        submitBtn.disabled = true;
    });

    function select(id) {
        selectedId = id;
        mediaIdInput.value = String(id);
        grid.querySelectorAll('.media-card').forEach(function (card) {
            const on = parseInt(card.dataset.mediaId, 10) === id;
            card.classList.toggle('border-primary', on);
            card.classList.toggle('border-3', on);
            card.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        submitBtn.disabled = false;
    }

    function clearSelection() {
        selectedId = null;
        mediaIdInput.value = '';
        submitBtn.disabled = true;
    }

    // training-records/_form.blade.php の loadPage の複製（type=photo を付け、training_record_id は付けない）
    async function loadPage(page) {
        clearSelection();

        loading.classList.remove('d-none');
        errorEl.classList.add('d-none');
        empty.classList.add('d-none');
        grid.innerHTML = '';
        pagination.classList.add('d-none');

        const params = new URLSearchParams({
            trainer_id: trainerFilter.value,
            page: String(page),
            type: 'photo',
        });
        try {
            const res = await fetch(
                '/api/training-records/available-media?' + params.toString(),
                { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
            );
            if (!res.ok) throw new Error('候補の取得に失敗しました');
            const body = await res.json();
            const items = body.data || [];
            renderGrid(items);
            renderPagination(body.meta);
            if (items.length === 0 && body.meta && body.meta.current_page === 1) {
                empty.classList.remove('d-none');
            }
        } catch (e) {
            errorEl.textContent = e.message || '候補の取得に失敗しました';
            errorEl.classList.remove('d-none');
        } finally {
            loading.classList.add('d-none');
        }
    }

    // 日付見出しの曜日（Date#getDay() は 0＝日〜6＝土）。locale に左右されないよう配列で持つ
    // （training-records/_form.blade.php の DATE_HEADING_WEEKDAYS・formatDateHeading の複製）
    const DATE_HEADING_WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    // 'Y/m/d' の文字列から 'Y/m/d（曜）' を作る。文字列パースは日がずれる恐れがあるため使わず、
    // 年・月・日を数値にしてローカル日付として組み立てる。形が想定外ならそのまま返す
    function formatDateHeading(dateKey) {
        var parts = dateKey.split('/').map(function (v) { return parseInt(v, 10); });
        if (parts.length !== 3 || parts.some(isNaN)) return dateKey;
        var weekday = DATE_HEADING_WEEKDAYS[new Date(parts[0], parts[1] - 1, parts[2]).getDay()];
        return dateKey + '（' + weekday + '）';
    }

    // training-records/_form.blade.php の renderGrid の複製（日付見出しのクラスの組み合わせも同じ）
    function renderGrid(items) {
        grid.innerHTML = '';
        var prevDateKey = null;
        items.forEach(function (m) {
            var dateKey = (m.created_at || '').split(' ')[0];
            if (dateKey !== prevDateKey) {
                var headerCol = document.createElement('div');
                headerCol.className = 'col-12 w-100' + (prevDateKey === null ? '' : ' pt-3');
                var h6 = document.createElement('h6');
                h6.className = 'fw-bold mb-0 pb-1 border-bottom';
                h6.textContent = formatDateHeading(dateKey);
                headerCol.appendChild(h6);
                grid.appendChild(headerCol);
                prevDateKey = dateKey;
            }
            grid.appendChild(buildModalCard(m));
        });
    }

    // training-records/_form.blade.php の buildModalCard の複製。写真だけなので▶は付けない。
    // チェックボックスは置かず、カード全体を押して選ぶ（1 枚だけ選ぶため）
    function buildModalCard(m) {
        const col = document.createElement('div');
        col.className = 'col';

        const card = document.createElement('div');
        card.className = 'card h-100 media-card position-relative';
        card.dataset.mediaId = String(m.id);
        card.style.cursor = 'pointer';
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-pressed', 'false');

        const ratio = document.createElement('div');
        ratio.className = 'ratio ratio-1x1 bg-light d-flex align-items-center justify-content-center';
        if (m.thumbnail_url) {
            const img = document.createElement('img');
            img.src = m.thumbnail_url;
            img.alt = m.display_title || '';
            img.className = 'img-fluid';
            ratio.appendChild(img);
        } else {
            const span = document.createElement('span');
            span.className = 'text-muted';
            span.textContent = '写真';
            ratio.appendChild(span);
        }
        card.appendChild(ratio);

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

    // training-records/_form.blade.php の renderPagination の複製
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
