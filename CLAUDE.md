# カウンセリング記録管理システム

<!-- ガイド: このファイルはプロジェクトフォルダ直下にCLAUDE.mdとして配置する -->
<!-- ガイド: ~/workspace/dev/CLAUDE.md の共通ルールに加え、プロジェクト固有の情報を記載する -->

## プロジェクト概要

- **名前**: カウンセリング記録管理システム
- **概要**: 相談機関におけるクライアント情報と相談記録を一元管理し、相談業務の効率化と記録の品質向上を実現する

## 技術スタック

<!-- ガイド: アーキテクチャ設計書の技術スタックと一致させること -->

- **フロントエンド**: Blade + Bootstrap 5 + Select2 + Vite（Vue.jsは未採用）
- **バックエンド**: Laravel 12.x (PHP 8.2以上)
- **データベース**: MySQL 8.0
- **インフラ**: さくらのレンタルサーバー（Apache 2.4 + Let's Encrypt）
- **パッケージマネージャ**: Composer (PHP) / npm (JS)

## ディレクトリ構成

<!-- ガイド: 主要なディレクトリの役割を書く -->

```
project-root/
├── src/              # Laravelアプリケーション本体
│   ├── app/          # アプリケーションコード（Models, Controllers, Services等）
│   ├── resources/    # Bladeテンプレート・JavaScript・CSS等
│   ├── routes/       # ルーティング定義
│   ├── database/     # マイグレーション・シーダー
│   └── config/       # 設定ファイル
├── docs/             # 設計書
│   └── setup/        # デプロイ手順・環境セットアップ
├── tests/            # テスト（手動テストチェックリスト・不具合管理）
│   └── bugs.md       # 不具合・課題の管理表（一覧＋詳細）
└── .gitignore
```

## 用語集

<!-- ガイド: 要件定義書の用語集へのリンクまたはプロジェクト固有の用語を記載 -->
<!-- ガイド: コード内の変数名・テーブル名とビジネス用語の対応を書くと特に有用 -->

詳細は [docs/glossary.md](docs/glossary.md) を参照。

| ビジネス用語 | コード上の名前 | 説明 |
|-------------|---------------|------|
| 会員（画面表記。旧称クライアント） | client | 相談を受ける対象者 |
| 相談記録 | counseling_record | カウンセリングの実施内容を記録したもの |
| カウンセラー | counselor | カウンセリングを実施する担当者 |

## 設計書

<!-- ガイド: 設計書の場所と優先順位を明記する。AIが設計書を参照する順序の指針になる -->

| 優先順位 | ファイル | 内容 |
|---------|---------|------|
| 1 | `docs/requirements.md` | 要件定義書 — 全体の土台。機能の根拠はここにある |
| 2 | `docs/architecture.md` | アーキテクチャ設計書 — 技術方針と全体構成 |
| 3 | `docs/screen-design.md` | 画面設計書 — UI仕様と操作フロー |
| 4 | `docs/api-design.md` | API設計書 — エンドポイントとデータ形式 |
| 5 | `docs/db-schema.md` | DB設計書 — テーブル定義とデータ構造 |

設計書の索引は [docs/doc-index.md](docs/doc-index.md) を参照。

## 開発コマンド

<!-- ガイド: よく使うコマンドを書く -->

```bash
# 開発サーバー起動
php -S 127.0.0.1:8080 -t public

# テスト実行
php artisan test

# DBマイグレーション
php artisan migrate

# シーダー実行
php artisan db:seed

# リンター（PHP）
./vendor/bin/pint

# フロントエンド開発サーバー（Vite。HMR あり）
npm run dev

# フロントエンド本番ビルド（src/public/build/ に成果物を出力）
npm run build
```

## AI作業指示

<!-- ガイド: AIに対するプロジェクト固有の指示を書く -->

### コード生成時のルール

- 新しい機能を実装する前に、必ず対応する設計書を確認すること
- 設計書に記載のないAPIやテーブルを勝手に追加しない
- 設計書との差異を見つけたら、実装を進める前にユーザーに確認すること

### 設計書の参照方法

1. 機能を実装する際は、まず要件定義書で機能IDを確認する
2. 画面実装時は、画面設計書の画面IDとAPI設計書のエンドポイントを照合する
3. DB操作時は、DB設計書のテーブル定義を確認する

