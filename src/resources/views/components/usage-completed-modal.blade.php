{{-- 利用の登録・更新の完了のメッセージボックス（S-0305-M10。2026-11 追加。設計書 S-0305-M10）。
     利用を登録・更新して会員詳細に戻ったとき、その利用が今日以降なら自動で開く（画面上部の完了メッセージは出さない）。
     中身はフラッシュ（ClientUsageController::COMPLETED_SESSION_KEY）のもので、メモは入っていない。

     使い方：<x-usage-completed-modal :completed="session('usage_completed')" />
       completed：title・date_time・label・google_calendar_url

     ［Googleカレンダーに追加］は別タブで開くリンク。data-bs-dismiss は使わない（a 要素ではリンクを開く動きを止めてしまうため）。
     押したときに JS で閉じる --}}
@props(['completed'])

<div class="modal fade" id="usageCompletedModal" tabindex="-1" aria-labelledby="usageCompletedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="usageCompletedModalLabel">{{ $completed['title'] }}</h5>
            </div>
            <div class="modal-body">
                <p class="mb-1">{{ $completed['date_time'] }}</p>
                <p class="mb-0">{{ $completed['label'] }}</p>
            </div>
            <div class="modal-footer">
                <x-google-calendar-link :url="$completed['google_calendar_url']" :with-text="true" id="usageCompletedGoogleCalendar" class="btn btn-primary" />
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modalEl = document.getElementById('usageCompletedModal');
        const linkEl = document.getElementById('usageCompletedGoogleCalendar');
        const modal = new bootstrap.Modal(modalEl);
        modalEl.addEventListener('shown.bs.modal', function () {
            linkEl.focus();
        });
        linkEl.addEventListener('click', function () {
            modal.hide();
        });
        modal.show();
    });
</script>
@endpush
