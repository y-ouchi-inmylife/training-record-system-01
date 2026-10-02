// Select2 の入口。会員・登録者の選択欄を使う画面でだけ読み込む。
// jQuery を window.$ / window.jQuery に置き、Select2 を jQuery に組み込む。
// 日本語のメッセージは画面側で option として与えているため、ここでは
// 言語ファイルは読み込まない。
import 'select2/dist/css/select2.min.css';
import 'select2-bootstrap-5-theme/dist/select2-bootstrap-5-theme.min.css';
import jQuery from 'jquery';
import select2 from 'select2';

window.$ = window.jQuery = jQuery;
// Select2 の CommonJS 版は (window, jQuery) を受け取り、jQuery に .select2 を足す。
select2(window, jQuery);
