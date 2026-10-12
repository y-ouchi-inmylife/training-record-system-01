{{-- 利用の登録・編集モーダル（S-0305-M05）。登録と編集で共用する。
     作りは契約モーダル（clients/_contract-modal.blade.php）と同じで、計測値モーダルをなぞる。

     利用方法（S-0305 会員詳細）:
       @include('clients._usage-modal', ['client' => $client, 'openModal' => $openModal])
       // ボタン側:
       //   新規登録: onclick="window.usageModal.openForCreate()"
       //   編集   : onclick="window.usageModal.openForEdit(this.dataset)"
       //            （data-id / data-used-on / data-count / data-note。clients/_usage-slot.blade.php）

     期待する変数:
       - $client    : App\Models\Client（store URL の生成に使う）
       - $openModal : ?string エラーで戻ったときに開き直すモーダル（'contract' / 'usage' / null）

     エラーのあとの開き直しは契約モーダルと同じ（hidden `_form_type` = 'usage'、
     編集で失敗したときは old('_usage_id') で編集モードに戻す）。
     残り回数がマイナスになる登録・編集も止めない（警告も出さない。設計書 S-0305-M05）。
--}}

<div class="modal fade" id="usageModal" tabindex="-1" aria-labelledby="usageModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。 --}}
            <form id="usageForm" method="POST" action="{{ route('client-usages.store', $client) }}" novalidate>
                @csrf
                <input type="hidden" name="_method" id="usageFormMethodInput" value="">
                <input type="hidden" name="_usage_id" id="usageFormIdInput" value="">
                {{-- 送信元のモーダルの種別。ClientUsageRequest はルールに持たないため validated() に混入しない。 --}}
                <input type="hidden" name="_form_type" value="usage">

                <div class="modal-header">
                    <h5 class="modal-title" id="usageModalLabel">利用の登録</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="used_on" class="form-label">利用日 <span class="text-danger">*</span></label>
                            {{-- 入力部品は計測値モーダルの計測日と同じ（Flatpickr の .datepicker） --}}
                            <input type="text" class="form-control datepicker @if($openModal === 'usage') @error('used_on') is-invalid @enderror @endif"
                                   id="used_on" name="used_on"
                                   value="{{ $openModal === 'usage' ? old('used_on') : '' }}"
                                   placeholder="例: 2026-09-15" pattern="\d{4}-\d{2}-\d{2}" maxlength="10"
                                   required autocomplete="off">
                            @if($openModal === 'usage')
                                <x-form-error field="used_on" />
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label for="usage_count" class="form-label">回数 <span class="text-danger">*</span></label>
                            <input type="number" step="1" min="1" max="999" inputmode="numeric"
                                   class="form-control @if($openModal === 'usage') @error('count') is-invalid @enderror @endif"
                                   id="usage_count" name="count"
                                   value="{{ $openModal === 'usage' ? old('count') : '' }}"
                                   required autocomplete="off">
                            @if($openModal === 'usage')
                                <x-form-error field="count" />
                            @endif
                        </div>
                        <div class="col-12">
                            <label for="usage_note" class="form-label">メモ</label>
                            <input type="text" maxlength="255"
                                   class="form-control @if($openModal === 'usage') @error('note') is-invalid @enderror @endif"
                                   id="usage_note" name="note"
                                   value="{{ $openModal === 'usage' ? old('note') : '' }}"
                                   placeholder="例: 当日キャンセル"
                                   autocomplete="off">
                            @if($openModal === 'usage')
                                <x-form-error field="note" />
                            @endif
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    {{-- 削除は編集モードのときだけ表示する（一般トレーナーも可） --}}
                    <button type="button" class="btn btn-outline-danger me-auto d-none" id="usageDeleteBtn">削除</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success" id="usageSubmitBtn">登録</button>
                </div>
            </form>
            {{-- 削除用フォーム（action は編集モードで開いたときに JS で設定する） --}}
            <form id="usageDeleteForm" method="POST" action="" class="d-none">
                @csrf
                @method('DELETE')
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const STORE_URL = @json(route('client-usages.store', $client));
    const UPDATE_URL_TEMPLATE = @json(route('client-usages.update', ['usage' => '__ID__']));
    const DESTROY_URL_TEMPLATE = @json(route('client-usages.destroy', ['usage' => '__ID__']));

    // 登録時の初期値「今日」はモーダルを開いた瞬間のブラウザの日付を使う
    function pad2(n) { return String(n).padStart(2, '0'); }
    function todayString() {
        const d = new Date();
        return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
    }

    let modalEl = null;
    let modal = null;
    let formEl = null;
    let methodInput = null;
    let idInput = null;
    let submitBtn = null;
    let deleteBtn = null;
    let deleteForm = null;
    let titleEl = null;
    let usedOnInput = null;
    let countInput = null;
    let noteInput = null;

    function initRefs() {
        if (modalEl) return;
        modalEl = document.getElementById('usageModal');
        if (!modalEl) return;
        modal = new bootstrap.Modal(modalEl);
        formEl = document.getElementById('usageForm');
        methodInput = document.getElementById('usageFormMethodInput');
        idInput = document.getElementById('usageFormIdInput');
        submitBtn = document.getElementById('usageSubmitBtn');
        deleteBtn = document.getElementById('usageDeleteBtn');
        deleteForm = document.getElementById('usageDeleteForm');
        titleEl = document.getElementById('usageModalLabel');
        usedOnInput = document.getElementById('used_on');
        countInput = document.getElementById('usage_count');
        noteInput = document.getElementById('usage_note');

        deleteBtn.addEventListener('click', function () {
            if (confirm('この利用を削除しますか？')) {
                deleteForm.submit();
            }
        });
    }

    function setCreateMode() {
        formEl.action = STORE_URL;
        methodInput.value = '';
        idInput.value = '';
        titleEl.textContent = '利用の登録';
        submitBtn.textContent = '登録';
        deleteBtn.classList.add('d-none');
        deleteForm.action = '';
    }
    function setEditMode(id) {
        formEl.action = UPDATE_URL_TEMPLATE.replace('__ID__', encodeURIComponent(id));
        methodInput.value = 'PUT';
        idInput.value = id;
        titleEl.textContent = '利用の編集';
        submitBtn.textContent = '更新';
        deleteBtn.classList.remove('d-none');
        deleteForm.action = DESTROY_URL_TEMPLATE.replace('__ID__', encodeURIComponent(id));
    }

    function openForCreate() {
        initRefs();
        if (!modal) return;
        setCreateMode();
        usedOnInput.value = todayString();
        // 回数の初期値は 1（設計書 S-0305-M05）
        countInput.value = '1';
        noteInput.value = '';
        modal.show();
    }

    function openForEdit(data) {
        // data: { id, usedOn, count, note }（data-* 属性はキャメルケースになる）
        initRefs();
        if (!modal) return;
        setEditMode(data.id);
        usedOnInput.value = data.usedOn || '';
        countInput.value = data.count || '';
        noteInput.value = data.note || '';
        modal.show();
    }

    window.usageModal = { openForCreate: openForCreate, openForEdit: openForEdit };

    // バリデーションエラーで再描画されたときに、送信元がこのモーダルならモードを保って開き直す。
    document.addEventListener('DOMContentLoaded', function () {
        initRefs();
        @if($openModal === 'usage')
            const oldUsageId = @json(old('_usage_id'));
            if (oldUsageId) {
                setEditMode(oldUsageId);
            } else {
                setCreateMode();
            }
            modal.show();
        @endif
    });
})();
</script>
@endpush
