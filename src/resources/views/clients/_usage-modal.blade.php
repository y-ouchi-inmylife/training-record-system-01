{{-- 利用の登録・編集モーダル（S-0305-M05）。登録と編集で共用する。
     作りは契約モーダル（clients/_contract-modal.blade.php）と同じで、計測値モーダルをなぞる。

     利用方法（S-0305 会員詳細）:
       @include('clients._usage-modal', ['client' => $client, 'openModal' => $openModal,
                'contractOptions' => $ticket['contractOptions'], 'defaultContractId' => $ticket['defaultContractId']])
       // ボタン側:
       //   新規登録: onclick="window.usageModal.openForCreate()"
       //            （「未利用」のマスからは openForCreate(this.dataset.clientContractId) で、その契約を選んで開く）
       //   編集   : onclick="window.usageModal.openForEdit(this.dataset)"
       //            （data-id / data-client-contract-id / data-used-on / data-note。
       //              clients/_usage-slot.blade.php）

     期待する変数:
       - $client            : App\Models\Client（store URL の生成に使う）
       - $openModal         : ?string エラーで戻ったときに開き直すモーダル（'contract' / 'usage' / null）
       - $contractOptions   : 「契約」の選択肢（ContractTicketService::build() の contractOptions。
                              契約日の古い順。[id, label, usedUp]）
       - $defaultContractId : ?int 登録時の初期値（残りがある契約のうち一番古いもの。null は「契約なし」）

     ## 「契約」の選択（設計書 S-0305-M05）

     - 選択肢はサーバーで組み立てたものを出し、最後に「契約なし」（value は空）を置く
     - 使い切った契約（data-used-up="1"）は選べない（disabled）。ただし編集モードでは、
       その利用が今割り当てられている契約だけは使い切りでも選べる（applyDisabled() で切り替える）
     - 初期値：登録は $defaultContractId、編集はその利用の今の契約（data-client-contract-id。未割り当ては「契約なし」）
     - 利用 1 件は 1 回分で、回数の欄は持たない。残りのない契約への割り当てはサーバーでエラーにする
       （ClientUsageRequest。使い切りの契約は画面でも選べない）。会員の残り回数が
       マイナスになる登録・編集は止めない（「契約なし」は残りに関係なく登録できる）

     エラーのあとの開き直しは契約モーダルと同じ（hidden `_form_type` = 'usage'、
     編集で失敗したときは old('_usage_id') で編集モードに戻す）。選んでいた契約は
     old('client_contract_id') で選び直す（「契約なし」を選んでいた場合は null で戻るので「契約なし」を選ぶ）。
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
                        {{-- 契約（利用日の次の項目。任意）。エラーで開き直したときは選んでいた値を選び直す。
                             disabled（使い切り）の付け外しはモードに合わせて JS の applyDisabled() で行う。 --}}
                        <div class="col-12">
                            <label for="client_contract_id" class="form-label">契約</label>
                            <select class="form-select @if($openModal === 'usage') @error('client_contract_id') is-invalid @enderror @endif"
                                    id="client_contract_id" name="client_contract_id">
                                @foreach($contractOptions as $option)
                                    <option value="{{ $option['id'] }}"
                                            data-used-up="{{ $option['usedUp'] ? '1' : '0' }}"
                                            @disabled($option['usedUp'])
                                            @selected($openModal === 'usage' && (string) old('client_contract_id') === (string) $option['id'])>{{ $option['label'] }}</option>
                                @endforeach
                                <option value="" data-used-up="0"
                                        @selected($openModal === 'usage' && old('client_contract_id') === null)>契約なし</option>
                            </select>
                            @if($openModal === 'usage')
                                <x-form-error field="client_contract_id" />
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
    // 登録時の「契約」の初期値（残りがある契約のうち一番古いもの。null は「契約なし」）
    const DEFAULT_CONTRACT_ID = @json($defaultContractId);

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
    let noteInput = null;
    let contractSelect = null;

    // 使い切った契約を選べなくする。currentContractId（編集中の利用が今割り当てられている契約）
    // だけは使い切りでも選べるようにする。登録モードでは null を渡す。
    function applyDisabled(currentContractId) {
        Array.from(contractSelect.options).forEach(function (option) {
            if (option.dataset.usedUp === '1') {
                option.disabled = currentContractId === null || String(option.value) !== String(currentContractId);
            }
        });
    }

    // 利用済みのマス（data-client-contract-id を持つ）から、その利用の今の契約を読む。
    // エラーで編集モードに開き直したときに使う（未割り当ては null）。
    function currentContractOf(usageId) {
        const slot = document.querySelector('button.ticket-slot[data-id="' + CSS.escape(String(usageId)) + '"]');
        return slot && slot.dataset.clientContractId ? slot.dataset.clientContractId : null;
    }

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
        noteInput = document.getElementById('usage_note');
        contractSelect = document.getElementById('client_contract_id');

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

    // contractId を渡すと、その契約を選んだ状態で開く（「未利用」のマスから）。
    // 渡さないとき（「利用を追加」ボタン）は、登録時の初期値（残りがある一番古い契約）を選ぶ。
    // どちらも登録モードなので、使い切りの契約は無効のまま（未利用のマスは残りがある契約にしかない）。
    function openForCreate(contractId) {
        initRefs();
        if (!modal) return;
        setCreateMode();
        applyDisabled(null);
        if (contractId !== undefined && contractId !== null && contractId !== '') {
            contractSelect.value = String(contractId);
        } else {
            contractSelect.value = DEFAULT_CONTRACT_ID === null ? '' : String(DEFAULT_CONTRACT_ID);
        }
        usedOnInput.value = todayString();
        noteInput.value = '';
        modal.show();
    }

    function openForEdit(data) {
        // data: { id, clientContractId, usedOn, note }（data-* 属性はキャメルケースになる）
        initRefs();
        if (!modal) return;
        setEditMode(data.id);
        const currentContractId = data.clientContractId || null;
        applyDisabled(currentContractId);
        contractSelect.value = currentContractId === null ? '' : String(currentContractId);
        usedOnInput.value = data.usedOn || '';
        noteInput.value = data.note || '';
        modal.show();
    }

    window.usageModal = { openForCreate: openForCreate, openForEdit: openForEdit };

    // バリデーションエラーで再描画されたときに、送信元がこのモーダルならモードを保って開き直す。
    document.addEventListener('DOMContentLoaded', function () {
        initRefs();
        @if($openModal === 'usage')
            const oldUsageId = @json(old('_usage_id'));
            // 選んでいた契約は Blade の old('client_contract_id') で選び直し済み。ここでは
            // 送信先・タイトルと、使い切りの契約の disabled をモードに合わせるだけ。
            if (oldUsageId) {
                setEditMode(oldUsageId);
                applyDisabled(currentContractOf(oldUsageId));
            } else {
                setCreateMode();
                applyDisabled(null);
            }
            modal.show();
        @endif
    });
})();
</script>
@endpush
