{{-- 契約の登録・編集モーダル（S-0305-M04）。登録と編集で共用する。
     作りは計測値モーダル（trainees/_measurement-modal.blade.php）をなぞる：
     JavaScript でフォームの `action` / `_method` / 各入力値を差し替える。

     利用方法（S-0305 会員詳細）:
       @include('clients._contract-modal', ['client' => $client, 'openModal' => $openModal])
       // ボタン側:
       //   新規登録: onclick="window.contractModal.openForCreate()"
       //   編集   : onclick="window.contractModal.openForEdit(this.dataset)"
       //            （data-id / data-contracted-on / data-count / data-expires-on / data-note）

     期待する変数:
       - $client    : App\Models\Client（store URL の生成に使う）
       - $openModal : ?string エラーで戻ったときに開き直すモーダル（'contract' / 'usage' / null）。
                      ClientController::show で old('_form_type') から決めて渡す。

     ## エラーのあとの開き直し（設計書 S-0305「S-0305-M04・M05 に共通の決まり」）

     契約と利用のモーダルは入力欄（count / note）の名前が重なるため、hidden `_form_type`
     （'contract' / 'usage'）で送信元を送り、エラーで戻ったときは送信元のモーダルだけを開き直す。
     欄の赤枠・欄の下の文言も、送信元のモーダルにだけ出す（$openModal === 'contract' のときだけ）。
     編集で失敗した場合は old('_contract_id') に対象の id が入るので、編集モードで開き直す。
     モーダルの中なので、画面上部のエラー案内（<x-form-error-summary />）は出さない（§2-7）。
--}}

