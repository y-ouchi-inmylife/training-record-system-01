<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MediaRecordController as TrainerMediaRecordController;
use Illuminate\Contracts\View\View;

/**
 * クライアント閲覧機能（柱2）— ダッシュボードコントローラ
 *
 * ログイン中のクライアント自身のトレーニング記録を、セッションカード・
 * フィードとしてビューに渡す。段階③でトレーニーの体重推移グラフ用の
 * データも組み立てて渡す（設計書 §S-1402 の「体重推移」節、6-15-14）。
 *
 * 設計書 §8-1-1 の方針に従う：
 * - 既存クエリはそのまま（eager load / withCount / training_date 降順は不変）
 * - 記録ごとに入れ子の $sessions 構造を返す（前案の平坦化 $mediaItems は撤回）
 * - 署名付きサムネイル URL 等はコントローラで生成し、Blade からモデルの
 *   temporaryThumbnailUrl() を呼ばない
 * - 有効期限（$thumbnailExpiresAt）の算出はそのまま維持
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $client = auth('client')->user();

        // 自分の記録を日付の新しい順（降順）で取得。
        // 一覧表示に使うリレーション（担当1・担当2）に加え、
        // メディアもカード内に埋め込むため mediaRecords を eager load（N+1回避）。
        // updatedBy はクライアント非表示のため意図的にロードしない。
        $records = $client
            ->trainingRecords()
            ->with(['trainer1', 'trainer2', 'mediaRecords'])
            ->withCount('mediaRecords')
            ->orderByDesc('training_date')
            ->get();

        // 記録ごとに入れ子の $sessions 構造に組み替える（設計書 §8-1-1 変更1）。
        // 署名付きサムネイル URL は temporaryThumbnailUrl() を呼ぶ必要があるため、
        // 有効期限をここで算出しコントローラで一括生成する（Blade で呼ばない）。
        $thumbnailExpiresAt = now()->addMinutes(
            TrainerMediaRecordController::PLAY_URL_EXPIRES_MINUTES
        );

        // displayTitle は渡さない。お客様側の Blade は種別ラベル（写真／動画）を
        // alt / aria-label / data-display-title に載せる（設計書 S-1402 メディアギャラリー
        // 節参照。メディアの表示名・ファイル名はクライアント側に一切露出させない方針）。
        // typeLabel はコントローラで組み立てて渡す（Blade で `@php(...)` を使うと
        // 直前の `@php ... @endphp` ブロックとパースが衝突する事故があったため、
        // Blade 側の @php 使用を避けている）。photo / video の 2 分岐は DB CHECK 制約に対応。
        $sessions = $records->map(function ($rec) use ($thumbnailExpiresAt) {
            return [
                'record' => $rec,
                'media'  => $rec->mediaRecords->map(function ($m) use ($thumbnailExpiresAt) {
                    return [
                        'id'               => $m->id,
                        'type'             => $m->type,
                        'typeLabel'        => $m->type === 'photo' ? '写真' : '動画',
                        'thumbnailUrl'     => $m->temporaryThumbnailUrl($thumbnailExpiresAt),
                        'conversionStatus' => $m->conversion_status,
                    ];
                })->all(),
            ];
        });

        // 段階③：トレーニーごとの体重推移グラフ用データを組み立てる。
        // Blade には完成した配列を渡す（Blade 内での再計算・並べ替えは行わない）。
        $weightCharts = $this->buildWeightCharts($client);

        return view('client.dashboard', compact('sessions', 'weightCharts'));
    }

    /**
     * 体重推移グラフ用のデータをトレーニーごとに組み立てる。
     *
     * 1 頭分の組み立てロジックは `Trainee::getWeightChartDataAttribute()` に集約
     * （**2026-09 変更**、トレーナー側 S-0309 と共用するため）。本メソッドは
     * トレーニーを回してアクセサを呼ぶだけの薄い形。返り値の形（トレーニー配列で
     * 各要素は `id / name / photoUrl / datasets`）は変更なし — Blade
     * （client/dashboard.blade.php）は無変更で動く。詳細な設計方針・データ形式・
     * タイムゾーン扱いは `Trainee::getWeightChartDataAttribute()` の docblock を参照。
     *
     * トレーニー 0 頭の会員では空配列を返し、Blade で `@if(!empty($weightCharts))` により
     * ブロックごと非表示になる（挙動変更なし）。
     */
    private function buildWeightCharts($client): array
    {
        // トレーニーは Trainee::trainees() で `id` 昇順（登録順）が既定。
        // アクセサ内で $this->measurements を触るため、ここで eager load しておく
        // （load しないと 1+N のクエリになる。単頭ケースは S-0309 側で別途
        // $trainee->load('measurements') 済み）。
        $trainees = $client->trainees()->with('measurements')->get();

        return $trainees->map(fn ($trainee) => $trainee->weight_chart_data)->all();
    }
}
