<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'Asia/Tokyo',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trainer Portal Branding（画面設計書 §2-3「ブラウザタイトル」）
    |--------------------------------------------------------------------------
    |
    | トレーナー側画面のブランド関連文字列。
    |
    | - trainer_portal_name: layouts/app・layouts/guest・layouts/error の
    |   <title> 末尾サフィックスに使用する（画面設計書 §2-3）。
    |
    | APP_NAME を流用しない理由: APP_NAME は MAIL_FROM_NAME / VITE_APP_NAME /
    | cache・session の Str::slug プレフィックスに波及しており、
    | ブラウザタイトルの変更が上記に影響しないよう独立キーで扱う。
    |
    */

    'trainer_portal_name' => env('TRAINER_PORTAL_NAME', 'トレーニング記録管理システム'),

    /*
    |--------------------------------------------------------------------------
    | Client Portal Branding（設計書 §9-1 / §9-2）
    |--------------------------------------------------------------------------
    |
    | クライアントポータルのブランド関連文字列。Blade にベタ書きせず、
    | env 経由で切り替えできるようにする。
    |
    | - client_portal_name: ログイン画面のワードマーク・タイトルに表示する
    |   プロダクト名。設計書 §9-1 の暫定値は「トレーニング記録」（8 文字）。
    |   想定文字数 3〜7 文字だが 8 文字でも組めるようレイアウト側で対応。
    | - client_portal_company: フッターに表示するトレーニング提供会社名
    |   （開発会社ではない）。設計書 §9-2 に従い、null / 空の場合は
    |   Blade 側で <footer> ブロックごと出力しない。
    | - client_portal_logo: ヘッダー帯のブランド位置に表示するロゴ画像の
    |   URL（`public/` からの相対または絶対 URL）。空文字を設定した場合はテキスト
    |   （client_portal_name）を表示する。**2026-10-18 変更**：ロゴが確定したため、
    |   env が未設定のときは Mirai Dog Wellness のロゴ（public/images/mirai-dog-wellness-logo.png）
    |   を既定にした（本番の .env を変えずに git pull だけで切り替わるように）。その前の既定は
    |   null（設計書 §9-3 の暫定でロゴ画像なし）。`public/` の中の画像なら、<img> の
    |   width・height を画像のファイルから読む（App\Support\ClientPortalLogo）。
    | - client_portal_privacy_url: プライバシーポリシー文書の URL。お客様が
    |   最初に個人情報を預ける画面（S-1405 メールアドレス登録・S-1403 初回設定）で、
    |   送信ボタンの手前に「送信すると、[プライバシーポリシー]に同意したものと
    |   みなされます。」の同意文を表示するときに使う。null / 空の場合は、
    |   Blade 側で同意文のブロックごと出力しない（現状は文書側の準備中のため空。
    |   訓練所のホームページに掲載された時点で URL を設定するだけで表示される）。
    |   将来「利用規約」など他の同意リンクを増やす場合は、この設定値と並べて
    |   `client_portal_terms_url` 等を追加すれば同じ形で扱える。
    | - client_portal_reply_to: お客様に送るメールの Reply-To（返信先）アドレス。
    |   お客様が受信メールに返信したときの届き先。訓練所の問い合わせ用アドレス
    |   （info@... など）が確定した時点でこの値を設定する。null / 空の場合は
    |   Reply-To を指定せず、Envelope 側の From アドレス（noreply@...）に返信
    |   されることになる。従前は開発会社（インマイライフ）アドレスがハードコード
    |   されていたが、お客様の返信は事業者に届くべきであり、事業者側のアドレスが
    |   決まるまでは空にしておく（次点として From への返信になる）。
    |
    */

    'client_portal_name' => env('CLIENT_PORTAL_NAME', 'トレーニング記録'),

    'client_portal_company' => env('CLIENT_PORTAL_COMPANY'),

    'client_portal_logo' => env('CLIENT_PORTAL_LOGO', '/images/mirai-dog-wellness-logo.png'),

    'client_portal_privacy_url' => env('CLIENT_PORTAL_PRIVACY_URL'),

    'client_portal_reply_to' => env('CLIENT_PORTAL_REPLY_TO'),

];
