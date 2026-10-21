<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 役割別セッション設定
    |--------------------------------------------------------------------------
    | トレーナー（内部）とクライアント（外部）で lifetime / Cookie 名 / domain を
    | 分離する。ConfigureSessionByRole ミドルウェアが判定結果に応じて動的に
    | config('session.*') を上書きする。
    |
    | domain は env 未設定時に null を返す。ローカルでは両方 null で現ホスト限定 Cookie
    | として動作。本番はサブドメインごとに env で設定する。
    |
    | lifetime は「最後に使ってから」の分（使うたびに延びる）。2026-10-21 変更：会員様は月に 1 回でも
    | 開けばログアウトしないように 43200 分（30 日）、トレーナーは 720 分（12 時間）。その前はトレーナー 120 分・会員 60 分。
    | absolute_lifetime（トレーナーだけ。2026-10-21 追加）は「ログインしてから」の分。使い続けていても、ログインしてから
    | この長さでログアウトする（1 日 1 回はログインし直す。残業を考えて 12 時間）。EnforceAbsoluteSessionLifetime が見る。
    | 0 以下なら数えない（テストで日付を何日も進めるときなど）。
    | 設計書 requirements.md 11-2-3・architecture.md 2-7
    */

    'trainer' => [
        'lifetime' => (int) env('TRAINER_SESSION_LIFETIME', 720),
        'absolute_lifetime' => (int) env('TRAINER_SESSION_ABSOLUTE_LIFETIME', 720),
        'cookie'   => env('TRAINER_SESSION_COOKIE', 'trs01-staff-session'),
        'domain'   => env('TRAINER_SESSION_DOMAIN'),
    ],

    'client' => [
        'lifetime' => (int) env('CLIENT_SESSION_LIFETIME', 43200),
        'cookie'   => env('CLIENT_SESSION_COOKIE', 'trs01-client-session'),
        'domain'   => env('CLIENT_SESSION_DOMAIN'),
    ],
];
