# 開発環境の起動手順および停止手順【macOS】

トレーニング記録管理システム（training-record-system）の開発環境（Mac）の起動手順です。

Windows 版は `startup-guide-win.md` を参照してください。**MySQL の起動方式が Windows と異なります**（Windows: Docker / Mac: Homebrew）。

---

## 前提

- Laravel Herd（Mac）がインストール済みであること
- Homebrew の `mysql@8.0` がインストール済みであること（就労支援記録管理システムで使用中のもの）
- MySQL・Herd はいずれも**ログイン時に自動起動**するため、通常は起動操作が不要

構成：**Laravel Herd（PHP 8.4 + nginx）／ MySQL 8.0（Homebrew・3306）**

---

## 起動手順

### 1. 最新を取り込む

```
cd ~/workspace/dev/training-record-system-01
git pull
cd src
composer install
npm ci
php artisan migrate --force
```

同じ機械で続けて作業する場合は不要です。

### 2. 作業ディレクトリへ移動

```
cd ~/workspace/dev/training-record-system-01/src
```

### 3. Vite を起動（SCSS / JS を編集する場合）

```
npm run dev
```

`VITE v... ready in ... ms` で待機状態になれば起動完了。**このターミナルは開いたままにします。**

> 本システムの `public/build/` は git 管理対象のため、Vite を起動しなくてもコミット済みのビルド成果物で画面は表示されます。`npm run dev` はホットリロードのためのものです。

### 4. ブラウザでアクセス

トレーナー側：

```
http://trs01-dev.test
```

クライアントポータル側：

```
http://trs01-dev.test/client/login
```

本番はサブドメインで分離していますが（トレーナー = `miraidogwellness-trs01-staff.inmylife1965.com` / クライアント = `miraidogwellness.inmylife1965.com`）、開発環境では `TRAINER_HOST` 未設定によりパスベース検出で両方にアクセスできます。

---

## 停止手順

| 対象 | 操作 |
| --- | --- |
| Vite | ターミナルで `Ctrl + C` |
| MySQL（任意） | `brew services stop mysql@8.0` |
| Herd（任意） | メニューバーのHerdアイコン → Quit |

MySQL・Herd は常駐させたままで問題ありません。ESS と共用しているため、**MySQL は停止しないことを推奨**します。

---

## 開発時の注意

- **設定キャッシュを打たない**。`config:cache` / `route:cache` / `view:cache` は本番専用。誤って実行した場合は `php artisan optimize:clear` で解除
- **`composer install` に `--no-dev` を付けない**
- **`npm audit fix` / npm のバージョン更新はしない**。`public/build/` をコミットするため、Windows 機と生成物が食い違うと git 差分ノイズになる
- **SCSS / JS を変更したら `npm run build` を実行し、`public/build/` の成果物もコミットに含める**
- **audio 系コード（`AudioRecord` / `AudioRecordController` / `RecordingV2Controller` / `audio_records`）には触れない**
- DBが混乱したら `php artisan migrate:fresh --seed` で作り直せる（**本番では絶対に使わない**）
