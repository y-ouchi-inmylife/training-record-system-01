{{-- 番号ごとのページがないエラー（5xx。405・413 など）の受け皿（2026-10-21 追加。Laravel の既定の英語のページを出さないため）。
     作りは layouts/error.blade.php --}}
@include('layouts.error', ['status' => $exception->getStatusCode()])
