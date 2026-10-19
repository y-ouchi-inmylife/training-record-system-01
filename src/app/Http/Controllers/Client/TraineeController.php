<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientTraineeProfileRequest;
use App\Models\Trainee;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 会員の愛犬の情報の変更（S-1417。要件定義書 6-15-20。2026-10-18 追加）。
 *
 * 登録情報（S-1406）の愛犬のカードの下の［{呼び名}の情報を変更］から開く。名前・犬種・性別・誕生日・
 * 去勢・避妊だけを変える（自由記述の質問・写真は変えない）。入力欄と入力のきまりは愛犬アンケート（S-1413）と同じ。
 *
 * - 自分の愛犬だけ。ほかの会員の愛犬は 403（写真の登録・削除〔TraineePhotoController〕と同じ扱い）
 * - トレーナー側のトレーニーのルート（trainees.*）は会員に開放せず、このコントローラを専用に持つ
 * - updated_by（最終更新者のトレーナー）は変えない（会員の操作のため。愛犬アンケートの反映・写真の登録と同じ）
 * - 操作の記録（access_logs）は残さない（会員側の操作は記録しない。登録情報の変更 S-1411 と同じ）
 */
class TraineeController extends Controller
{
    /**
     * 変更の画面。トレーニーの今の値を入れて開く（入力エラーで戻ったときは入力した値だけを使う。ビューで判定）
     */
    public function edit(Trainee $trainee): View
    {
        if ($trainee->client_id !== auth('client')->id()) {
            abort(403);
        }

        return view('client.trainees.edit', ['trainee' => $trainee]);
    }

    /**
     * 保存して登録情報（S-1406）に戻る。自分の愛犬かどうかは ClientTraineeProfileRequest::authorize() で確かめる。
     *
     * 変更の画面なので、空にした項目は空にする（愛犬アンケートの「空なら変えない」とは違う）。
     * 選ばなかったラジオボタン（送られてこない）も空にする。完了メッセージの呼び名は変更したあとの名前から作る
     */
    public function update(ClientTraineeProfileRequest $request, Trainee $trainee): RedirectResponse
    {
        $validated = $request->validated();
        $changes = [];
        foreach (array_keys(ClientTraineeProfileRequest::basicInfoRules()) as $key) {
            $changes[$key] = $validated[$key] ?? null;
        }
        $trainee->fill($changes);
        if ($trainee->isDirty()) {
            $trainee->save();
        }

        return redirect()->route('client-portal.profile.show')
            ->with('success', $trainee->client_call_name.'の情報を変更しました。');
    }
}
