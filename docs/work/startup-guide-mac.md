# 開発環境の起動手順および停止手順【macOS】

トレーニング記録管理システム（training-record-system）の開発環境（Mac）の起動手順です。

Windows 版は `startup-guide-win.md` を参照してください。**MySQL の起動方式が Windows と異なります**（Windows: Docker / Mac: Homebrew）。

---

## 前提

- Laravel Herd（Mac）がインストール済みであること
- Homebrew の `mysql@8.0` がインストール済みであること（就労支援記録管理システムで使用中のもの）
- MySQL・Herd はいずれも**ログイン時に自動起動**するため、通常は起動操作が不要

---

## 現状の開発環境構成

構成：**Laravel Herd（PHP 8.4 + nginx）／ MySQL 8.0（Homebrew・3306）**

就労支援記録管理システム（ESS）と**同一の MySQL サーバーを共用**し、データベース名で分離します。ポート競合は起きません。

| | Windows 開発機 | Mac 開発機 |
| --- | --- | --- |
| MySQL | Docker `training-mysql` | Homebrew `mysql@8.0` |
| ポート | 3308 | 3306 |
| DB名 | `training_record` | `training_record` |

---

## 初回セットアップ（Mac で一度だけ）

### 1. リポジトリの取得

```
mkdir -p ~/workspace/dev
cd ~/workspace/dev
git clone https://github.com/y-ouchi-inmylife/training-record-system.git
cd training-record-system/src
```

> HTTPS 方式のため、初回に GitHub の認証を求められることがあります。その場合はパスワードではなく **Personal Access Token** を入力するか、事前に `gh auth login` を通してください。同じアカウントで他リポジトリをクローン済みであれば、キーチェーンに保存された認証情報が使われるため入力は不要です。

### 2. データベースの作成

MySQL が起動していることを確認します。

```
brew services list
```

`mysql@8.0` が `started` でなければ起動します。

```
brew services start mysql@8.0
```

データベースを作成します。

```
mysql -u root -p -e "CREATE DATABASE training_record CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

> `mysql` コマンドが見つからない場合は「トラブルシューティング」の該当項目を参照してください。`mysql@8.0` は keg-only のため PATH が通っていないことがあります。

続いてアプリ用ユーザーを作成します。Windows 側は Docker コンテナ内の MySQL のため、そこで作られたユーザーは Mac には存在しません。同名のユーザーを Mac 側にも作ることで、`.env` の内容を Windows と揃えられます。

`YOUR_PASSWORD` は `.env` に設定する `DB_PASSWORD` の値に置き換えてください。

```
mysql -u root -p -e "CREATE USER 'trs01_user'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD'; GRANT ALL PRIVILEGES ON training_record.* TO 'trs01_user'@'localhost'; FLUSH PRIVILEGES;"
```

> `DB_HOST=127.0.0.1` でも MySQL 側は `localhost` として扱うため、`@'localhost'` で作成します。

### 3. `.env` の作成

`.env.example` は**本番向けの参照ファイル**です。ローカル開発用のコピー元には使えません。Windows 開発機の `src/.env` を基に作成し、以下を Mac 用に読み替えます。

| キー | Mac での値 |
| --- | --- |
| `APP_URL` | Windows 側の `.env` と**同じ値**にする（後述） |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | **`3306`**（Windows は 3308） |
| `DB_DATABASE` | `training_record` |
| `DB_USERNAME` | Windows と同じ（`trs01_user`）。ただし Mac の MySQL にユーザー作成が必要（後述） |
| `DB_PASSWORD` | **Mac の MySQL のパスワード**（後述） |
| `TRAINER_HOST` | **行ごと削除する** |
| `CLIENT_HOST` | **行ごと削除する** |
| `MAGICK_PATH` | `/opt/homebrew/bin/magick`（`which magick` で確認した値） |
| `FFMPEG_PATH` | `/opt/homebrew/bin/ffmpeg`（`which ffmpeg` で確認した値） |
| `MYSQLDUMP_PATH` | **行ごと削除する** |
| `MYSQL_PATH` | **行ごと削除する** |
| `OPENSSL_PATH` | **行ごと削除する** |
| `MEDIA_STORAGE_BUCKET` | **Mac 開発用バケット名**（Windows・本番とは別バケット） |
| `MEDIA_STORAGE_ENDPOINT` | Windows と同じ（同一アカウント） |
| `MEDIA_STORAGE_ACCESS_KEY_ID` / `SECRET_ACCESS_KEY` | Mac 用バケットへの書き込み権限を持つトークンの値 |
| `BACKUP_STORAGE_*` | さくらのクラウドオブジェクトストレージの値をそのまま移送 |
| `MAIL_*` | そのまま移送 |
| `QUEUE_CONNECTION` | `sync`（Windows と同じ） |

**重要な注意：**

- **DB の認証情報は Windows からコピーしないこと。** Windows は Docker コンテナ内の MySQL、Mac は Homebrew の MySQL で、別のサーバーです。
- **`MAGICK_PATH` / `FFMPEG_PATH` はフルパスで設定すること。** Herd の PHP-FPM は Homebrew のパス（`/opt/homebrew/bin`）を PATH に持たないため、デフォルト値（`magick` / `ffmpeg`）では解決に失敗します。
- **`MYSQLDUMP_PATH` 等は「空欄」ではなく「行ごと削除」すること。** Laravel の `env()` はキーが存在しない場合のみデフォルト値を返します。空値で残すと空文字列が返ります。
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

---

## 起動手順

### 1. 作業ディレクトリへ移動

```
cd ~/workspace/dev/training-record-system/src
```

### 2. 最新を取り込む（機械を移った直後・ブランチ切り替え時）

```
git pull
composer install
npm ci
php artisan migrate:fresh --seed
```

同じ機械で続けて作業する場合は不要です。

### 3. Vite を起動（SCSS / JS を編集する場合）

```
npm run dev
```

`VITE v... ready in ... ms` で待機状態になれば起動完了。**このターミナルは開いたままにします。**

> **ESS とは異なります。** 本システムの `public/build/` は git 管理対象のため、Vite を起動しなくてもコミット済みのビルド成果物で画面は表示されます。`npm run dev` はホットリロードのためのものです。

### 4. ブラウザでアクセス

トレーナー側：

```
http://trs01-dev.test
```

クライアントポータル側：

```
http://trs01-dev.test/client/login
```

本番はサブドメインで分離していますが（トレーナー = `mikan-trs01-staff.inmylife1965.com` / クライアント = `mikan.inmylife1965.com`）、開発環境では `TRAINER_HOST` 未設定によりパスベース検出で両方にアクセスできます。

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

### バックアップ／リストア機能について

`php artisan` のバックアップ／リストア系コマンドは **Mac では動作しません**。`MYSQLDUMP_PATH` / `MYSQL_PATH` / `OPENSSL_PATH` を未設定にしているためです（これらのキーはコード側にデフォルト値がありません）。

Mac で使えるようにする場合は、Homebrew の `openssl@3` を追加インストールし、3つのフルパスを `.env` に設定してください。macOS 同梱の `/usr/bin/openssl` は LibreSSL であり、Windows／本番 Linux の OpenSSL で作成した暗号化バックアップとの互換性が保証されないため、`/usr/bin/openssl` は使わないでください。

また、**`php artisan schedule:work` は実行しないでください**。バックアップコマンドがスケジュール登録されている場合、Mac では失敗します。

---

## トラブルシューティング

### `mysql` / `mysqldump` コマンドが見つからない

Homebrew の `mysql@8.0` は keg-only のため、PATH に自動で追加されません。

```
brew --prefix mysql@8.0
```

出力されたパスの `bin` をシェルの設定に追加します（Apple Silicon の例）。

```
echo 'export PATH="/opt/homebrew/opt/mysql@8.0/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc
```

### `SQLSTATE[HY000] [2002] Connection refused`

MySQL が停止しています。

```
brew services list
brew services start mysql@8.0
```

`DB_PORT` が `3306` になっているかも確認してください。Windows の値（3308）のままだとこのエラーになります。

### `SQLSTATE[HY000] [1045] Access denied`

`DB_USERNAME` / `DB_PASSWORD` が Windows のもの（Docker コンテナ用）のままになっている可能性があります。Mac の Homebrew MySQL の認証情報に置き換えてください。

パスワードに `#` が含まれる場合は引用符で囲みます。

