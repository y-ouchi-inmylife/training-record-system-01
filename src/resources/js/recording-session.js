// 録音実行画面（recording-v2/session.blade.php）専用の入口。
// この画面は layouts.app を使わない独立 HTML で、見た目は素の Bootstrap 5。
// 共通の app.scss / app.js は読み込まない（当てると見た目が変わるため）。
// Bootstrap の CSS と JS を npm 版から読み込み、グローバルに Bootstrap を置く
// （ビューのスクリプトが `new bootstrap.Modal(...)` の形で使っている）。
import 'bootstrap/dist/css/bootstrap.min.css';
import * as bootstrap from 'bootstrap';
import { currentCsrfToken, refreshCsrfToken } from './csrf-token';
import { openPendingRecordingStore } from './pending-recordings';
import { sendRecording } from './recording-upload';

window.bootstrap = bootstrap;

// 録音を失わないための一時保存とトークンの取り直し（2026-10-21 追加。設計書 screen-design.md S-0502）。
// ビューのスクリプト（ふつうの <script>）から使えるように window に置く。この入口は module なので、ビューのスクリプトより後に動く
// （ビューでは DOMContentLoaded 以降・利用者の操作のときに使う）
window.RecordingUpload = { currentCsrfToken, refreshCsrfToken, openPendingRecordingStore, sendRecording };
