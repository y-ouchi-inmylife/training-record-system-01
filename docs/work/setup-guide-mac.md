# 開発環境の構築手順【macOS】

トレーニング記録管理システム（training-record-system）の開発環境（Mac）の構築手順です。

---

## 前提

- Laravel Herd（Mac）がインストール済みであること
- Homebrew の `mysql@8.0` がインストール済みであること
- MySQL・Herd はいずれも**ログイン時に自動起動**するため、通常は起動操作が不要

構成：**Laravel Herd（PHP 8.4 + nginx）／ MySQL 8.0（Homebrew・3306）**

---

## 初回セットアップ

### 1. リポジトリの取得

```
mkdir -p ~/workspace/dev
cd ~/workspace/dev
git clone https://github.com/y-ouchi-inmylife/training-record-system-01.git
cd training-record-system-01/src
```

> HTTPS 方式のため、初回に GitHub の認証を求められることがあります。その場合はパスワードではなく **Personal Access Token** を入力するか、事前に `gh auth login` を通してください。同じアカウントで他リポジトリをクローン済みであれば、キーチェーンに保存された認証情報が使われるため入力は不要です。

### 2. データベースの作成

1. MySQL が起動していることを確認します。

```
brew services list
```

`mysql@8.0` が `started` でなければ起動します。

```
brew services start mysql@8.0
```

2. データベースを作成します。

```
mysql -u root -p -e "CREATE DATABASE training_record CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

> `mysql` コマンドが見つからない場合は「トラブルシューティング」の該当項目を参照してください。`mysql@8.0` は keg-only のため PATH が通っていないことがあります。

3. 続いてアプリ用ユーザーを作成します。Windows 側は Docker コンテナ内の MySQL のため、そこで作られたユーザーは Mac には存在しません。同名のユーザーを Mac 側にも作ることで、`.env` の内容を Windows と揃えられます。

`YOUR_PASSWORD` は `.env` に設定する `DB_PASSWORD` の値に置き換えてください。

```
mysql -u root -p -e "CREATE USER 'trs01_user'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD'; GRANT ALL PRIVILEGES ON training_record.* TO 'trs01_user'@'localhost'; FLUSH PRIVILEGES;"
```

> `DB_HOST=127.0.0.1` でも MySQL 側は `localhost` として扱うため、`@'localhost'` で作成します。

### 3. `.env` の作成

`.env.example` は**本番向けの参照ファイル**です。ローカル開発用のコピー元には使えません。Windows 開発機の `src/.env` を基に作成し、以下を Mac 用に読み替えます。

| キー | Mac での値 |
| --- | --- |
| `APP_URL` | Windows 側の `.env` と**同じ値**にする |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | **`3306`** |
| `DB_DATABASE` | `training_record` |
| `DB_USERNAME` | `trs01_user` |
| `DB_PASSWORD` | **Mac の MySQL のパスワード** |
| `TRAINER_HOST` | **行ごと削除する** |
| `CLIENT_HOST` | **行ごと削除する** |
| `MAGICK_PATH` | `/opt/homebrew/bin/magick` |
| `FFMPEG_PATH` | `/opt/homebrew/bin/ffmpeg` |
| `MYSQLDUMP_PATH` | `/opt/homebrew/opt/mysql@8.0/bin/mysqldump` |
| `MYSQL_PATH` | `/opt/homebrew/opt/mysql@8.0/bin/mysql` |
| `OPENSSL_PATH` | `/opt/homebrew/opt/openssl@3/bin/openssl` |
| `MEDIA_STORAGE_BUCKET` | `trs01-media-dev-mac` |
| `MEDIA_STORAGE_ENDPOINT` | 書き込み権限を持つトークンの値 |
| `MEDIA_STORAGE_ACCESS_KEY_ID` | 書き込み権限を持つトークンの値 |
| `MEDIA_STORAGE_ACCESS_KEY_ID` | 書き込み権限を持つトークンの値 |
| `BACKUP_STORAGE_BUCKET` | `shared-backup-dev` |
| `BACKUP_STORAGE_ENDPOINT` | 書き込み権限を持つトークンの値 |
| `BACKUP_STORAGE_ACCESS_KEY_ID` | 書き込み権限を持つトークンの値 |
| `BACKUP_STORAGE_ACCESS_KEY_ID` | 書き込み権限を持つトークンの値 |
| `MAIL_MAILER` | `log` |
| `MAIL_LOG_CHANNEL` | `mail` |
| `MAIL_SCHEME` | `null` |
| `MAIL_HOST` | `127.0.0.1` |
| `MAIL_PORT` | `2525` |
| `MAIL_USERNAME` | `noreply@inmylife1965.com` |
| `MAIL_PASSWORD` | `null` |
| `MAIL_FROM_ADDRESS` | `noreply@inmylife1965.com` |
| `MAIL_FROM_NAME` | "${APP_NAME}" |
| `QUEUE_CONNECTION` | `sync` |

**重要な注意：**

- **DB の認証情報は Windows からコピーしないこと。** Windows は Docker コンテナ内の MySQL、Mac は Homebrew の MySQL で、別のサーバーです。
- **`MAGICK_PATH` / `FFMPEG_PATH` はフルパスで設定すること。** Herd の PHP-FPM は Homebrew のパス（`/opt/homebrew/bin`）を PATH に持たないため、デフォルト値（`magick` / `ffmpeg`）では解決に失敗します。
- **`TRAINER_HOST` / `CLIENT_HOST` の行は削除すること。** Windows 側の `.env` にはこれらが `localhost` で設定されています。残したままだと全ルートに `localhost` のドメイン制約が付き、`trs01-dev.test` でアクセスしても**全ページが 404 になります**。削除すればホスト制約が外れ、パスベース検出にフォールバックします。
- `DB_PASSWORD` に `#` が含まれる場合は引用符で囲みます（`DB_PASSWORD="パスワード"`）。
- 認証情報を含むため、`.env` はチャットやファイル共有経由で送らず、安全な経路で移送してください。

