{{-- 計測値の登録・編集モーダル（登録と編集で共用、S-0305 / S-0309 の両方で使う汎用部品）。
     登録・編集で 1 つのモーダルを使い、JavaScript でフォームの `action` /
     `_method` / 各入力値を差し替える。

     利用方法（S-0309 = 単一トレーニー画面）:
       @include('trainees._measurement-modal', ['trainee' => $trainee])
       // ボタン側:
       //   新規登録: onclick="window.measurementModals[{{ $trainee->id }}].openForCreate()"
       //   編集   : onclick="window.measurementModals[{{ $trainee->id }}].openForEdit(this.dataset)"

     利用方法（S-0305 = 会員詳細、複数トレーニーが並ぶ画面）:
       for-each の中で
         include('trainees._measurement-modal', ['trainee' => $trainee, 'returnTo' => 'client'])
       を呼ぶ形。実装例は resources/views/clients/show.blade.php を参照

     期待する変数:
       - $trainee  : App\Models\Trainee 親トレーニー（store URL 生成・ID の一意化に使う）
       - $returnTo : string ('trainee' | 'client')
                     成功時のリダイレクト先を判別する hidden。既定は 'trainee'（S-0309 との後方互換）。
                     S-0305 では 'client' を渡して会員詳細に戻す。詳細は
                     api-design.md 「計測値エンドポイントの `return_to` 仕様」参照。

     ## 複数トレーニーに対応する ID の一意化（2026-09 変更、設計書 S-0305 セクション3 参照）

     以前は固定 ID（`measurementModal` / `measurementForm` / `measured_date` など）を
     使っていたが、S-0305 は 1 会員に複数トレーニーが並ぶため重複違反になる。
     モーダル・フォーム・入力欄のすべての ID に `-{{ $trainee->id }}` を付けて
     トレーニーごとに一意化する。あわせて `window.measurementModal` の単一
     オブジェクトを `window.measurementModals[traineeId]` の辞書に変更した。
     S-0309 も同じ形（辞書経由）に揃える（詳細は screen-design.md S-0309
     「計測値モーダルの共用（S-0305 との）」参照）。

     ## 新規登録時の初期日時（変更なし、設計書 S-0309「新規登録時の初期値」参照）

     コントローラの `now()` は使わず、JavaScript の `new Date()` で `openForCreate()`
     の中で組み立てる（**モーダルを開いた瞬間のブラウザ時刻**を使う）。

     ## バリデーションエラー時の復帰

     Laravel は `back()` で redirect し old() と $errors がセッションに載る。
     再描画時にモーダルを自動で開き直す判定は以下：
       1. 計測値フィールドに関するエラーがあるか（$errors->hasAny([...]) で判定）
       2. old('_trainee_id') が現在のトレーニーの ID と一致するか（S-0305 のような
          複数トレーニー画面で、該当トレーニーのモーダルだけを開き直すため）
       3. old('_measurement_id') の有無で「編集モードで開き直す」か「登録モードで
          開き直す」を分岐
--}}

@php
    // returnTo の既定値。S-0309 側は引数なしで include するため、ここでフォールバックを設定する。
    $returnTo = $returnTo ?? 'trainee';

    // 計測値フォームに関するエラーがあるかを Blade 側で判定してから JS に渡す。
    // トレーニー本体のエラー（トレーニー編集フォームで発生）とは区別する。
    $measurementFieldNames = ['measured_date', 'measured_time', 'weight_kg'];
    $hasMeasurementError = $errors->hasAny($measurementFieldNames);
    // 編集で失敗した場合は old('_measurement_id') に対象レコードの id が入る。
    // route() で update URL を再構築できるよう Blade から渡す。
    $oldMeasurementId = old('_measurement_id');
    // 複数トレーニーが並ぶ画面（S-0305）で、どのトレーニーのモーダルを開き直すかの判別に使う。
    $shouldReopen = $hasMeasurementError && (int) old('_trainee_id') === (int) $trainee->id;
    $storeUrl = route('trainee-measurements.store', $trainee);
@endphp

