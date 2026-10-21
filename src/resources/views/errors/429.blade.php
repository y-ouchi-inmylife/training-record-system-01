{{-- エラーページ（429）。作りは layouts/error.blade.php（2026-10-21 変更。設計書 screen-design.md「エラーページの共通の作り」） --}}
@include('layouts.error', ['status' => 429])
