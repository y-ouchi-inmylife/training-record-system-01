{{-- 利用の登録・編集モーダル（S-0305-M05）。登録と編集で共用する。
     作りは契約モーダル（clients/_contract-modal.blade.php）と同じで、計測値モーダルをなぞる。

     利用方法（S-0305 会員詳細）:
       @include('clients._usage-modal', ['client' => $client, 'openModal' => $openModal,
                'contractOptions' => $ticket['contractOptions'], 'defaultContractId' => $ticket['defaultContractId'],
                'traineeOptions' => $usageForm['traineeOptions'], 'defaultTraineeId' => $usageForm['defaultTraineeId'],
                'defaultStartTime' => $usageForm['defaultStartTime'], 'defaultEndTime' => $usageForm['defaultEndTime']])
       // ボタン側:
       //   新規登録: onclick="window.usageModal.openForCreate()"（2026-11 変更：契約を指定して開く入口〔「未利用」の行の［＋］〕はなくした）
       //   編集   : onclick="window.usageModal.openForEdit(this.dataset)"
       //            （data-id / data-client-contract-id / data-trainee-id / data-used-on / data-start-time / data-end-time / data-note。
       //              clients/_usage-row.blade.php。時刻は HH:MM）

     期待する変数:
       - $client            : App\Models\Client（store URL の生成に使う）
       - $openModal         : ?string エラーで戻ったときに開き直すモーダル（'contract' / 'usage' / null）
       - $contractOptions   : 「契約」の選択肢（ContractTicketService::build() の contractOptions。
                              契約日の古い順。[id, label, usedUp]）
       - $defaultContractId : ?int 登録時の初期値（残りがある契約のうち一番古いもの。null は「契約なし」）
       - $traineeOptions    : 「トレーニー」の選択肢（その会員のトレーニー全部。空のトレーニーも含む。id 順。[id, label]。
                              label はトレーナー側の表示名〔名前未登録なら「名前未登録」〕）。空の配列ならこの欄を出さない（2026-11 追加）
       - $defaultTraineeId  : ?int 登録時の「トレーニー」の初期値（Client::defaultUsageTraineeId()。null は初期選択なし。2026-11 追加）
       - $defaultStartTime  : string 登録時の開始時刻の初期値（事業所情報の設定の営業開始時刻。HH:MM。2026-11 追加）
       - $defaultEndTime    : string 登録時の終了時刻の初期値（事業所情報の設定の営業終了時刻。HH:MM。2026-11 追加）

     ## 項目の並び（設計書 S-0305-M05。2026-11 変更）

     契約 → 利用日 → 開始時刻・終了時刻（横並び） → トレーニー → メモ（登録・編集とも。2026-11 変更：契約を一番上にした。
     その前は 利用日 → 開始時刻・終了時刻 → トレーニー → 契約 → メモ）。
     開始時刻・終了時刻は input type="time"（計測値の計測時刻と同じ。Flatpickr は使わない）。範囲のエラーは各欄の下ではなく、
     まとまりの下に出す（§2-7 の但し書き）。

     ## 「トレーニー」の選択（設計書 S-0305-M05。2026-11 追加）

     - その会員にトレーニーが 1 頭以上いるときは必須。1 頭もいないときは欄を出さない（送らない＝NULL）
     - 先頭に未選択の選択肢（value は空）を置き、初期選択があるときは JS で隠す（applyTraineeBlank()）。
       初期選択がないとき（登録で初期値がない・編集で「トレーニーなし」の利用）は、未選択の選択肢を出して選んだ状態で開く

     ## 「契約」の選択（設計書 S-0305-M05）

     - 選択肢はサーバーで組み立てたものを出し、最後に「契約なし」（value は空）を置く
     - 使い切った契約（data-used-up="1"）は選べない（disabled）。ただし編集モードでは、
       その利用が今割り当てられている契約だけは使い切りでも選べる（applyDisabled() で切り替える）
     - 初期値：登録は $defaultContractId、編集はその利用の今の契約（data-client-contract-id。未割り当ては「契約なし」）
     - 利用 1 件は 1 回分で、回数の欄は持たない。残りのない契約への割り当てはサーバーでエラーにする
       （ClientUsageRequest。使い切りの契約は画面でも選べない）。会員の残り回数が
       マイナスになる登録・編集は止めない（「契約なし」は残りに関係なく登録できる）

     ## 配置と予約状況（設計書 S-0305-M05「予約状況」。2026-11 追加）

     モーダルは modal-xl。左（col-lg-6）に入力欄、右（col-lg-6）に予約状況（<x-usage-schedule>。カレンダーとその日の予約）。
     lg 未満では予約状況を入力欄の下に回す。利用日の欄には flatpickr を付けない（右のカレンダーと役割が重なるため。直接入力はできる）。
     カレンダーの日付を押すと利用日の欄に入れ、利用日の欄に正しい日付を入れるとカレンダーが動く。
     開いたときの月：登録は今日、編集はその利用の利用日、エラーで開き直したときは入力していた利用日（正しくなければ今日）。
     編集のときは、編集中の利用自身を予約状況に入れない（除く利用の id を渡す）。

     エラーのあとの開き直しは契約モーダルと同じ（hidden `_form_type` = 'usage'、
     編集で失敗したときは old('_usage_id') で編集モードに戻す）。選んでいた契約は
     old('client_contract_id') で選び直す（「契約なし」を選んでいた場合は null で戻るので「契約なし」を選ぶ）。
--}}

