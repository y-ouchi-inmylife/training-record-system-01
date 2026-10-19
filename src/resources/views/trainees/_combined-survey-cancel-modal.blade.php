{{-- 愛犬アンケート＆愛犬性格診断の案内の取消確認モーダル（S-0305-M09。2026-10-19 追加。要件定義書 6-16-9・6-16-12）。
     会員詳細のトレーニーの欄の「案内中　愛犬アンケート＆愛犬性格診断」の［取消］から開く。まだ答えていない分（2 つとも回答待ちなので
     2 つとも）をまとめて取り消す。愛犬アンケートだけ答えたあとの残りの愛犬性格診断は、愛犬性格診断の取消（M07）を使う。
     作りは愛犬性格診断の案内の取消（M07。trainees/_personality-diagnosis-cancel-modal.blade.php）と同じ。
     開き方：［取消］に data-bs-toggle="modal" data-bs-target="#combinedSurveyCancelModal" と
     data-combined-survey-trainee-id="{トレーニーの id}" を付ける --}}
<div class="modal fade" id="combinedSurveyCancelModal" tabindex="-1"
     aria-labelledby="combinedSurveyCancelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="combinedSurveyCancelModalLabel">
                    {{ \App\Support\CombinedSurveyName::name() }}の案内の取消
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    発行済みの{{ \App\Support\CombinedSurveyName::name() }}の案内のうち、まだお答えいただいていない分がまとめて使えなくなります。<br>
                    すでにお渡しした案内からは回答できなくなります。
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button>
                <form method="POST" action="" class="d-inline m-0" id="combinedSurveyCancelForm">
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
    const DESTROY_URL_TEMPLATE = @json(route('trainee-combined-survey-tokens.destroy', ['trainee' => '__ID__']));
    const modalEl = document.getElementById('combinedSurveyCancelModal');
    const formEl = document.getElementById('combinedSurveyCancelForm');
    if (!modalEl || !formEl) {
        return;
    }
    modalEl.addEventListener('show.bs.modal', function (event) {
        const id = event.relatedTarget ? event.relatedTarget.getAttribute('data-combined-survey-trainee-id') : null;
        formEl.action = id ? DESTROY_URL_TEMPLATE.replace('__ID__', encodeURIComponent(id)) : '';
    });
})();
</script>
@endpush
