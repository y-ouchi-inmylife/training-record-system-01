<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MediaRecordController as TrainerMediaRecordController;
use App\Models\TrainingRecord;
use Illuminate\Contracts\View\View;

/**
 * クライアント閲覧機能（柱2）— トレーニング記録詳細コントローラ
 *
 * ログイン中のクライアントが「自分の」トレーニング記録の詳細を閲覧する。
 * トレーナー側 TrainingRecordController::show とは別実装：
 * - 本人認可（client_id 照合）で他人の記録は 403
 * - 所感（impression）・最終更新者などクライアント非開示情報は
 *   ロード・受け渡ししない（漏れを構造的に防ぐ）
 * - 紐づくメディアはサムネイル URL とともに view に渡す。記録レベル本人認可を
 *   通った時点で「本人の記録に紐づくメディア」であることが保証されるため、
 *   サムネイル発行に個別のメディア認可は挟まない（再生 API 側で個別認可）
 */
class TrainingRecordController extends Controller
{
    public function show(TrainingRecord $trainingRecord): View
    {
        // 本人認可：対象記録がログイン中クライアント自身のものでなければ 403
        if ($trainingRecord->client_id !== auth('client')->id()) {
            abort(403);
        }

        // 表示に必要なリレーションのみロードする。
        // updatedBy は意図的にロードしない（非開示）。mediaRecords は
        // belongsToMany 側で orderByPivot('sort_order') 済み＝sort_order 昇順で並ぶ。
        $trainingRecord->load(['client', 'trainer1', 'trainer2', 'mediaRecords']);

        // メディアグリッド用の表示データ（presigned サムネイル URL を含む）。
        // 再生は /client/media/{id}/play を叩く（そちらでメディア単位の本人認可）。
        $thumbnailExpiresAt = now()->addMinutes(TrainerMediaRecordController::PLAY_URL_EXPIRES_MINUTES);
        // displayTitle は渡さない。お客様側の Blade は種別ラベル（写真／動画）を
        // alt / aria-label / data-display-title に載せる（設計書 S-1404 セクション2
        // メディア参照。メディアの表示名・ファイル名はクライアント側に一切露出させない方針）。
        // typeLabel はコントローラで組み立てて渡す（Blade で `@php(...)` を使うと
        // 直前の `@php ... @endphp` ブロックとパースが衝突する事故があったため、
        // Blade 側の @php 使用を避けている）。photo / video の 2 分岐は DB CHECK 制約に対応。
        $mediaItems = $trainingRecord->mediaRecords->map(function ($m) use ($thumbnailExpiresAt) {
            return [
                'id'               => $m->id,
                'type'             => $m->type,
                'typeLabel'        => $m->type === 'photo' ? '写真' : '動画',
                'thumbnailUrl'     => $m->temporaryThumbnailUrl($thumbnailExpiresAt),
                'conversionStatus' => $m->conversion_status,
            ];
        })->values()->all();

        return view('client.training-records.show', compact('trainingRecord', 'mediaItems'));
    }
}
