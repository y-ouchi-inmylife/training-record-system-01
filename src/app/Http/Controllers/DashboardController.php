<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientContract;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TrainingRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * 最近の愛犬性格診断の 1 ページの件数（2026-10-20 追加。設計書・テストもこの 1 か所に合わせる）。
     * 2026-10-20 変更：ダッシュボードの下のトレーニング記録の表が下に押し下げられすぎないよう、20 件から 5 件にした
     */
    public const DIAGNOSES_PER_PAGE = 5;

    /**
     * 連絡先が未入力の会員の 1 ページの件数（2026-10-28 追加。設計書・テストもこの 1 か所に合わせる）。
     * 最近の愛犬性格診断と同じく、下の表が押し下げられすぎないように 5 件
     */
    public const CONTACT_MISSING_PER_PAGE = 5;

    /**
     * ダッシュボード画面を表示
     */
    public function index(Request $request): View
    {
        // 連絡先が未入力の会員（S-0201。2026-10-28 追加：契約か利用があるのに住所・電話番号が入っていない会員に気づけるように）。
        // 対象は会員側の「ご連絡先の登録のお願い」と同じ条件（Client::contactMissing。契約か利用がある会員。2026-10-28 変更：その前は契約がある会員だけ）。
        // 状態バッジ（Client::statusBadge）の判定に要る値は会員一覧と同じく withStatusData で、最新の契約日はサブクエリでまとめて取る（契約がなければ NULL）。
        // 並びは契約がない（利用だけの）会員が先（すでに預かっているのに契約がなく、いちばん急ぐため。IS NULL DESC は MySQL・SQLite で同じ結果）、
        // 次に最新の契約日の新しい順、同じ日なら id の大きい順。絞り込みはなし。役割で変えない。
        // 5 件ずつ（CONTACT_MISSING_PER_PAGE）のページ送り（2026-10-28 変更：その前は全件）。ページの番号は contacts_page で、
        // withQueryString でページを送ってもほかの欄の diagnoses・diagnoses_page・recent が外れない
        $contactMissingQuery = Client::contactMissing()
            ->withStatusData()
            ->addSelect([
                'latest_contracted_on' => ClientContract::selectRaw('MAX(contracted_on)')
                    ->whereColumn('client_id', 'clients.id'),
            ])
            ->withCasts(['latest_contracted_on' => 'date'])
            ->orderByRaw('latest_contracted_on IS NULL DESC')
            ->orderBy('latest_contracted_on', 'desc')
            ->orderBy('clients.id', 'desc');
        // ページが範囲外（連絡先が入力されて対象が減り、開いていたページが最後のページより後になった）なら、最後のページを出す。
        // セクションが消えたり、空の表が出たりしないように（全体が 0 件ならビューはセクションを出さない）
        $contactMissingClients = $this->paginateWithinRange($contactMissingQuery, self::CONTACT_MISSING_PER_PAGE, 'contacts_page');

        // 最近のトレーニング記録（S-0201 セクション「最近のトレーニング記録」、2026-09 追加）。
        // 過去 1 週間（今日を含む 7 日間、未来日は含めない）を日付の新しい順で取得。
        // 詳細な設計方針は screen-design.md S-0201「設計方針」参照。
        $recentFrom = now()->startOfDay()->subDays(6);
        $recentTo   = now()->endOfDay();

        // 絞り込み：クエリパラメータ recent=primary のときのみ主担当（clients.primary_trainer_id）で絞る。
        // 空・不正な値は「すべて」として扱う（`=== 'primary'` の等価判定で分岐）。
        $recentFilter = $request->query('recent') === 'primary' ? 'primary' : 'all';

        $recentQuery = TrainingRecord::with(['client.trainees', 'trainer1', 'trainer2'])
            ->withCount('mediaRecords')
            ->whereBetween('training_date', [$recentFrom->toDateString(), $recentTo->toDateString()]);

        if ($recentFilter === 'primary') {
            // 会員の主担当 = ログイン中のトレーナー。担当1・担当2 で絞るのではない
            // （設計書 S-0201 設計方針「『主担当のみ』の基準」参照）。
            $recentQuery->whereHas('client', function ($q) {
                $q->where('primary_trainer_id', Auth::id());
            });
        }

        // 並びは S-0402 の既定に合わせる（training_date DESC, training_time DESC）。
        $recentRecords = $recentQuery
            ->orderBy('training_date', 'desc')
            ->orderBy('training_time', 'desc')
            ->get();

        // 最近の愛犬性格診断（S-0201。2026-10-20 追加：アドバイスの公開し忘れがひと目で分かるように）。
        // 回答日時の新しい順に、5 件ずつ（DIAGNOSES_PER_PAGE）のページ送りで出す。期間では絞らない（2026-10-20 変更：
        // 30 日より前の未公開も見落とさないように。その前は過去 30 日のものだけを、ページングなしで出していた）。
        // 絞り込み（2026-10-20 追加：やることの一覧として未公開だけを見たい）：クエリパラメータ diagnoses=all のときはすべて、
        // それ以外（ない・unpublished・不正な値）は未公開（なし・下書き）だけ。未公開の判定はモデルの evaluationNotPublished
        // （公開した日時がないもの。状態の判定 evaluation_status と同じ基準）。recent とは別のパラメータ（ぶつからないように）。
        // 見せる範囲は最近のトレーニング記録と同じ（役割で変えない。recent の絞り込みは効かない）。ページの番号は diagnoses_page で、
        // withQueryString でページを送っても diagnoses と recent が外れない。トレーニーと会員はまとめて読み込む。
        // 2026-10-20 変更：見出しの「（未公開 n件）」を外したので、未公開の件数は数えない。
        // ページが範囲外なら最後のページを出す（2026-10-28 変更：評価文を公開すると未公開が減り、開いていたページが範囲外になるため。
        // その前は範囲外のページをそのまま取り、件数があっても「〜はありません。」の帯が出ていた。全体が 0 件なら今までどおり帯）
        $diagnosesFilter = $request->query('diagnoses') === 'all' ? 'all' : 'unpublished';
        $recentDiagnosesQuery = TraineePersonalityDiagnosisResult::with('trainee.client')
            ->when($diagnosesFilter === 'unpublished', fn ($query) => $query->evaluationNotPublished())
            ->orderBy('answered_at', 'desc')
            ->orderBy('id', 'desc');
        $recentDiagnoses = $this->paginateWithinRange($recentDiagnosesQuery, self::DIAGNOSES_PER_PAGE, 'diagnoses_page');

        return view('dashboard', compact('contactMissingClients', 'recentRecords', 'recentFilter', 'recentDiagnoses', 'diagnosesFilter'));
    }

    /**
     * ページ送りで取り、ページが範囲外（最後のページより後）なら最後のページで取り直す（2026-10-28 追加）。
     * 連絡先が未入力の会員（contacts_page）と最近の愛犬性格診断（diagnoses_page）で使う。どちらも、入力・公開で対象が減ると
     * 開いていたページが範囲外になるため。全体が 0 件なら最後のページは 1 ページ目で、空のページが返る（ビューは 0 件の扱い）。
     * 取り直したページは、ページ送りのリンクにも、ほかのフォームの hidden（currentPage()）にも入る。
     * withQueryString で、ほかの欄の絞り込み・ページを外さない
     */
    private function paginateWithinRange(Builder $query, int $perPage, string $pageName): LengthAwarePaginator
    {
        $paginator = (clone $query)->paginate($perPage, ['*'], $pageName)->withQueryString();
        if ($paginator->currentPage() > $paginator->lastPage()) {
            $paginator = $query->paginate($perPage, ['*'], $pageName, $paginator->lastPage())->withQueryString();
        }

        return $paginator;
    }
}
