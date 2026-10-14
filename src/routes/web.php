<?php

use App\Http\Controllers\AccessLogController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\LoginController as ClientLoginController;
use App\Http\Controllers\Client\LogoutController as ClientLogoutController;
use App\Http\Controllers\ClientContractController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientEmailController;
use App\Http\Controllers\ClientEmailRegistrationTokenController;
use App\Http\Controllers\ClientUsageController;
use App\Http\Controllers\TrainingRecordController;
use App\Http\Controllers\TrainerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IpRestrictionController;
use App\Http\Controllers\MediaRecordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AudioRecordController;
use App\Http\Controllers\RecordingV2Controller;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SummaryPromptController;
use App\Http\Controllers\TraineeController;
use App\Http\Controllers\TraineeIntakeSurveyTokenController;
use App\Http\Controllers\TraineeMeasurementController;
use App\Http\Controllers\UsageStatsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| トレーナー用サブドメイン（内部）
|--------------------------------------------------------------------------
| トレーナー側の全ルートを Route::domain で囲む。ローカルは両ホストが localhost
| に解決されるため、既存の動作は変わらない。
*/
Route::domain(config('subdomain.trainer_host'))->middleware('check-ip')->group(function () {

    // ルートURLはログイン画面にリダイレクト
    Route::redirect('/', '/login');

    /*
    |--------------------------------------------------------------------------
    | 認証不要のルート（トレーナー用ログイン）
    |--------------------------------------------------------------------------
    */
    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [LoginController::class, 'login']);
    });

    /*
    |--------------------------------------------------------------------------
    | 認証が必要なルート
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');

        // パスワード変更（初回ログイン時の強制変更対応）
        Route::get('/password/change', [ChangePasswordController::class, 'show'])->name('password.change');
        Route::post('/password/change', [ChangePasswordController::class, 'update'])->name('password.update');

        // マイプロフィール
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::get('/profile/password', [ProfileController::class, 'editPassword'])->name('profile.password.edit');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        /*
        |--------------------------------------------------------------------------
        | 実務トレーナー（管理トレーナー + 一般トレーナー）共通ルート
        | クライアントの個人情報・トレーニング内容を扱うため、system_admin は 403 で弾く
        |--------------------------------------------------------------------------
        */
        Route::middleware('practitioners')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::resource('clients', ClientController::class);
            // マイページ登録案内の発行（6-3-6、段階 4-1）
            Route::post('clients/{client}/email-registration-tokens', [ClientEmailRegistrationTokenController::class, 'store'])
                ->name('client-email-registration-tokens.store');
            // マイページ登録案内の取消（6-3-7）
            Route::delete('clients/{client}/email-registration-tokens', [ClientEmailRegistrationTokenController::class, 'destroy'])
                ->name('client-email-registration-tokens.destroy');
            // マイページ登録案内の表示（印刷ページ S-0307、段階 4-2）
            Route::get('clients/{client}/email-registration-tokens/print', [ClientEmailRegistrationTokenController::class, 'print'])
                ->name('client-email-registration-tokens.print');
            // メールアドレスの削除（6-3-8、段階 4-2）
            Route::delete('clients/{client}/email', [ClientEmailController::class, 'destroy'])
                ->name('client-email.destroy');

            // トレーニー（D-0700、段階①）。会員詳細（S-0305）の中だけに置くため
            // Route::resource は使わず個別に定義する。登録・store は会員 URL 配下、
            // それ以外はフラット URL とする（設計書 api-design.md 参照）。
            Route::get('clients/{client}/trainees/create', [TraineeController::class, 'create'])
                ->name('trainees.create');
            Route::post('clients/{client}/trainees', [TraineeController::class, 'store'])
                ->name('trainees.store');
            Route::get('trainees/{trainee}', [TraineeController::class, 'show'])
                ->name('trainees.show');
            Route::get('trainees/{trainee}/edit', [TraineeController::class, 'edit'])
                ->name('trainees.edit');
            Route::put('trainees/{trainee}', [TraineeController::class, 'update'])
                ->name('trainees.update');
            Route::delete('trainees/{trainee}', [TraineeController::class, 'destroy'])
                ->name('trainees.destroy');

            // 計測値（D-0800、段階②）。create / edit / show の画面遷移は持たない
            // （モーダル内で登録・編集するため。設計書 S-0309）
            Route::post('trainees/{trainee}/measurements', [TraineeMeasurementController::class, 'store'])
                ->name('trainee-measurements.store');
            Route::put('trainee-measurements/{measurement}', [TraineeMeasurementController::class, 'update'])
                ->name('trainee-measurements.update');
            Route::delete('trainee-measurements/{measurement}', [TraineeMeasurementController::class, 'destroy'])
                ->name('trainee-measurements.destroy');

            // 受付アンケート案内（受付アンケート用 URL のトークン。DS-1000。2026-10 追加）。
            // 発行と取消は同じリソース URL に対する POST / DELETE（マイページ登録案内と同じ考え方）。
            Route::post('trainees/{trainee}/intake-survey-tokens', [TraineeIntakeSurveyTokenController::class, 'store'])
                ->name('trainee-intake-survey-tokens.store');
            Route::delete('trainees/{trainee}/intake-survey-tokens', [TraineeIntakeSurveyTokenController::class, 'destroy'])
                ->name('trainee-intake-survey-tokens.destroy');
            // 受付アンケート案内画面（S-0311）。会員詳細の「案内を表示」から別タブで開く
            Route::get('trainees/{trainee}/intake-survey-tokens/print', [TraineeIntakeSurveyTokenController::class, 'print'])
                ->name('trainee-intake-survey-tokens.print');

            // 契約（D-0900）・利用（D-1000）。会員詳細（S-0305）の「回数」カードのモーダルで
            // 登録・編集・削除する（create / edit / show の画面遷移は持たない）。
            // 計測値と同じく、登録は会員 URL 配下、それ以外はフラット URL とする（api-design.md 参照）。
            Route::post('clients/{client}/contracts', [ClientContractController::class, 'store'])
                ->name('client-contracts.store');
            Route::put('client-contracts/{contract}', [ClientContractController::class, 'update'])
                ->name('client-contracts.update');
            Route::delete('client-contracts/{contract}', [ClientContractController::class, 'destroy'])
                ->name('client-contracts.destroy');
            Route::post('clients/{client}/usages', [ClientUsageController::class, 'store'])
                ->name('client-usages.store');
            Route::put('client-usages/{usage}', [ClientUsageController::class, 'update'])
                ->name('client-usages.update');
            Route::delete('client-usages/{usage}', [ClientUsageController::class, 'destroy'])
                ->name('client-usages.destroy');

            Route::resource('training-records', TrainingRecordController::class);

            // 旧録音画面 → 録音【改良版】にリダイレクト
            Route::get('recording', function () {
                return redirect()->route('recording-v2.index');
            })->name('recording');

            // 録音【改良版】（全トレーナーがアクセス可能）
            Route::prefix('recording-v2')->name('recording-v2.')->group(function () {
                Route::get('/', [RecordingV2Controller::class, 'index'])->name('index');
                Route::post('/start', [RecordingV2Controller::class, 'start'])->name('start');
                Route::get('/session', [RecordingV2Controller::class, 'session'])->name('session');
            });

            // メディア管理（全トレーナーがアクセス可能）
            // 一覧・登録・詳細モーダル経由の更新/削除。再生(play)は api.php 側。
            Route::get('media-records', [MediaRecordController::class, 'index'])->name('media-records.index');
            Route::put('media-records/{mediaRecord}', [MediaRecordController::class, 'update'])->name('media-records.update');
            Route::delete('media-records/{mediaRecord}', [MediaRecordController::class, 'destroy'])->name('media-records.destroy');

            // 音声管理（全トレーナーがアクセス可能）
            Route::get('audio-records', [AudioRecordController::class, 'index'])->name('audio-records.index');
            Route::post('audio-records/upload', [AudioRecordController::class, 'uploadStore'])->name('audio-records.upload.store');
            Route::post('audio-records/recording', [AudioRecordController::class, 'recordingStore'])->name('audio-records.recording.store');
            Route::get('audio-records/upload/create', [AudioRecordController::class, 'uploadCreate'])->name('audio-records.upload.create');
            Route::get('audio-records/text-paste/create', [AudioRecordController::class, 'textPasteCreate'])->name('audio-records.text-paste.create');
            Route::post('audio-records/text-paste', [AudioRecordController::class, 'textPasteStore'])->name('audio-records.text-paste.store');
            Route::get('audio-records/{audioRecord}', [AudioRecordController::class, 'show'])->name('audio-records.show');
            Route::put('audio-records/{audioRecord}', [AudioRecordController::class, 'update'])->name('audio-records.update');
            Route::delete('audio-records/{audioRecord}', [AudioRecordController::class, 'destroy'])->name('audio-records.destroy');
            Route::delete('audio-records/{audioRecord}/delete-audio', [AudioRecordController::class, 'deleteAudioOnly'])->name('audio-records.delete-audio');
            Route::get('audio-records/{audioRecord}/play', [AudioRecordController::class, 'play'])->name('audio-records.play');

            // レポート
            Route::get('statistics/clients', [StatisticsController::class, 'clients'])->name('statistics.clients');
        });

        /*
        |--------------------------------------------------------------------------
        | 管理者専用ルート（adminロールのみ、system_adminはアクセス不可）
        |--------------------------------------------------------------------------
        */
        Route::middleware('admin-only')->group(function () {
            // 要約プロンプト設定
            Route::get('settings/summary-prompts', [SummaryPromptController::class, 'edit'])->name('settings.summary-prompts.edit');
            Route::put('settings/summary-prompts', [SummaryPromptController::class, 'update'])->name('settings.summary-prompts.update');

            // トレーナー操作履歴
            Route::get('access-logs', [AccessLogController::class, 'index'])->name('access-logs.index');
        });

        /*
        |--------------------------------------------------------------------------
        | 管理者・システム管理者ルート（admin + system_admin、staffはアクセス不可）
        |--------------------------------------------------------------------------
        */
        Route::middleware('admin')->group(function () {
            // トレーナーアカウント管理
            Route::resource('trainers', TrainerController::class)->except(['show']);
            Route::get('trainers/{trainer}/reset-password', [TrainerController::class, 'showResetPassword'])->name('trainers.reset-password');
            Route::put('trainers/{trainer}/reset-password', [TrainerController::class, 'resetPassword'])->name('trainers.reset-password.update');
            Route::patch('trainers/{trainer}/unlock', [TrainerController::class, 'unlock'])->name('trainers.unlock');
            Route::patch('trainers/{trainer}/toggle-active', [TrainerController::class, 'toggleActive'])->name('trainers.toggle-active');
            Route::patch('trainers/{trainer}/move-up', [TrainerController::class, 'moveUp'])->name('trainers.move-up');
            Route::patch('trainers/{trainer}/move-down', [TrainerController::class, 'moveDown'])->name('trainers.move-down');
        });

        /*
        |--------------------------------------------------------------------------
        | システム管理者専用ルート（system_adminロールのみ）
        |--------------------------------------------------------------------------
        */
        Route::middleware('system-admin-only')->group(function () {
            // 音声データ管理状況（音声ファイル一覧）
            Route::get('usage-stats', [UsageStatsController::class, 'index'])->name('usage-stats.index');

            // IPアドレス制限設定
            Route::get('settings/ip-restriction', [IpRestrictionController::class, 'edit'])->name('settings.ip-restriction.edit');
            Route::put('settings/ip-restriction', [IpRestrictionController::class, 'update'])->name('settings.ip-restriction.update');
        });
    });

});

