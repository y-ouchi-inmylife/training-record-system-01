@extends('layouts.app')

@section('title', '事業所情報の設定')

{{-- 事業所情報の設定画面（S-1601。2026-11 追加。設計書 screen-design.md 3-16。2026-11 変更：画面の名前を改めた。
     URL・ルート名・設定のキー・クラス名などの内部の名前は schedule のまま）。
     営業開始時刻・営業終了時刻は、利用を登録するときの開始時刻・終了時刻の初期値になる。
     - 小さなフォームなので、上部の案内（x-form-error-summary）は置かない（§2-7 の例外）
     - 2 つの時刻は横に並べ、範囲のエラーは各欄の下ではなく、まとまりの下に出す（§2-7 の但し書き。エラーの欄は赤枠だけ）
     - 説明文は置かない（2026-11 変更：その前は時刻のまとまりの下に出していたが、分かりにくいため消した）
     - 保存していない変更があるときの離脱の確認は、要約プロンプト（S-0601）と同じ UnsavedChangesGuard --}}

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4" style="max-width: 700px;">
                <h2 class="mb-0">事業所情報の設定</h2>
                <div class="d-flex gap-2">
                    <button type="submit" form="scheduleSettingForm" class="btn btn-success text-nowrap">更新</button>
                </div>
            </div>

            {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7） --}}
            <form id="scheduleSettingForm" method="POST" action="{{ route('settings.schedule.update') }}" novalidate>
                @csrf
                @method('PUT')

                <div class="d-flex flex-wrap align-items-end column-gap-2 row-gap-2" data-schedule-time-range>
                    <div>
                        <label for="schedule_open_time" class="form-label">営業開始時刻 <span class="text-danger">*</span></label>
                        <input type="time" class="form-control @error('schedule_open_time') is-invalid @enderror"
                               id="schedule_open_time" name="schedule_open_time"
                               value="{{ old('schedule_open_time', $openTime) }}"
                               required autocomplete="off">
                    </div>
                    <div class="pb-2" aria-hidden="true">〜</div>
                    <div>
                        <label for="schedule_close_time" class="form-label">営業終了時刻 <span class="text-danger">*</span></label>
                        <input type="time" class="form-control @error('schedule_close_time') is-invalid @enderror"
                               id="schedule_close_time" name="schedule_close_time"
                               value="{{ old('schedule_close_time', $closeTime) }}"
                               required autocomplete="off">
                    </div>
                </div>
                {{-- 範囲のエラーは、まとまり（営業開始時刻〜営業終了時刻）の下に出す --}}
                <x-form-error field="schedule_open_time" block />
                <x-form-error field="schedule_close_time" block />

            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 未保存変更警告（要約プロンプト S-0601 と同じ）
    new window.UnsavedChangesGuard({
        formSelector: '#scheduleSettingForm',
        leaveLinkSelector: '.js-leave-link'
    }).init();
});
</script>
@endpush