<div class="modal fade" id="contractModal" tabindex="-1" aria-labelledby="contractModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。 --}}
            <form id="contractForm" method="POST" action="{{ route('client-contracts.store', $client) }}" novalidate>
                @csrf
                {{-- _method は JavaScript で「（空）」と 'PUT' を切り替える。 --}}
                <input type="hidden" name="_method" id="contractFormMethodInput" value="">
                {{-- 編集時は対象レコードの id を載せる。エラーで戻ったときに編集モードで開き直す判定に使う。 --}}
                <input type="hidden" name="_contract_id" id="contractFormIdInput" value="">
                {{-- 送信元のモーダルの種別。ClientContractRequest はルールに持たないため validated() に混入しない。 --}}
                <input type="hidden" name="_form_type" value="contract">

                <div class="modal-header">
                    <h5 class="modal-title" id="contractModalLabel">契約の登録</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="contracted_on" class="form-label">契約日 <span class="text-danger">*</span></label>
                            {{-- 入力部品は計測値モーダルの計測日と同じ（Flatpickr の .datepicker） --}}
                            <input type="text" class="form-control datepicker @if($openModal === 'contract') @error('contracted_on') is-invalid @enderror @endif"
                                   id="contracted_on" name="contracted_on"
                                   value="{{ $openModal === 'contract' ? old('contracted_on') : '' }}"
                                   placeholder="例: 2026-09-15" pattern="\d{4}-\d{2}-\d{2}" maxlength="10"
                                   required autocomplete="off">
                            @if($openModal === 'contract')
                                <x-form-error field="contracted_on" />
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label for="contract_count" class="form-label">回数 <span class="text-danger">*</span></label>
                            <input type="number" step="1" min="1" max="999" inputmode="numeric"
                                   class="form-control @if($openModal === 'contract') @error('count') is-invalid @enderror @endif"
                                   id="contract_count" name="count"
                                   value="{{ $openModal === 'contract' ? old('count') : '' }}"
                                   placeholder="例: 4"
                                   required autocomplete="off">
                            @if($openModal === 'contract')
                                <x-form-error field="count" />
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label for="expires_on" class="form-label">有効期限</label>
                            <input type="text" class="form-control datepicker @if($openModal === 'contract') @error('expires_on') is-invalid @enderror @endif"
                                   id="expires_on" name="expires_on"
                                   value="{{ $openModal === 'contract' ? old('expires_on') : '' }}"
                                   placeholder="例: 2026-12-15" pattern="\d{4}-\d{2}-\d{2}" maxlength="10"
                                   autocomplete="off">
                            @if($openModal === 'contract')
                                <x-form-error field="expires_on" />
                            @endif
                        </div>
                        <div class="col-12">
                            <label for="contract_note" class="form-label">メモ</label>
                            <input type="text" maxlength="255"
                                   class="form-control @if($openModal === 'contract') @error('note') is-invalid @enderror @endif"
                                   id="contract_note" name="note"
                                   value="{{ $openModal === 'contract' ? old('note') : '' }}"
                                   placeholder="例: 4回コース"
                                   autocomplete="off">
                            @if($openModal === 'contract')
                                <x-form-error field="note" />
                            @endif
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    {{-- 削除は編集モードのときだけ表示する（一般トレーナーも可）。
                         フォームの入れ子はできないため、下の削除用フォームを JS から送る。 --}}
                    <button type="button" class="btn btn-outline-danger me-auto d-none" id="contractDeleteBtn">削除</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success" id="contractSubmitBtn">登録</button>
                </div>
            </form>
            {{-- 削除用フォーム（action は編集モードで開いたときに JS で設定する） --}}
            <form id="contractDeleteForm" method="POST" action="" class="d-none">
                @csrf
                @method('DELETE')
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    // Blade から埋め込む定数
    const STORE_URL = @json(route('client-contracts.store', $client));
    // 編集・削除時の URL テンプレート。プレースホルダを実 ID で置換する。
    const UPDATE_URL_TEMPLATE = @json(route('client-contracts.update', ['contract' => '__ID__']));
    const DESTROY_URL_TEMPLATE = @json(route('client-contracts.destroy', ['contract' => '__ID__']));

    // 登録時の初期値「今日」はモーダルを開いた瞬間のブラウザの日付を使う
    // （計測値モーダルの「新規登録時の初期値」と同じ考え方）。
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
    let contractedOnInput = null;
    let countInput = null;
    let expiresOnInput = null;
    let noteInput = null;
    // 編集中の契約に割り当てられた利用の件数（削除の確認ダイアログの文言に使う。回数ではない）
    let editingUsageCount = 0;

    // 契約の見出し（data-usage-count を持つ button）から、割り当てられた利用の件数を読む。
    // エラーで編集モードに開き直したときも、見出しから同じ値を取れる。
    function usageCountOf(id) {
        const heading = document.querySelector('button[data-usage-count][data-id="' + CSS.escape(String(id)) + '"]');
        return heading ? (parseInt(heading.dataset.usageCount, 10) || 0) : 0;
    }

    function initRefs() {
        if (modalEl) return;
        modalEl = document.getElementById('contractModal');
        if (!modalEl) return;
        modal = new bootstrap.Modal(modalEl);
        formEl = document.getElementById('contractForm');
        methodInput = document.getElementById('contractFormMethodInput');
        idInput = document.getElementById('contractFormIdInput');
        submitBtn = document.getElementById('contractSubmitBtn');
        deleteBtn = document.getElementById('contractDeleteBtn');
        deleteForm = document.getElementById('contractDeleteForm');
        titleEl = document.getElementById('contractModalLabel');
        contractedOnInput = document.getElementById('contracted_on');
        countInput = document.getElementById('contract_count');
        expiresOnInput = document.getElementById('expires_on');
        noteInput = document.getElementById('contract_note');

        // 割り当てられた利用があるときだけ、「契約なし」になる件数を添える（設計書 S-0305-M04）。
        // 利用が「契約なし」になるのは DB の外部キー（ON DELETE SET NULL）の処理。
        deleteBtn.addEventListener('click', function () {
            let message = 'この契約を削除しますか？';
            if (editingUsageCount > 0) {
                message += '\n割り当てられている利用 ' + editingUsageCount + '件は『契約なし』になります。';
            }
            if (confirm(message)) {
                deleteForm.submit();
            }
        });
    }

    // 登録モード／編集モードの見た目と送信先を切り替える
    function setCreateMode() {
        formEl.action = STORE_URL;
        methodInput.value = '';
        idInput.value = '';
        titleEl.textContent = '契約の登録';
        submitBtn.textContent = '登録';
        deleteBtn.classList.add('d-none');
        deleteForm.action = '';
        editingUsageCount = 0;
    }
    function setEditMode(id) {
        formEl.action = UPDATE_URL_TEMPLATE.replace('__ID__', encodeURIComponent(id));
        methodInput.value = 'PUT';
        idInput.value = id;
        titleEl.textContent = '契約の編集';
        submitBtn.textContent = '更新';
        deleteBtn.classList.remove('d-none');
        deleteForm.action = DESTROY_URL_TEMPLATE.replace('__ID__', encodeURIComponent(id));
        editingUsageCount = usageCountOf(id);
    }

    function openForCreate() {
        initRefs();
        if (!modal) return;
        setCreateMode();
        contractedOnInput.value = todayString();
        countInput.value = '';
        expiresOnInput.value = '';
        noteInput.value = '';
        modal.show();
    }

    function openForEdit(data) {
        // data: { id, contractedOn, count, expiresOn, note, usageCount }（data-* 属性はキャメルケースになる）
        //   usageCount は setEditMode() が見出しから読み直す
        initRefs();
        if (!modal) return;
        setEditMode(data.id);
        contractedOnInput.value = data.contractedOn || '';
        countInput.value = data.count || '';
        expiresOnInput.value = data.expiresOn || '';
        noteInput.value = data.note || '';
        modal.show();
    }

    window.contractModal = { openForCreate: openForCreate, openForEdit: openForEdit };

    // バリデーションエラーで再描画されたときに、送信元がこのモーダルならモードを保って開き直す。
    // 入力値は old() で既に埋まっているので、送信先・タイトルなどだけを整える。
    document.addEventListener('DOMContentLoaded', function () {
        initRefs();
        @if($openModal === 'contract')
            const oldContractId = @json(old('_contract_id'));
            if (oldContractId) {
                setEditMode(oldContractId);
            } else {
                setCreateMode();
            }
            modal.show();
        @endif
    });
})();
</script>
@endpush