```
DB_PASSWORD="パスワード"
```

### `Unknown database 'training_record'`

データベースが未作成です。初回セットアップの手順2を実行してください。

### レイアウトが崩れる・CSSが当たらない

ESS とは原因が異なります。本システムは `public/build/` をコミットしているため、Vite 未起動が原因ではありません。

1. `git status` で `public/build/` に差分や欠損が出ていないか確認
2. `npm run dev` を Ctrl+C 以外で終了した場合、`public/hot` が残っていることがある。存在すれば削除する

```
ls public/hot
rm public/hot
```

### 全ページが Laravel の 404 になる

`404 | NOT FOUND` という Laravel 標準のエラーページが出る場合、`.env` に `TRAINER_HOST` / `CLIENT_HOST` が残っています。

```
php artisan route:list
```

各ルートの先頭に `localhost/` が付いていれば、その状態です。`.env` の該当行を削除して反映します。

```
php artisan config:clear
php artisan route:clear
```

再度 `route:list` を実行し、`localhost/` が消えていれば解消です。

### `trs01-dev.test` が開けない

サイト登録を確認します。Path が `.../training-record-system/src` になっている必要があります。

```
herd links
```

ズレている場合は登録し直します。

```
herd unlink trs01-dev
cd ~/workspace/dev/training-record-system/src
herd link trs01-dev
```

### メディアのアップロードは通るが変換されない

Herd の子プロセスから `magick` / `ffmpeg` の PATH 解決に失敗している可能性があります。フルパスを確認します。

```
which magick
which ffmpeg
```

`.env` に追記します（この場合のみ）。

```
MAGICK_PATH=/opt/homebrew/bin/magick
FFMPEG_PATH=/opt/homebrew/bin/ffmpeg
```

追記後は `php artisan config:clear` を実行します。

> `QUEUE_CONNECTION=sync` のため変換は同期実行されます。大容量の mov では `max_execution_time` 超過が起きることがあります（既知の課題）。

### メディアのアップロードで「ストレージへの通信に失敗しました」

ブラウザから R2 への直接 PUT が失敗しています。DevTools のコンソール（`⌘ + ⌥ + J`）でエラー文を確認します。

- `blocked by CORS policy` … R2 バケットの CORS 設定を確認（初回セットアップの手順7）。`AllowedOrigins` に `http://trs01-dev.test`、`AllowedMethods` に `PUT`、`AllowedHeaders` に `Content-Type` が必要。エラー文に許可されていないヘッダー名が出ていれば、それを `AllowedHeaders` に追加する
- `403 Forbidden` … API トークンに Mac 用バケットへの書き込み権限が無い

> 署名付き URL の発行はローカルの計算のみで完結し、R2 には問い合わせません。URL が発行されていても、認証情報やトークン権限が正しいことの確認にはなりません。

ブラウザの DevTools の Network タブで切り分けられます。

### `npm ci` / `npm run dev` が失敗する

インストールスクリプトが未承認の可能性があります。

```
npm approve-scripts esbuild
npm approve-scripts fsevents
npm approve-scripts @parcel/watcher
npm ci
```
