{{-- 愛犬アンケート案内取消確認モーダル（S-0305-M06。2026-10 追加。要件定義書 6-16-9）。
     S-0305 会員詳細のトレーニーカードだけで使う。取消のあとは会員詳細に戻る。
     見せ方はマイページ登録案内の取消（S-0305-M03）と同じ形。
     経緯：段階3a では S-0309 トレーニー詳細の愛犬アンケートの欄でも共用し、戻り先を return_to
     （client / trainee）で渡していた。2026-10 に操作を会員詳細に寄せたため、S-0309 では使わない

     開き方：会員詳細のトレーニーカードの「取消」ボタンに data-bs-toggle="modal" data-bs-target="#intakeSurveyCancelModal"
     と data-intake-survey-trainee-id="{トレーニーの id}" を付ける。トレーニーが何頭いてもモーダルは
     1 つにし、開いたボタンのトレーニーの id を、送信先の URL のひな形に入れる（契約モーダル
     S-0305-M04 の「URL のひな形を JS で置き換える」方式と同じ）。 --}}
<div class="modal fade" id="intakeSurveyCancelModal" tabindex="-1"
     aria-labelledby="intakeSurveyCancelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="intakeSurveyCancelModalLabel">
                    {{ __('messages.intake_survey_name') }}の案内の取消
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    発行済みの{{ __('messages.intake_survey_name') }}の案内が使えなくなります。<br>
                    すでにお渡しした案内からは回答できなくなります。
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button>
                <form method="POST" action="" class="d-inline m-0" id="intakeSurveyCancelForm">
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
    const DESTROY_URL_TEMPLATE = @json(route('trainee-intake-survey-tokens.destroy', ['trainee' => '__ID__']));
    const modalEl = document.getElementById('intakeSurveyCancelModal');
    const formEl = document.getElementById('intakeSurveyCancelForm');
    if (!modalEl || !formEl) {
        return;
    }
    modalEl.addEventListener('show.bs.modal', function (event) {
        const id = event.relatedTarget ? event.relatedTarget.getAttribute('data-intake-survey-trainee-id') : null;
        formEl.action = id ? DESTROY_URL_TEMPLATE.replace('__ID__', encodeURIComponent(id)) : '';
    });
})();
</script>
@endpush
