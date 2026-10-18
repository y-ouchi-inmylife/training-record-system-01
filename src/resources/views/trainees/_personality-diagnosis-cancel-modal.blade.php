{{-- 性格診断の案内の取消確認モーダル（S-0305-M07。2026-10 追加。要件定義書 6-16-12）。
     S-0305 会員詳細のトレーニーカードの「性格診断」の行だけで使う。取消のあとは会員詳細に戻る。
     受付アンケート案内の取消（S-0305-M06。trainees/_intake-survey-cancel-modal.blade.php）と同じ作り
     （キャンセルの見た目も M06 と同じ白抜き。4-4 の塗りにそろえるときは M02・M03・M06 と一緒に直す）。

     開き方：「性格診断」の行の「取消」ボタンに data-bs-toggle="modal" data-bs-target="#personalityDiagnosisCancelModal"
     と data-personality-diagnosis-trainee-id="{トレーニーの id}" を付ける。トレーニーが何頭いてもモーダルは
     1 つにし、開いたボタンのトレーニーの id を、送信先の URL のひな形に入れる（契約モーダル
     S-0305-M04 の「URL のひな形を JS で置き換える」方式と同じ）。 --}}
<div class="modal fade" id="personalityDiagnosisCancelModal" tabindex="-1"
     aria-labelledby="personalityDiagnosisCancelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="personalityDiagnosisCancelModalLabel">
                    {{ __('messages.personality_diagnosis_name') }}の案内の取消
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    発行済みの{{ __('messages.personality_diagnosis_name') }}の案内が使えなくなります。<br>
                    すでにお渡しした案内からは回答できなくなります。
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button>
                <form method="POST" action="" class="d-inline m-0" id="personalityDiagnosisCancelForm">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">取り消す</button>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    // 取消の送信先の URL のひな形。プレースホルダを、開いたボタンのトレーニーの id で置き換える
    const DESTROY_URL_TEMPLATE = @json(route('trainee-personality-diagnosis-tokens.destroy', ['trainee' => '__ID__']));
    const modalEl = document.getElementById('personalityDiagnosisCancelModal');
    const formEl = document.getElementById('personalityDiagnosisCancelForm');
    if (!modalEl || !formEl) {
        return;
    }
    modalEl.addEventListener('show.bs.modal', function (event) {
        const id = event.relatedTarget ? event.relatedTarget.getAttribute('data-personality-diagnosis-trainee-id') : null;
        formEl.action = id ? DESTROY_URL_TEMPLATE.replace('__ID__', encodeURIComponent(id)) : '';
    });
})();
</script>
@endpush
