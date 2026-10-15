<?php

namespace App\Http\Controllers;

use App\Http\Requests\TraineeMeasurementRequest;
use App\Models\Trainee;
use App\Models\TraineeMeasurement;
use Illuminate\Http\RedirectResponse;

/**
 * トレーニー計測値（D-0800）の CRUD コントローラ。
 *
 * TraineeController の書き方に合わせる：
 * - store / update で `updated_by` はコントローラで明示代入（既存慣例）
 * - フラッシュキーは `success`
 * - destroy は物理削除。**権限は一般トレーナーも可**（設計書 6-16-7）
 *
 * ## リダイレクト先（api-design.md「計測値エンドポイントのリダイレクト先」参照）
 *
 * 3 メソッドとも、成功後は常に S-0309 トレーニー詳細画面へ戻す（計測値の登録・編集・削除は
 * S-0309 の計測値の欄だけで行うため）。2026-10 変更：当初は hidden `return_to` で送信元を判別し、
 * `return_to=client` のときは S-0305 会員詳細へ戻していた（S-0305 のトレーニーカードの
 * 「計測を追加」から登録したとき）。S-0305 から登録しなくなったため外した（送られても使わない）。
 */
class TraineeMeasurementController extends Controller
{
    /**
     * 計測値の登録
     */
    public function store(TraineeMeasurementRequest $request, Trainee $trainee): RedirectResponse
    {
        $validated = $request->validated();
        $validated['trainee_id'] = $trainee->id;
        $validated['updated_by'] = auth()->id();

        TraineeMeasurement::create($validated);

        return redirect()->route('trainees.show', $trainee)
            ->with('success', '計測値を登録しました。');
    }

    /**
     * 計測値の更新
     */
    public function update(TraineeMeasurementRequest $request, TraineeMeasurement $measurement): RedirectResponse
    {
        $validated = $request->validated();
        $validated['updated_by'] = auth()->id();
        $measurement->update($validated);

        return redirect()->route('trainees.show', $measurement->trainee)
            ->with('success', '計測値を更新しました。');
    }

    /**
     * 計測値の削除（物理削除、一般トレーナーも可）
     *
     * 削除後のリダイレクトで trainee 情報を使うため、削除前にトレーニーを退避する
     * （`$measurement->delete()` は measurement 側の削除で trainee には影響しない）。
     */
    public function destroy(TraineeMeasurement $measurement): RedirectResponse
    {
        $trainee = $measurement->trainee;
        $measurement->delete();

        return redirect()->route('trainees.show', $trainee)
            ->with('success', '計測値を削除しました。');
    }
}