/*
|--------------------------------------------------------------------------
| クライアント用サブドメイン（外部）
|--------------------------------------------------------------------------
| クライアント側の全ルートを Route::domain で囲む。ローカルは両ホストが localhost
| に解決されるため、既存の動作は変わらない。
| ルート名は変えないため、route() 呼び出しや CheckIpRestriction のバイパス判定
| （routeIs('client-portal.*')）は無改修で動く。
*/
Route::domain(config('subdomain.client_host'))->group(function () {

    // クライアント用ドメインの / → クライアントログインへ（トレーナー側と対称）
    Route::redirect('/', '/client-portal/login');

    // --- 公開（認証不要） ---

    // クライアント初回設定（S-1403、公開URL、認証不要）。
    // /client-portal/* の auth:client グループには入れず、認証不要の公開領域に置く。
    // GET 時にトークン検証と同時に client guard でログインさせる（自動ログイン）。
    Route::get('client-portal/setup/{token}', [\App\Http\Controllers\Client\InitialSetupController::class, 'showByToken'])
        ->name('client-portal.setup.show');
    Route::post('client-portal/setup/{token}', [\App\Http\Controllers\Client\InitialSetupController::class, 'storeByToken'])
        ->name('client-portal.setup.store');

    // メールアドレス登録画面（S-1405、公開URL、認証不要）。段階 4-1。
    Route::get('client-portal/email-registration/{token}', [\App\Http\Controllers\Client\EmailRegistrationController::class, 'showByToken'])
        ->name('client-portal.email-registration.show');
    Route::post('client-portal/email-registration/{token}', [\App\Http\Controllers\Client\EmailRegistrationController::class, 'storeByToken'])
        ->name('client-portal.email-registration.store');

    // 受付アンケート（S-1413 回答画面・S-1414 回答完了画面、公開URL、認証不要。2026-10 追加）。
    // 回答完了画面 client-portal/intake-survey/thanks は、`thanks` が {token} として解釈されないよう、
    // {token} のルートより**先に**定義すること（api-design.md 4-1-15）
    Route::get('client-portal/intake-survey/thanks', [\App\Http\Controllers\Client\IntakeSurveyController::class, 'thanks'])
        ->name('client-portal.intake-survey.thanks');
    Route::get('client-portal/intake-survey/{token}', [\App\Http\Controllers\Client\IntakeSurveyController::class, 'show'])
        ->name('client-portal.intake-survey.show');
    Route::post('client-portal/intake-survey/{token}', [\App\Http\Controllers\Client\IntakeSurveyController::class, 'store'])
        ->name('client-portal.intake-survey.store');

    // メールアドレス確認リンク（S-1406 派生、公開URL、認証不要）。段階 4-3。
    // ログイン状態を問わず、切替 → ログアウト → ログイン画面へ遷移する。
    Route::get('client-portal/email-change/{token}', [\App\Http\Controllers\Client\EmailChangeController::class, 'confirm'])
        ->name('client-portal.email-change.confirm');

    // パスワード再設定 申し込み（S-1407、公開URL、認証不要）。段階 4-4。
    // {token} を含む再設定用ルートより先に定義する（申し込みルートを優先マッチさせるため）。
    Route::get('client-portal/password-reset', [\App\Http\Controllers\Client\PasswordResetController::class, 'showRequestForm'])
        ->name('client-portal.password-reset.request.show');
    Route::post('client-portal/password-reset', [\App\Http\Controllers\Client\PasswordResetController::class, 'sendResetLink'])
        ->name('client-portal.password-reset.request.send');

    // パスワード再設定（S-1408、公開URL、認証不要）。段階 4-4。
    // 申し込みルート（/password-reset）より後に定義する。
    Route::get('client-portal/password-reset/{token}', [\App\Http\Controllers\Client\PasswordResetController::class, 'showResetForm'])
        ->name('client-portal.password-reset.reset.show');
    Route::post('client-portal/password-reset/{token}', [\App\Http\Controllers\Client\PasswordResetController::class, 'resetPassword'])
        ->name('client-portal.password-reset.reset.save');

    /*
    |--------------------------------------------------------------------------
    | クライアント閲覧機能（柱2）
    |--------------------------------------------------------------------------
    | トレーナー向け（web guard）とは独立し、client guard で認証する。
    | practitioners 等トレーナー専用ミドルウェアは絶対に付けない（隔離）。
    */
    Route::prefix('client-portal')->name('client-portal.')->group(function () {
        // 未認証向け（guest:client）：ログイン画面・ログイン処理
        // ログイン済みは redirectUsersTo により /client-portal/dashboard へ振り分けられる。
        Route::middleware('guest:client')->group(function () {
            Route::get('/login', [ClientLoginController::class, 'showLoginForm'])->name('login');
            Route::post('/login', [ClientLoginController::class, 'login']);
        });

        // 認証必須（auth:client）。未認証は redirectGuestsTo により /client-portal/login へ振り分けられる。
        Route::middleware('auth:client')->group(function () {
            Route::post('/logout', [ClientLogoutController::class, 'logout'])->name('logout');
            Route::get('/dashboard', [ClientDashboardController::class, 'index'])->name('dashboard');
            // ご利用回数（S-1412、6-15-16）— ログイン中の会員自身の契約・利用を見るだけ。
            // URL に会員の id を取らない（auth('client')->user() の分だけを出す）。
            Route::get('/contracts', [\App\Http\Controllers\Client\ContractController::class, 'index'])
                ->name('contracts');
            Route::get('/training-records/{trainingRecord}', [\App\Http\Controllers\Client\TrainingRecordController::class, 'show'])
                ->name('training-records.show');
            Route::get('/media/{mediaRecord}/play', [\App\Http\Controllers\Client\MediaRecordController::class, 'play'])
                ->name('media.play');
            // トレーニー写真（S-1402、6-15-15）— 会員がダッシュボードから登録・削除する
            // サーバ経由の multipart POST 方式（署名付き URL 直アップロードは使わない）。
            // trainees.* ルート（トレーナー向け CRUD）は practitioners ミドルウェアで
            // 保護されたままで、会員には開放しない。会員側は本エンドポイントを専用に持つ。
            Route::post('/trainees/{trainee}/photo', [\App\Http\Controllers\Client\TraineePhotoController::class, 'store'])
                ->name('trainee-photo.store');
            Route::delete('/trainees/{trainee}/photo', [\App\Http\Controllers\Client\TraineePhotoController::class, 'destroy'])
                ->name('trainee-photo.destroy');
            // 登録情報（S-1406）— 確認画面
            Route::get('/profile', [\App\Http\Controllers\Client\SettingsController::class, 'show'])
                ->name('profile.show');
            // 登録情報の変更（S-1411）— 変更フォーム
            Route::get('/profile/edit', [\App\Http\Controllers\Client\SettingsController::class, 'edit'])
                ->name('profile.edit');
            Route::put('/profile', [\App\Http\Controllers\Client\SettingsController::class, 'updateProfile'])
                ->name('profile.update');
            // メールアドレスの変更（S-1409）— 独立画面
            Route::get('/settings/email', [\App\Http\Controllers\Client\SettingsController::class, 'editEmail'])
                ->name('settings.email.edit');
            Route::post('/settings/email', [\App\Http\Controllers\Client\SettingsController::class, 'requestEmailChange'])
                ->name('settings.email.request');
            // パスワードの変更（S-1410）— 独立画面
            Route::get('/settings/password', [\App\Http\Controllers\Client\SettingsController::class, 'editPassword'])
                ->name('settings.password.edit');
            Route::put('/settings/password', [\App\Http\Controllers\Client\SettingsController::class, 'updatePassword'])
                ->name('settings.password.update');
        });
    });

});