<div class="modal fade" id="usageModal" tabindex="-1" aria-labelledby="usageModalLabel" aria-hidden="true">
    {{-- modal-xl：右に予約状況を置くため広げる（2026-11 変更） --}}
    <div class="modal-dialog modal-xl">
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
                    <div class="row g-4">
                    {{-- 左：入力欄（並びは今のまま） --}}
                    <div class="col-lg-6" data-usage-inputs>
                    <div class="row g-3">
                        {{-- 契約（一番上の項目。任意。2026-11 変更：その前はトレーニーの次〔トレーニーの欄がないときは開始時刻・終了時刻の次〕）。エラーで開き直したときは選んでいた値を選び直す。
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
                        <div class="col-md-6">
                            <label for="used_on" class="form-label">利用日 <span class="text-danger">*</span></label>
                            {{-- テキストの欄（YYYY-MM-DD を直接入力できる）。flatpickr（.datepicker）は付けない
                                 （2026-11 変更：右の予約状況のカレンダーと役割が重なるため。その前は計測値モーダルの計測日と同じ .datepicker） --}}
                            <input type="text" class="form-control @if($openModal === 'usage') @error('used_on') is-invalid @enderror @endif"
                                   id="used_on" name="used_on"
                                   value="{{ $openModal === 'usage' ? old('used_on') : '' }}"
                                   placeholder="例: 2026-09-15" pattern="\d{4}-\d{2}-\d{2}" maxlength="10"
                                   required autocomplete="off">
                            @if($openModal === 'usage')
                                <x-form-error field="used_on" />
                            @endif
                        </div>
                        {{-- 開始時刻・終了時刻（利用日の次。横に並べる。2026-11 追加）。範囲のエラーはまとまりの下に出す（§2-7 の但し書き） --}}
                        <div class="col-12">
                            <div class="d-flex flex-wrap align-items-end column-gap-2 row-gap-2" data-usage-time-range>
                                <div>
                                    <label for="usage_start_time" class="form-label">開始時刻 <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control @if($openModal === 'usage') @error('start_time') is-invalid @enderror @endif"
                                           id="usage_start_time" name="start_time"
                                           value="{{ $openModal === 'usage' ? old('start_time') : '' }}"
                                           required autocomplete="off">
                                </div>
                                <div class="pb-2" aria-hidden="true">〜</div>
                                <div>
                                    <label for="usage_end_time" class="form-label">終了時刻 <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control @if($openModal === 'usage') @error('end_time') is-invalid @enderror @endif"
                                           id="usage_end_time" name="end_time"
                                           value="{{ $openModal === 'usage' ? old('end_time') : '' }}"
                                           required autocomplete="off">
                                </div>
                            </div>
                            @if($openModal === 'usage')
                                <x-form-error field="start_time" block />
                                <x-form-error field="end_time" block />
                            @endif
                        </div>
                        {{-- トレーニー（開始時刻・終了時刻の次。2026-11 追加）。その会員にトレーニーがいなければ出さない。
                             エラーで開き直したときは選んでいたトレーニーを選び直す（未選択なら未選択の選択肢）。
                             未選択の選択肢を隠すかどうかは JS の applyTraineeBlank() で決める --}}
                        @if(count($traineeOptions) > 0)
                            <div class="col-12">
                                <label for="usage_trainee_id" class="form-label">トレーニー <span class="text-danger">*</span></label>
                                <select class="form-select @if($openModal === 'usage') @error('trainee_id') is-invalid @enderror @endif"
                                        id="usage_trainee_id" name="trainee_id" required>
                                    <option value="" @selected($openModal === 'usage' && blank(old('trainee_id')))></option>
                                    @foreach($traineeOptions as $option)
                                        <option value="{{ $option['id'] }}"
                                                @selected($openModal === 'usage' && (string) old('trainee_id') === (string) $option['id'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                @if($openModal === 'usage')
                                    <x-form-error field="trainee_id" />
                                @endif
                            </div>
                        @endif
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
                    {{-- 右：予約状況（カレンダーとその日の予約。lg 未満では入力欄の下。2026-11 追加） --}}
                    <div class="col-lg-6">
                        <x-usage-schedule id="usageSchedule" />
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
    // 登録時の「トレーニー」の初期値（null は初期選択なし）と、開始時刻・終了時刻の初期値（事業所情報の設定。HH:MM）。2026-11 追加
    const DEFAULT_TRAINEE_ID = @json($defaultTraineeId);
    const DEFAULT_START_TIME = @json($defaultStartTime);
    const DEFAULT_END_TIME = @json($defaultEndTime);

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
    let traineeSelect = null;   // その会員にトレーニーがいなければ null（欄を出さない）
    let startTimeInput = null;
    let endTimeInput = null;
    let schedule = null;        // 予約状況（window.UsageSchedule。2026-11 追加）

    // 使い切った契約を選べなくする。currentContractId（編集中の利用が今割り当てられている契約）
    // だけは使い切りでも選べるようにする。登録モードでは null を渡す。
    function applyDisabled(currentContractId) {
        Array.from(contractSelect.options).forEach(function (option) {
            if (option.dataset.usedUp === '1') {
                option.disabled = currentContractId === null || String(option.value) !== String(currentContractId);
            }
        });
    }

    // 「トレーニー」の未選択の選択肢（value は空）を、何かが選ばれているときだけ隠す（2026-11 追加）。
    // 初期選択がないとき（登録で初期値がない・編集で「トレーニーなし」の利用・エラーで未選択のまま戻ったとき）は出す
    function applyTraineeBlank() {
        if (!traineeSelect) return;
        const blank = traineeSelect.querySelector('option[value=""]');
        if (blank) {
            blank.hidden = traineeSelect.value !== '';
        }
    }
    function setTrainee(traineeId) {
        if (!traineeSelect) return;
        traineeSelect.value = traineeId === undefined || traineeId === null ? '' : String(traineeId);
        applyTraineeBlank();
    }

    // 利用の行（data-client-contract-id を持つ。clients/_usage-row.blade.php）から、その利用の今の契約を読む。
    // エラーで編集モードに開き直したときに使う（未割り当ては null）。
    function currentContractOf(usageId) {
        const slot = document.querySelector('button.usage-row[data-id="' + CSS.escape(String(usageId)) + '"]');
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
        traineeSelect = document.getElementById('usage_trainee_id');
        startTimeInput = document.getElementById('usage_start_time');
        endTimeInput = document.getElementById('usage_end_time');
        if (traineeSelect) {
            traineeSelect.addEventListener('change', applyTraineeBlank);
        }

        // 予約状況（2026-11 追加）。カレンダーの日付を押したら利用日の欄に入れ、
        // 利用日の欄に正しい日付を入れたらカレンダーをその月・日に動かす
        schedule = new window.UsageSchedule(document.getElementById('usageSchedule'), {
            onSelect: function (ymd) {
                usedOnInput.value = ymd;
                usedOnInput.dispatchEvent(new Event('input', { bubbles: true }));
            },
        });
        usedOnInput.addEventListener('input', function () { schedule.setDate(usedOnInput.value); });
        usedOnInput.addEventListener('change', function () { schedule.setDate(usedOnInput.value); });

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

    // 登録モードで開く（「利用を追加」ボタン）。契約は登録時の初期値（残りがある一番古い契約）を選ぶ。
    // 使い切りの契約は無効のまま。2026-11 変更：契約を指定して開く入口（「未利用」の行の［＋］）をなくしたため、引数をなくした
    function openForCreate() {
        initRefs();
        if (!modal) return;
        setCreateMode();
        applyDisabled(null);
        contractSelect.value = DEFAULT_CONTRACT_ID === null ? '' : String(DEFAULT_CONTRACT_ID);
        usedOnInput.value = todayString();
        startTimeInput.value = DEFAULT_START_TIME;
        endTimeInput.value = DEFAULT_END_TIME;
        setTrainee(DEFAULT_TRAINEE_ID);
        noteInput.value = '';
        schedule.open(usedOnInput.value, { excludeUsageId: null, fallbackDate: todayString() });
        modal.show();
    }

    function openForEdit(data) {
        // data: { id, clientContractId, traineeId, usedOn, startTime, endTime, note }（data-* 属性はキャメルケースになる。時刻は HH:MM）
        initRefs();
        if (!modal) return;
        setEditMode(data.id);
        const currentContractId = data.clientContractId || null;
        applyDisabled(currentContractId);
        contractSelect.value = currentContractId === null ? '' : String(currentContractId);
        usedOnInput.value = data.usedOn || '';
        startTimeInput.value = data.startTime || '';
        endTimeInput.value = data.endTime || '';
        // 「トレーニーなし」（data-trainee-id が空）の利用は、未選択の状態で開く
        setTrainee(data.traineeId || null);
        noteInput.value = data.note || '';
        // 編集中の利用自身は、予約状況の頭数にも一覧にも入れない
        schedule.open(usedOnInput.value, { excludeUsageId: data.id, fallbackDate: todayString() });
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
            // トレーニー・時刻は Blade の old() で戻し済み。未選択の選択肢の表示だけを合わせる
            applyTraineeBlank();
            // 予約状況は入力していた利用日の月で開く（正しくない日付なら今日）
            schedule.open(usedOnInput.value, { excludeUsageId: oldUsageId || null, fallbackDate: todayString() });
            modal.show();
        @endif
    });
})();
</script>
@endpush
