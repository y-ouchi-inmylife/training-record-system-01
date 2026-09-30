// セッション開始時に git の同期状態を確認するスクリプト
//
// 目的：
//   開発は Mac と Windows の 2 台で行っているため、push 忘れ・pull 忘れに
//   作業を始めるときに気づけるよう、origin との差と未コミットの変更を表示する。
//
// 呼ばれ方：
//   Claude Code の SessionStart フック（.claude/settings.json）から呼ばれる。
//   標準出力はそのまま Claude の文脈に入る。手で `node .claude/hooks/git-sync-check.mjs` と実行してもよい。
//
// Node にしている理由：
//   Mac（zsh）と Windows（PowerShell 5.1）ではシェルの書き方が違う（`&&` が使えない等）ため、
//   処理を Node のスクリプト 1 つにまとめ、git はシェルを通さずに直接呼ぶ。
//
// 作業を止めないよう、どんな場合も終了コード 0 で終わる。

import { execFileSync } from 'node:child_process';

// git を実行する場所。フックから呼ばれたときはプロジェクトのルート、手で実行したときはカレントディレクトリ
const cwd = process.env.CLAUDE_PROJECT_DIR || process.cwd();

// git をシェルを通さずに実行し、標準出力を返す
function git(args, timeout = 5000) {
    return execFileSync('git', args, {
        cwd,
        encoding: 'utf8',
        timeout,
        stdio: ['ignore', 'pipe', 'pipe'],
        windowsHide: true,
    }).trim();
}

const lines = ['【git の同期状態（セッション開始時の自動確認）】'];

try {
    // origin の最新を取り込む（作業ツリーは変えない）。失敗しても手元の情報だけで続ける
    let fetchFailed = false;
    try {
        git(['fetch', '--quiet'], 15000);
    } catch {
        fetchFailed = true;
    }

    const branch = git(['rev-parse', '--abbrev-ref', 'HEAD']);

    // 上流ブランチがない場合は例外になる
    let upstream = null;
    try {
        upstream = git(['rev-parse', '--abbrev-ref', '@{u}']);
    } catch {
        upstream = null;
    }

    // 未コミットの変更の数（git status --porcelain の行数）
    const status = git(['status', '--porcelain']);
    const changes = status === '' ? 0 : status.split(/\r?\n/).length;

    if (fetchFailed) {
        lines.push('origin の確認に失敗しました（ネットワークを確認してください）');
    }

    let ahead = 0;
    let behind = 0;
    if (upstream) {
        lines.push(`ブランチ：${branch}（上流：${upstream}）`);
        // 出力は「ahead<TAB>behind」
        const [a, b] = git(['rev-list', '--left-right', '--count', 'HEAD...@{u}']).split(/\s+/);
        ahead = Number(a) || 0;
        behind = Number(b) || 0;
        lines.push(`origin より遅れているコミット（behind）：${behind} 件${behind > 0 ? ' ← 作業の前に pull が必要です' : ''}`);
        lines.push(`未 push のコミット（ahead）：${ahead} 件${ahead > 0 ? ' ← push を忘れていないか確認してください' : ''}`);
    } else {
        lines.push(`ブランチ：${branch}（上流ブランチが設定されていません）`);
    }
    lines.push(`未コミットの変更：${changes} 件`);

    if (upstream && !fetchFailed && ahead === 0 && behind === 0 && changes === 0) {
        lines.push('最新の状態です。');
    }
} catch (e) {
    lines.push(`git の状態を確認できませんでした：${e.message.split(/\r?\n/)[0]}`);
}

console.log(lines.join('\n'));
process.exit(0);