### 禁止事項

- 設計書を無視して独自の仕様で実装しない
- 設計書を勝手に変更しない（変更はユーザーの承認を得てから）
- テーブルやカラムの命名をDB設計書の命名規則と異なるものにしない

## フロントエンドのビルドとコミット

<!-- ガイド: 本番はビルドせず git pull のみで反映するため、ビルド成果物はコミットに含める -->

このリポジトリは、フロントのビルド成果物（`src/public/build/`）を **開発環境でビルドしてリポジトリにコミットする** 運用をとる。本番サーバーではビルドせず、`git pull` だけで反映する。したがって、フロントを変更したらコミット前に必ずビルドし、生成物を同じコミットに含めること。

### コミット前にビルドが必要な変更

次のいずれかを変更したときは、**コミット前に `src/` で `npm run build` を実行する**：

- `src/resources/sass/` 配下（`app.scss`, `client.scss`）
- `src/resources/js/` 配下
- `src/vite.config.js`
- `src/package.json` の依存関係
- Blade テンプレートの `@vite(...)` で読み込むファイルの追加・変更

フロントを変更していないコミット（PHP のみ、Markdown のみ、など）ではビルドは不要。

### ビルド成果物の扱い

- `npm run build` で生成・更新・削除された `src/public/build/` のファイル（ハッシュ付きの `assets/*` と `manifest.json`）を、**変更したソースと同じコミットに含める**
- 古いハッシュ付きファイルが削除された場合は、その削除も同じコミットに含める
- コミット前に `git status` で `src/public/build/` の変更が含まれていることを確認する
- `src/public/build/` を `.gitignore` に追加しない（本番はビルドしないため、リポジトリに残す必要がある）

### 本番デプロイ

- 本番サーバーでは `npm run build` を実行しない
- 本番のデプロイは `git pull` のみで完結する

## PowerShell でファイルを書き換えるときの注意（BOM）

- Windows PowerShell 5.1 の `Set-Content -Encoding UTF8` / `Out-File -Encoding UTF8` / `Add-Content -Encoding UTF8` は、ファイル先頭に UTF-8 BOM（`EF BB BF`）を付けて書き込む。PHP ファイルに BOM が付くと構文エラーになる（`namespace` が先頭の文ではなくなる）。
- **これらのコマンドでファイルを書き込まないこと。** 一括置換などでファイルを書き込むときは、BOM なし UTF-8 を明示する：

  ```powershell
  [System.IO.File]::WriteAllText($path, $content, (New-Object System.Text.UTF8Encoding($false)))
  ```

- 読み込みは `[System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8)` を使う（`Get-Content` は改行の扱いが変わるため、全文置換には使わない）。
- 書き込み後は、先頭 3 バイトが `EF BB BF` でないことを確認する。
- 経緯：2026-09 に、この副作用で設計書 3 件（`docs/requirements.md` / `docs/screen-design.md` / `docs/client-portal-design-plan.md`）・`src/resources/sass/client.scss`・`src/database/seeders/TrainingRecordDemoSeeder.php` に BOM が混入した（`TrainingRecordDemoSeeder.php` は構文エラーになった）。

## セッション開始時の git 同期状態の確認

開発は Mac と Windows の 2 台で行っている。push 忘れ・pull 忘れに作業を始めるときに気づくため、セッションの開始時に SessionStart フック（`.claude/settings.json` → `.claude/hooks/git-sync-check.mjs`）が `git fetch` をしてから、`【git の同期状態（セッション開始時の自動確認）】` という見出しで同期状態を出す。これを見て、次のように対応すること：

- **behind（遅れ）が 1 件以上なら、どんな依頼でも作業を始める前に、大内さんに「origin より N 件遅れています。先に pull してください」と伝えて止まる。** 自分で `git pull` はしない。
- ahead（未 push）が 1 件以上なら、最初の返答の冒頭で「未 push のコミットが N 件あります」と伝える（作業は続けてよい）。
- 未コミットの変更がある場合も、最初の返答の冒頭で伝える。
- 「origin の確認に失敗しました」と出た場合は、その旨を伝える（behind の数は古い情報に基づくため、当てにならない）。
