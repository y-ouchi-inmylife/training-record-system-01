<?php

use App\Http\Controllers\AudioRecordController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\MediaRecordController;
use App\Http\Controllers\TrainingRecordController;
use App\Http\Controllers\TrainerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::domain(config('subdomain.trainer_host'))
    ->middleware(['web', 'auth', 'practitioners', 'check-ip'])
    ->group(function () {
    Route::get('/clients/search', [ClientController::class, 'apiSearch'])->name('api.clients.search');
    Route::get('/audio-records/summaries', [AudioRecordController::class, 'summaries'])->name('api.audio-records.summaries');
    Route::get('/audio-records/{audioRecord}/summary', [AudioRecordController::class, 'getSummary'])->name('api.audio-records.summary');
    Route::post('/audio-records/{audioRecord}/transcribe', [AudioRecordController::class, 'transcribe'])->name('api.audio-records.transcribe');
    Route::post('/audio-records/{audioRecord}/summarize', [AudioRecordController::class, 'summarize'])->name('api.audio-records.summarize');
    // 文字起こし・要約の状態の問い合わせ（キューで処理中のとき、音声記録一覧が一定の間隔で呼ぶ。2026-10）
    Route::get('/audio-records/{audioRecord}/status', [AudioRecordController::class, 'status'])->name('api.audio-records.status');
    // 録音実行の「作成する」：文字起こし → 要約 → トレーニング記録の作成をひとつながりで始める（2026-10）
    Route::post('/audio-records/{audioRecord}/auto-create-training-record', [AudioRecordController::class, 'startAutoCreate'])->name('api.audio-records.auto-create-training-record');
    Route::get('/trainers', [TrainerController::class, 'apiList'])->name('api.trainers.list');
    Route::post('/training-records/auto-create', [TrainingRecordController::class, 'autoCreate'])->name('api.training-records.auto-create');
    Route::get('/training-records/available-media', [TrainingRecordController::class, 'availableMedia'])->name('api.training-records.available-media');
    Route::post('/media-records/upload-url', [MediaRecordController::class, 'uploadUrl'])->name('api.media-records.upload-url');
    Route::post('/media-records', [MediaRecordController::class, 'store'])->name('api.media-records.store');
    Route::get('/media-records/{mediaRecord}/play', [MediaRecordController::class, 'play'])->name('api.media-records.play');
    Route::post('/media-records/{mediaRecord}/convert', [MediaRecordController::class, 'convert'])->name('api.media-records.convert');
    Route::post('/media-records/{mediaRecord}/generate-thumbnail', [MediaRecordController::class, 'generateThumbnail'])->name('api.media-records.generate-thumbnail');
    // 変換・サムネイルの状態の問い合わせ（キューで処理中のとき、登録モーダルが一定の間隔で呼ぶ。2026-10）
    Route::get('/media-records/{mediaRecord}/status', [MediaRecordController::class, 'status'])->name('api.media-records.status');
});
