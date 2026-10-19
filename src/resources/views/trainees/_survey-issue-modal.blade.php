{{-- 案内の発行モーダル（S-0305-M08。2026-10-19 追加。要件定義書 6-16-8・6-16-11、設計書 S-0305 セクション3「案内の操作」）。
     会員詳細のトレーニーの欄の［案内を発行］から開き、愛犬アンケートか愛犬性格診断のどちらか 1 つを選んで発行する。
     ユーザーレビューで、トレーニーの欄に種類ごとの［…を発行］［…を表示］［取消］がばらばらに並び分かりにくかったため、
     ［案内を発行］1 つとこのモーダルにまとめた。1 頭に出しておける案内は 2 つの種類を合わせて 1 つまで（サーバーでも守る）。
     2026-10-19 追加：選択肢に「愛犬アンケート＆愛犬性格診断」（2 つの案内を同時に作って結び付け、1 つのリンクで続けて答えてもらう）を
     足して 3 つにした（運用上、2 つを同時に送りたいことがあるため）。組み合わせは 1 つの案内として数える。

     - 開き方：ボタンに data-bs-toggle="modal" data-bs-target="#surveyIssueModal" と、data-survey-issue-trainee-id
       （トレーニーの id）・data-survey-issue-trainee-name（表示名）を付ける。トレーニーが何頭いてもモーダルは 1 つにし、
       開いたボタンのトレーニーの id を送信先の URL のひな形に入れる（取消のモーダル M06・M07 と同じ作り）
     - 見出しは「{表示名}の案内を発行」（どのトレーニーの案内かを確かめられるように）
     - 種類はラジオボタン。開いたときはどちらも選ばない（どちらを出すかを毎回選んでもらうため。間違えると、取消してからでないと
       もう一方を出せない）。選ぶまで［発行する］は押せない。種類を選ぶと送信先を、選んだ種類の今の発行のルートに切り替える
     - 発行の確認のモーダルはもともとないので、このモーダルが確認を兼ねる。押したらボタンを押せなくして二重送信を防ぐ
     - ［キャンセル］はほかの確認のモーダルと同じグレーの白抜き
     - 「マイページ登録の案内も発行する」（2026-10-19 追加。要件定義書 6-16-18）：会員のマイページが「未登録」「登録待ち」（期限切れも）の
       ときだけ、選択肢の下に出す（$client は会員詳細の会員。状態は ClientController::show で読み込み済み）。開くたびにオフに戻す。
       オンで発行すると、マイページ登録の案内も用意し、お客様が回答を終えた完了の画面でマイページの登録へ誘導する。3 つの種類のどれでも使える --}}
<div class="modal fade" id="surveyIssueModal" tabindex="-1"
     aria-labelledby="surveyIssueModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="" class="m-0" id="surveyIssueForm">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="surveyIssueModalLabel"><span data-survey-issue-name></span>の案内を発行</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <fieldset>
                        <legend class="visually-hidden">案内の種類</legend>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="survey_kind" id="surveyIssueKindIntake" value="intake">
                            <label class="form-check-label" for="surveyIssueKindIntake">{{ __('messages.intake_survey_name') }}</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="survey_kind" id="surveyIssueKindPersonality" value="personality">
                            <label class="form-check-label" for="surveyIssueKindPersonality">{{ __('messages.personality_diagnosis_name') }}</label>
                        </div>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="survey_kind" id="surveyIssueKindCombined" value="combined">
                            <label class="form-check-label" for="surveyIssueKindCombined">{{ \App\Support\CombinedSurveyName::name() }}</label>
                        </div>
                    </fieldset>
                    @if(\App\Services\SurveyMypageRegistrationService::canAttach($client))
                        <div class="form-check mt-3 pt-3 border-top" data-survey-issue-mypage>
                            <input class="form-check-input" type="checkbox" name="with_mypage_registration" value="1" id="surveyIssueWithMypage"
                                   aria-describedby="surveyIssueWithMypageHelp">
                            <label class="form-check-label" for="surveyIssueWithMypage">マイページ登録の案内も発行する</label>
                            <div class="form-text" id="surveyIssueWithMypageHelp">回答のあと、マイページの登録をご案内します。</div>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary" id="surveyIssueSubmit" disabled>発行する</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    // 種類ごとの発行の送信先の URL のひな形。プレースホルダを、開いたボタンのトレーニーの id で置き換える
    const STORE_URL_TEMPLATES = {
        intake: @json(route('trainee-intake-survey-tokens.store', ['trainee' => '__ID__'])),
        personality: @json(route('trainee-personality-diagnosis-tokens.store', ['trainee' => '__ID__'])),
        combined: @json(route('trainee-combined-survey-tokens.store', ['trainee' => '__ID__'])),
    };
    const modalEl = document.getElementById('surveyIssueModal');
    const formEl = document.getElementById('surveyIssueForm');
    const submitEl = document.getElementById('surveyIssueSubmit');
    if (!modalEl || !formEl || !submitEl) {
        return;
    }
    const nameEl = modalEl.querySelector('[data-survey-issue-name]');
    const radios = formEl.querySelectorAll('input[name="survey_kind"]');
    let traineeId = null;

    // 選んだ種類の送信先にする。選んでいなければ送れない
    const updateAction = function () {
        const checked = formEl.querySelector('input[name="survey_kind"]:checked');
        const template = checked ? STORE_URL_TEMPLATES[checked.value] : null;
        formEl.action = template && traineeId ? template.replace('__ID__', encodeURIComponent(traineeId)) : '';
        submitEl.disabled = formEl.action === '' || formEl.getAttribute('action') === '';
    };

    modalEl.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        traineeId = button ? button.getAttribute('data-survey-issue-trainee-id') : null;
        nameEl.textContent = button ? button.getAttribute('data-survey-issue-trainee-name') : '';
        // 開くたびにどちらも選ばない状態に戻す（マイページ登録の案内のチェックもオフに戻す。2026-10-19 追加）
        radios.forEach(function (radio) { radio.checked = false; });
        const mypageEl = formEl.querySelector('input[name="with_mypage_registration"]');
        if (mypageEl) {
            mypageEl.checked = false;
        }
        updateAction();
    });
    radios.forEach(function (radio) { radio.addEventListener('change', updateAction); });

    formEl.addEventListener('submit', function (event) {
        if (!formEl.getAttribute('action')) {
            event.preventDefault();
            return;
        }
        // 二重送信を防ぐ
        submitEl.disabled = true;
    });
})();
</script>
@endpush
