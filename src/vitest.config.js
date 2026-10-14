import { defineConfig } from 'vitest/config';

// JS のテスト（vitest）の設定。2026-10 追加。
// 既存の vite.config.js（Laravel のプラグインでビルドの入口を決めている）には手を入れず、
// テスト用の設定をここに分ける。テストは DOM に触れない計算・判定のモジュールだけを対象にし、
// 実行環境は Node（jsdom などは入れていない）。
// テストは tests/js/ に置く（PHP のテスト tests/Unit・tests/Feature と、PHP・JS で共通に使う
// 固定データ tests/fixtures/ と並べる）。
export default defineConfig({
    test: {
        include: ['tests/js/**/*.test.js'],
        environment: 'node',
    },
});
