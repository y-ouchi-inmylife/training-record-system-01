// SortableJS の入口。トレーニング記録の登録・編集画面でだけ読み込む。
// グローバルに Sortable を置くのは、ビューのスクリプトが `new Sortable(...)` を
// そのまま使っているため（既存の書き方を変えない）。
import Sortable from 'sortablejs';
window.Sortable = Sortable;