### 4. Herd へのサイト登録

**サイト名は `trs01-dev` にします。** R2 バケットの CORS 設定で許可するオリジン（`http://trs01-dev.test`）と一致させる必要があります。

```
cd ~/workspace/dev/training-record-system/src
herd link trs01-dev
```

> `herd link` を実行すると `.env` の `APP_URL` が自動的に `http://trs01-dev.test` へ書き換わります。サイト名を張り直した場合は `APP_URL` を目視確認してください。

PHP バージョンが 8.4 であることを確認します。

```
php -v
```

異なる場合はこのサイトだけ 8.4 に固定します。

```
herd isolate php@8.4
```

### 5. 依存の取得とDB構築

```
composer install
npm ci
php artisan migrate:fresh --seed
```

> `npm install` ではなく **`npm ci`** を使います。`public/build/` を git 管理しているため、依存を Windows 機と完全一致させる必要があります。

`APP_KEY` が未設定の場合のみ生成します。

```
php artisan key:generate
```

### 6. 外部コマンドの確認

メディア変換に ImageMagick と ffmpeg を使用します。

```
magick -version
ffmpeg -version
```

未インストールの場合：

```
brew install imagemagick ffmpeg
```

インストール先を確認し、その値を `.env` の `MAGICK_PATH` / `FFMPEG_PATH` に設定します。

```
which magick
which ffmpeg
```

```
php artisan config:clear
```

> 動作確認はターミナル（`tinker` 等）ではなく**ブラウザからのアップロード**で行ってください。ターミナルでは PATH が通っているため、Herd 経由の実行環境と条件が異なります。HEIC と MOV をアップロードし、表示用の JPEG / MP4 が生成されることを確認します。

### 7. Cloudflare R2 の開発用バケット設定

Mac 開発機は**専用のバケット**を使います（本番・Windows 開発機とは共有しない）。

1. Cloudflare ダッシュボード → R2 で Mac 開発用バケットを作成
2. 「API トークンの管理」で、そのバケットへの書き込み権限を持つトークンを用意（既存トークンの対象バケットに追加するか、新規発行）
3. バケット → Settings → **CORS Policy** に以下を設定

```json
[
  {
    "AllowedOrigins": [
      "http://trs01-dev.test"
    ],
    "AllowedMethods": [
      "GET",
      "PUT"
    ],
    "AllowedHeaders": [
      "Content-Type"
    ],
    "MaxAgeSeconds": 3600
  }
]
```

> ブラウザから署名付き URL で直接 PUT するため、`PUT` と `AllowedHeaders` の両方が必須です。新規作成時のテンプレート（`GET` のみ）のままではプリフライトで拒否されます。
