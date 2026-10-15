<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@hasSection('title')@yield('title') - @endif{{ config('app.trainer_portal_name') }}</title>
    {{-- 印刷用ページ専用レイアウト（設計書 S-0307）。
         layouts.app は継承せず、ナビゲーションバー・フッターを最初から出さない。
         `@media print` は原則として要素の表示切替には使わず、印刷でしか効かない指定
         （用紙サイズ・余白）にだけ用いる。トレーナーが画面で確認したものと紙が同じ
         見た目になるように、通常表示のスタイルも同一とする。
         例外：ページ上部の操作エリア（「閉じる」ボタンと「文面をコピー」ボタン）は、
         画面には出して紙には出さないというトレーナー専用の要件があり、代替手段が
         ないため Bootstrap の `d-print-none`（実体は `@media print { display: none !important; }`）
         で非表示にする。この操作エリアに含める要素だけを例外として認め、
         他の目的では引き続き `@media print` による表示切替を行わない。--}}
    @vite(['resources/sass/app.scss'])
    <style>
        /* A4 縦・上下左右 15mm 余白（brand 統一の余白）。ブラウザの
           印刷プレビューで 1 枚に収まる目安。@page は印刷でしか効かない */
        @page {
            size: A4 portrait;
            margin: 15mm;
        }
        body {
            background: #fff;
            color: #212529;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans JP", sans-serif;
            padding: 24px;
            max-width: 780px;
            margin: 0 auto;
        }
        .print-qr {
            display: flex;
            justify-content: center;
            margin: 0.5rem 0 1rem;
        }
        .print-qr canvas {
            width: 240px;
            height: 240px;
        }
        .print-url {
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
            font-size: 0.95rem;
            word-break: break-all;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.75rem 1rem;
            margin: 0.5rem 0 1rem;
        }
        .print-lead {
            text-align: center;
            font-size: 1.05rem;
            margin: 0.5rem 0 1.5rem;
        }
        .print-divider {
            border: 0;
            border-top: 1px solid #dee2e6;
            margin: 1.25rem 0;
        }
        .print-section-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0.5rem 0 0.5rem;
        }
        .print-steps {
            padding-left: 1.5rem;
            line-height: 1.8;
            margin: 0;
        }
        .print-expiry-label {
            text-align: center;
            color: #6c757d;
            font-size: 0.95rem;
            margin: 0.5rem 0 0.25rem;
        }
        .print-expiry-date {
            text-align: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 1.5rem;
        }
        .print-company {
            text-align: center;
            color: #6c757d;
            font-size: 0.95rem;
            margin: 1.5rem 0 0;
        }
        @media print {
            body {
                padding: 0;
                max-width: none;
            }
        }
    </style>
</head>
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
