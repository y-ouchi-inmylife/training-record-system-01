// 録音実行画面（recording-v2/session.blade.php）専用の入口。
// この画面は layouts.app を使わない独立 HTML で、見た目は素の Bootstrap 5。
// 共通の app.scss / app.js は読み込まない（当てると見た目が変わるため）。
// Bootstrap の CSS と JS を npm 版から読み込み、グローバルに Bootstrap を置く
// （ビューのスクリプトが `new bootstrap.Modal(...)` の形で使っている）。
import 'bootstrap/dist/css/bootstrap.min.css';
import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;