<div class="modal fade" id="measurementModal-{{ $trainee->id }}" tabindex="-1" aria-labelledby="measurementModalLabel-{{ $trainee->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
                 required・type・step・min・max・pattern などの属性は残す。 --}}
            <form id="measurementForm-{{ $trainee->id }}" method="POST" action="{{ $storeUrl }}" novalidate>
                @csrf
                {{-- _method は JavaScript で「（空）」と 'PUT' を切り替える。
                     `@method('PUT')` の代わりに hidden input を JS で操作する。 --}}
                <input type="hidden" name="_method" id="measurementFormMethodInput-{{ $trainee->id }}" value="">
                {{-- 編集時は対象レコードの id をここに載せる。バリデーションエラーで
                     再描画されたときに、old('_measurement_id') から編集モードで開き直す判定に使う。 --}}
                <input type="hidden" name="_measurement_id" id="measurementFormIdInput-{{ $trainee->id }}" value="">
                {{-- どのトレーニーのモーダルからの送信かを判別する。バリデーションエラー時に
                     old('_trainee_id') と各モーダルの $trainee->id を比較して該当モーダル
                     だけを開き直す。TraineeMeasurementRequest は _trainee_id をルールに
                     持たないため validated() に混入しない。 --}}
                <input type="hidden" name="_trainee_id" value="{{ $trainee->id }}">
                {{-- 成功時のリダイレクト先を送信元から示す（api-design.md「計測値エンドポイントの
                     `return_to` 仕様」参照）。値は 'client' / 'trainee' のみ。URL は渡さない
                     （open redirect の回避）。 --}}
                <input type="hidden" name="return_to" value="{{ $returnTo }}">

                <div class="modal-header">
                    <h5 class="modal-title" id="measurementModalLabel-{{ $trainee->id }}">計測値の登録</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>

                <div class="modal-body">
                    {{-- 上部の案内（<x-form-error-summary />）は出さない。モーダルは画面に収まり、
                         欄がすべて一度に見えるため（設計書 §2-7「モーダルの中のフォーム」）。
                         欄の赤枠と欄の下の文言は、$shouldReopen の条件で該当トレーニーの
                         モーダルにだけ出す。 --}}
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="measured_date-{{ $trainee->id }}" class="form-label">計測日 <span class="text-danger">*</span></label>
                            {{-- Flatpickr（.datepicker）は altInput を使っていないためオリジナルの
                                 input が残り、兄弟セレクタで invalid-feedback が自動表示される。
                                 block は付けない（3-1 と同じ扱い）。 --}}
                            <input type="text" class="form-control datepicker @if($shouldReopen) @error('measured_date') is-invalid @enderror @endif"
                                   id="measured_date-{{ $trainee->id }}" name="measured_date"
                                   value="{{ $shouldReopen ? old('measured_date') : '' }}"
                                   placeholder="例: 2026-09-15" pattern="\d{4}-\d{2}-\d{2}" maxlength="10"
                                   required autocomplete="off">
                            @if($shouldReopen)
                                <x-form-error field="measured_date" />
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label for="measured_time-{{ $trainee->id }}" class="form-label">計測時刻 <span class="text-danger">*</span></label>
                            <input type="time" class="form-control @if($shouldReopen) @error('measured_time') is-invalid @enderror @endif"
                                   id="measured_time-{{ $trainee->id }}" name="measured_time"
                                   value="{{ $shouldReopen ? old('measured_time') : '' }}"
                                   required autocomplete="off">
                            @if($shouldReopen)
                                <x-form-error field="measured_time" />
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label for="weight_kg-{{ $trainee->id }}" class="form-label">体重（kg） <span class="text-danger">*</span></label>
                            {{-- 単位（kg）はラベルの括弧で示している（input-group では
                                 ないため has-validation は不要）。is-invalid の兄弟セレクタで
                                 invalid-feedback が自動表示される。block は付けない。 --}}
                            <input type="number" step="0.01" min="0.01" max="999.99"
                                   class="form-control @if($shouldReopen) @error('weight_kg') is-invalid @enderror @endif"
                                   id="weight_kg-{{ $trainee->id }}" name="weight_kg"
                                   value="{{ $shouldReopen ? old('weight_kg') : '' }}"
                                   placeholder="例: 12.35"
                                   required autocomplete="off">
                            @if($shouldReopen)
                                <x-form-error field="weight_kg" />
                            @endif
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success" id="measurementSubmitBtn-{{ $trainee->id }}">登録</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    // トレーニー ID を IIFE のクロージャに捕捉（Blade の foreach で複数回 include
    // されるため、各インスタンスが自分の trainee_id を保持する必要がある）。
    const traineeId = @json((int) $trainee->id);

    // 辞書の初期化。二重登録防止：同じトレーニー ID で既に登録済みなら早期リターン
    // （Blade の include の重複ケースなど、想定外の二重呼び出しからの保険）。
    if (!window.measurementModals) window.measurementModals = {};
    if (window.measurementModals[traineeId] && typeof window.measurementModals[traineeId].openForCreate === 'function') return;

    // Blade から埋め込む定数
    const STORE_URL = @json($storeUrl);
    // 編集時の URL テンプレート。プレースホルダを実 ID で置換する。
    const UPDATE_URL_TEMPLATE = @json(route('trainee-measurements.update', ['measurement' => '__ID__']));

    // 新規登録時の初期日時は `openForCreate()` 内で **`new Date()` から組み立てる**
    // （設計書 S-0309「新規登録時の初期値」参照）。以前は $defaultMeasuredDate /
    // $defaultMeasuredTime としてコントローラから渡していたが、ページ読み込み時に
    // 値が確定するため画面を開いたまま時間が経つと古い日時が入る不具合があった。
    // モーダルを開く瞬間の**ブラウザ時刻**を使うため、JS 側でその都度組み立てる。
    function pad2(n) { return String(n).padStart(2, '0'); }
    function nowDateString() {
        // 'YYYY-MM-DD' を返す（ブラウザのローカル時刻）。ゼロ埋め必須
        // （「2026-9-5」ではなく「2026-09-05」）。
        const d = new Date();
        return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
    }
    function nowTimeString() {
        // 'HH:MM' を返す（ブラウザのローカル時刻）。ゼロ埋め必須（「9:5」ではなく「09:05」）。
        const d = new Date();
        return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
    }

    let modalEl = null;
    let modal = null;
    let formEl = null;
    let methodInput = null;
    let idInput = null;
    let submitBtn = null;
    let titleEl = null;
    let dateInput = null;
    let timeInput = null;
    let weightInput = null;

    function initRefs() {
        if (modalEl) return;
        modalEl = document.getElementById('measurementModal-' + traineeId);
        if (!modalEl) return;
        modal = new bootstrap.Modal(modalEl);
        formEl = document.getElementById('measurementForm-' + traineeId);
        methodInput = document.getElementById('measurementFormMethodInput-' + traineeId);
        idInput = document.getElementById('measurementFormIdInput-' + traineeId);
        submitBtn = document.getElementById('measurementSubmitBtn-' + traineeId);
        titleEl = document.getElementById('measurementModalLabel-' + traineeId);
        dateInput = document.getElementById('measured_date-' + traineeId);
        timeInput = document.getElementById('measured_time-' + traineeId);
        weightInput = document.getElementById('weight_kg-' + traineeId);
    }

    function openForCreate() {
        initRefs();
        if (!modal) return;
        formEl.action = STORE_URL;
        methodInput.value = '';
        idInput.value = '';
        titleEl.textContent = '計測値の登録';
        submitBtn.textContent = '登録';
        // モーダルを開いた瞬間の日時を初期値にする（設計書 S-0309 参照）。
        dateInput.value = nowDateString();
        timeInput.value = nowTimeString();
        weightInput.value = '';
        modal.show();
    }

    function openForEdit(data) {
        // data: { id, measuredDate, measuredTime, weightKg }
        //   ボタンの dataset から渡す（data-* 属性はキャメルケースになる）
        initRefs();
        if (!modal) return;
        formEl.action = UPDATE_URL_TEMPLATE.replace('__ID__', encodeURIComponent(data.id));
        methodInput.value = 'PUT';
        idInput.value = data.id;
        titleEl.textContent = '計測値の編集';
        submitBtn.textContent = '更新';
        dateInput.value = data.measuredDate || '';
        timeInput.value = data.measuredTime || '';
        weightInput.value = data.weightKg || '';
        modal.show();
    }

    window.measurementModals[traineeId] = { openForCreate: openForCreate, openForEdit: openForEdit };

    // バリデーションエラーで再描画されたときにモーダルを自動で開き直す。
    // Blade 側で $shouldReopen （$hasMeasurementError かつ old('_trainee_id') が
    // 自分の ID と一致）を判定して JS に渡している。複数トレーニーが並ぶ S-0305 でも、
    // エラーが起きたトレーニーのモーダルだけが開く。
    document.addEventListener('DOMContentLoaded', function () {
        initRefs();
        @if($shouldReopen)
            const oldMeasurementId = @json($oldMeasurementId);
            if (oldMeasurementId) {
                // 編集モードで開き直す。入力値は old() で既に埋まっているので、
                // フォームの action / _method / _measurement_id をセットしてタイトルを更新するだけ。
                formEl.action = UPDATE_URL_TEMPLATE.replace('__ID__', encodeURIComponent(oldMeasurementId));
                methodInput.value = 'PUT';
                idInput.value = oldMeasurementId;
                titleEl.textContent = '計測値の編集';
                submitBtn.textContent = '更新';
            } else {
                // 登録モードで開き直す。フォームは初期状態のまま（action = STORE_URL、_method 空）。
                titleEl.textContent = '計測値の登録';
                submitBtn.textContent = '登録';
            }
            modal.show();
        @endif
    });
})();
</script>
@endpush
