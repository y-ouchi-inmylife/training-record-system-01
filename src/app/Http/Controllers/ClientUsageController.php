<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientUsageRequest;
use App\Models\Client;
use App\Models\ClientUsage;
use App\Services\UsageCapacityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * 利用（D-1000）の CRUD コントローラ。
 *
 * ClientContractController と同じ書き方（TraineeMeasurementController に合わせる）：
 * - store / update で `updated_by` はコントローラで明示代入
 * - フラッシュキーは `success`
 * - destroy は物理削除。**権限は一般トレーナーも可**（設計書 requirements.md 6-17-6）
 * - 処理後は常に会員詳細（S-0305）へ戻す（`return_to` は持たない）
 *
 * 割り当てる契約（client_contract_id。空は「契約なし」）は validated() のまま保存する。
 * 所属と契約の残りのチェックは ClientUsageRequest で行う。会員の残り回数がマイナスになる
 * 登録・編集は止めない（警告も出さない。設計書 6-17-4）。
 *
 * 保存時の上限の確認（2026-11 追加。requirements.md 6-17-11、screen-design.md S-0305-M05「保存時の上限の確認」）：
 * 入力の検証のあと、利用日が今日以降で、その利用を入れたときの時間帯の預かる数（UsageCapacityService）が
 * 同時に預かれる数（ScheduleSetting::capacity()）を超え、確認済みの印（CONFIRMED_INPUT）がなければ、
 * 保存しないで会員詳細に戻り、利用のモーダルを開き直して注意を出す（入力エラーで開き直す仕組みに乗せる。withInput）。
 * 注意の文はセッションの CAPACITY_WARNING_SESSION_KEY に載せる。この送信はアクセスログに記録しない（LogAccess）。
 */
class ClientUsageController extends Controller
{
    /** 上限の確認で戻ったときの注意の文を載せるセッションのキー（2026-11 追加。LogAccess も見る） */
    public const CAPACITY_WARNING_SESSION_KEY = 'usage_capacity_warning';

    /** 確認済みの印（hidden）の名前。値が '1' なら上限を超えていても保存する（2026-11 追加） */
    public const CONFIRMED_INPUT = '_capacity_confirmed';

    /**
     * 利用の登録
     */
    public function store(ClientUsageRequest $request, Client $client, UsageCapacityService $capacityService): RedirectResponse
    {
        $validated = $request->validated();

        $warning = $this->capacityWarning($request, $validated, null, $capacityService, '登録');
        if ($warning !== null) {
            return $this->backWithCapacityWarning($client->id, $warning);
        }

        $validated['client_id'] = $client->id;
        $validated['updated_by'] = auth()->id();

        ClientUsage::create($validated);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', '利用を登録しました。');
    }

    /**
     * 利用の更新
     */
    public function update(ClientUsageRequest $request, ClientUsage $usage, UsageCapacityService $capacityService): RedirectResponse
    {
        $validated = $request->validated();

        // 編集中の利用自身は数えない
        $warning = $this->capacityWarning($request, $validated, $usage->id, $capacityService, '更新');
        if ($warning !== null) {
            return $this->backWithCapacityWarning($usage->client_id, $warning);
        }

        $validated['updated_by'] = auth()->id();
        $usage->update($validated);

        return redirect()
            ->route('clients.show', $usage->client_id)
            ->with('success', '利用を更新しました。');
    }

    /**
     * 保存時の上限の確認（2026-11 追加。安全網）。確認が要るときは注意の文を、要らないときは null を返す。
     *
     * ふだんは画面が保存の前に GET /api/usage-schedule/check で問い合わせ、確認ダイアログで［OK］なら確認済みの印を付けて送る
     * （2026-11 変更）。ここは、問い合わせに失敗したときや、確認している間にほかの人が予約を入れたときの安全網。
     * - 確認済みの印があれば確かめない
     * - 判定（今日以降だけ・上限を超えるときだけ）は UsageCapacityService::confirmation（問い合わせと同じ）
     *
     * @param  array<string, mixed>  $validated
     * @param  string  $button  最後の文のボタンの名前（「登録」「更新」）
     */
    private function capacityWarning(
        ClientUsageRequest $request,
        array $validated,
        ?int $excludeUsageId,
        UsageCapacityService $capacityService,
        string $button,
    ): ?string {
        if ((string) $request->input(self::CONFIRMED_INPUT) === '1') {
            return null;
        }

        $date = Carbon::parse($validated['used_on'])->toDateString();
        $confirmation = $capacityService->confirmation($date, $validated['start_time'], $validated['end_time'], $excludeUsageId);
        if (! $confirmation['needs_confirmation']) {
            return null;
        }

        // 2026-11 変更：文の頭に「この利用を入れると」を足した（確認ダイアログと同じ考え方）
        return UsageCapacityService::confirmationLead($date, $confirmation)
            .'このまま'.$button.'する場合は、もう一度［'.$button.'］を押してください。';
    }

    /**
     * 上限の確認で、保存せずに会員詳細へ戻す（入力した値を戻し、利用のモーダルを開き直す。2026-11 追加）
     */
    private function backWithCapacityWarning(int $clientId, string $warning): RedirectResponse
    {
        return redirect()
            ->route('clients.show', $clientId)
            ->withInput()
            ->with(self::CAPACITY_WARNING_SESSION_KEY, $warning);
    }

    /**
     * 利用の削除（物理削除、一般トレーナーも可）
     */
    public function destroy(ClientUsage $usage): RedirectResponse
    {
        $clientId = $usage->client_id;
        $usage->delete();

        return redirect()
            ->route('clients.show', $clientId)
            ->with('success', '利用を削除しました。');
    }
}
