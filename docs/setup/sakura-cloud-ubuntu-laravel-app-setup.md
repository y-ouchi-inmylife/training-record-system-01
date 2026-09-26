# さくらのクラウド Ubuntu アプリ構築手順書（トレーニング記録システム・アプリ単位）

## この手順書の範囲

この手順書は、**トレーニング記録システム（trs01）を本番サーバーに載せるための、アプリ単位の作業** をまとめたものです。

サーバー自体の準備（OS・ファイアウォール・SSH・nginx・PHP・MySQL・Composer・certbot・supervisor の導入）は、**サーバー単位手順書**（`sakura-cloud-ubuntu-laravel-server-setup.md`）で済ませてある前提です。

- 表記：【未適用】…手順としては予定しているが、本番ではまだ実施していない

---

## 0. 前提・構成

### 0-1. 本番の構成（2026-09-25 確認）

| 項目 | 説明 | 値 |
|---|---|---|
| サーバー | アプリを載せるサーバー | sakura-cloud-prod-01（さくらのクラウド 東京第2ゾーン） |
| トレーナー用ドメイン | トレーナーが使う画面 | `mikan-trs01-staff.inmylife1965.com` |
| 会員用ドメイン | 会員が使う画面 | `mikan.inmylife1965.com` |
| 配置先 | アプリのコードを置く場所 | `/var/www/training-record-system-01`（Laravel 本体は `src/` 配下） |
| ブランチ | 本番に載せるブランチ | `main` |
| データベース | DB 名 / ユーザー | `training_record_01` / `trs_user_01@localhost` |
| メディアの保存先 | 動画・写真・会員のプロフィール写真 | さくらのオブジェクトストレージ 東京第1サイト（バケット `trs01-media-prod`） |
| DB バックアップの保存先 | 毎日のバックアップ | Cloudflare R2（バケット `trs01-backup-prod`）。サーバー内の `storage/app/backups` は、R2 に送る前にバックアップファイルを一時的に置く場所 |
| メール送信 | 招待・通知メール | さくらのレンタルサーバーの SMTP（ポート 587）、送信元 `noreply@inmylife1965.com` |

1つのアプリを2つのドメインで公開し、`.env` の `TRAINER_HOST` / `CLIENT_HOST` で、トレーナー用と会員用の画面を振り分けている。

### 0-2. 事前に用意しておくもの（サーバーの外）

- **さくらのオブジェクトストレージ**：メディア用バケットと、アクセスキー。バケットの CORS 設定（第7段階）。
- **Cloudflare R2**：バックアップ用バケットと、アクセスキー。
- **メール**：さくらのレンタルサーバーで、送信用のメールアカウント（`noreply@inmylife1965.com`）。
- **DNS**：inmylife1965.com の DNS はさくらインターネット（さくらのレンタルサーバーと同じアカウント）で管理している。2つのサブドメインの A レコードをここに登録する。
- **GitHub**：リポジトリ `y-ouchi-inmylife/training-record-system-01` にデプロイキーを登録できること（第3段階）。

---

## 第1段階：アプリ固有のパッケージ

### 1-1. FFmpeg と ImageMagick

動画変換（mov→mp4）に FFmpeg、画像変換（heic→jpeg、サムネイル、プロフィール写真のリサイズ）に ImageMagick を使う。

```bash
sudo apt install -y ffmpeg imagemagick libheif-plugin-libde265 libheif-plugin-aomdec
which ffmpeg convert
convert -list format | grep -i heic
```

- Ubuntu 24.04 の ImageMagick は **6 系** で、コマンドは `convert`（開発環境の 7 系 `magick` とは名前が違う）。`.env` の `MAGICK_PATH` で `/usr/bin/convert` を指定する（第4段階）。
- `convert -list format` で `HEIC  r--` と表示されれば、HEIC を読める。

実績：ImageMagick 6.9.12、libheif 1.17.6、FFmpeg 6.1.1。本番でメディア変換・プロフィール写真のアップロードが正常に動作することを確認済み。

---

## 第2段階：データベースの作成

アプリ専用の DB とユーザーを作る。パスワードは `.env` にだけ記載し、コミットしない。

```bash
sudo mysql
```

```sql
CREATE DATABASE training_record_01 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'trs_user_01'@'localhost' IDENTIFIED BY '<パスワード>';
GRANT ALL PRIVILEGES ON training_record_01.* TO 'trs_user_01'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 第3段階：コードの取得（GitHub デプロイキー）

### 3-1. デプロイキーを作成し、GitHub に登録する

サーバー専用の鍵を作り、このリポジトリだけを読み取れるようにする。

```bash
ssh-keygen -t ed25519 -f ~/.ssh/github_deploy -C "sakura-cloud-prod-01 deploy"
cat ~/.ssh/github_deploy.pub
```

表示された公開鍵を、GitHub のリポジトリの Settings → Deploy keys に登録する（書き込み権限は付けない）。

`~/.ssh/config` に次を追加する。

```
Host github.com
    HostName github.com
    User git
    IdentityFile ~/.ssh/github_deploy
    IdentitiesOnly yes
```

接続を確認する。

```bash
ssh -T git@github.com
```

### 3-2. clone する

```bash
sudo mkdir -p /var/www/training-record-system-01
sudo chown ubuntu:ubuntu /var/www/training-record-system-01
git clone git@github.com:y-ouchi-inmylife/training-record-system-01.git /var/www/training-record-system-01
cd /var/www/training-record-system-01
git branch --show-current   # main であること
```

---

## 第4段階：アプリの設定

以降、特に断りがなければ `src/` で作業する。

```bash
cd /var/www/training-record-system-01/src
```

### 4-1. PHP の依存ライブラリを入れる

```bash
composer install --no-dev --optimize-autoloader
```

フロントのビルド（`npm run build`）は不要。ビルド成果物（`public/build/`）はリポジトリにコミットしてある。

### 4-2. `.env` を作成する

```bash
cp .env.example .env
nano .env
```

主な設定値（本番の実際の値。秘密情報は値を載せず「秘密情報」と記載）：

#### アプリの基本

| 項目 | 値 |
|---|---|
| `APP_KEY` | 秘密情報（4-3 で生成） |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://mikan.inmylife1965.com` |

#### DB・セッション・キュー

| 項目 | 値 |
|---|---|
| `DB_DATABASE` | `training_record_01` |
| `DB_USERNAME` | `trs_user_01` |
| `DB_PASSWORD` | 秘密情報 |
| `SESSION_DRIVER` | `database` |
| `FILESYSTEM_DISK` | `local` |
| `QUEUE_CONNECTION` | `sync`（第8段階で `database` に切り替える予定） |
| `CACHE_STORE` | `database` |

#### メール

| 項目 | 値 |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `inmylife1965.sakura.ne.jp` |
| `MAIL_PORT` | `587` |
| `MAIL_USERNAME` | `noreply@inmylife1965.com` |
| `MAIL_PASSWORD` | 秘密情報 |
| `MAIL_FROM_ADDRESS` | `noreply@inmylife1965.com` |

#### ドメイン

| 項目 | 値 |
|---|---|
| `TRAINER_HOST` | `mikan-trs01-staff.inmylife1965.com` |
| `CLIENT_HOST` | `mikan.inmylife1965.com` |

#### メディア（画像・動画変換とオブジェクトストレージ）

| 項目 | 値 |
|---|---|
| `MAGICK_PATH` | `/usr/bin/convert` |
| `FFMPEG_PATH` | `/usr/bin/ffmpeg` |
| `MEDIA_STORAGE_ENDPOINT` | `https://s3.tky01.sakurastorage.jp` |
| `MEDIA_STORAGE_BUCKET` | `trs01-media-prod` |
| `MEDIA_STORAGE_REGION` | `jp-east-1` |
| `MEDIA_STORAGE_ACCESS_KEY_ID` | 秘密情報 |
| `MEDIA_STORAGE_SECRET_ACCESS_KEY` | 秘密情報 |

#### DB バックアップ

| 項目 | 値 |
|---|---|
| `BACKUP_DIRECTORY` | `/var/www/training-record-system-01/src/storage/app/backups` |
| `BACKUP_ENCRYPTION_KEY` | 秘密情報 |
| `MYSQLDUMP_PATH` | `/usr/bin/mysqldump` |
| `MYSQL_PATH` | `/usr/bin/mysql` |
| `OPENSSL_PATH` | `/usr/bin/openssl` |
| `BACKUP_STORAGE_ENDPOINT` | Cloudflare R2 のエンドポイント |
| `BACKUP_STORAGE_BUCKET` | `trs01-backup-prod` |
| `BACKUP_STORAGE_REGION` | 設定しない |
| `BACKUP_STORAGE_ACCESS_KEY_ID` | 秘密情報 |
| `BACKUP_STORAGE_SECRET_ACCESS_KEY` | 秘密情報 |

#### AI（OpenAI・Anthropic）

| 項目 | 値 |
|---|---|
| `OPENAI_API_KEY` | 秘密情報 |
| `OPENAI_ORGANIZATION` | 設定しない |
| `OPENAI_REQUEST_TIMEOUT` | `300` |
| `ANTHROPIC_API_KEY` | 秘密情報 |
| `ANTHROPIC_MODEL` | `claude-sonnet-4-6` |

秘密情報は、サーバー上の `.env` にだけ記載する。`.env` を変更したら `sudo -u www-data php artisan config:clear` を実行する。

### 4-3. アプリケーションキーを生成する

キーを画面に表示させ、`.env` の `APP_KEY=` に貼り付ける。

```bash
sudo -u www-data php artisan key:generate --show
```

### 4-4. 権限を設定する

コードは `ubuntu`の所有、書き込みが必要な `storage/` と `bootstrap/cache/` は `www-data` の所有にする。`.env` は、`ubuntu` が編集でき、`www-data`（PHP）が読めるだけにする。

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chown ubuntu:www-data .env
chmod 640 .env
```

### 4-5. テーブルを作成し、キャッシュを整える

artisan は `www-data` で実行する（ログやキャッシュのファイルが `ubuntu` の所有で作られて、PHP から書き込めなくなるのを防ぐ）。

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan route:clear
```

- 初期データ（システム管理者アカウントなど）は、シーダーで投入する（本番では `--force` が必要）。

```bash
sudo -u www-data php artisan db:seed --force
```

- `php artisan storage:link` は不要。メディアもプロフィール写真も `media` ディスク（オブジェクトストレージ）に保存しており、`public/storage` は使わない。

---

## 第5段階：nginx のサイト設定

1つの設定ファイルに、2つのドメイン分を書く。どちらも同じ `public/` を公開する。

```bash
sudo nano /etc/nginx/sites-available/training-record-system-01
```

証明書取得前の内容（ドメインごとに同じ形の `server` ブロックを2つ書く）：

```nginx
server {
    listen 80;
    server_name mikan.inmylife1965.com;

    root /var/www/training-record-system-01/src/public;
    index index.php;
    charset utf-8;

    client_max_body_size 200M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

server {
    listen 80;
    server_name mikan-trs01-staff.inmylife1965.com;
    # 以下、上と同じ内容
}
```

- `client_max_body_size` は、このアプリで受け付けるリクエストの大きさの上限。PHP 側の上限（サーバー全体で 25MB、サーバー単位手順書 2-2）より大きくしても、実際には 25MB までしか受け付けない。このアプリで Laravel 経由で受け取るファイルは会員のプロフィール写真だけ（動画・写真の本体はオブジェクトストレージに直接アップロードする）。
- `location ~ /\.(?!well-known).*` は、`.env` など `.` で始まるファイルを Web から見えなくする設定。
- SSL の設定は、第6段階で certbot が書き足す。

有効にして、設定を確認・反映する。

```bash
sudo ln -s /etc/nginx/sites-available/training-record-system-01 /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 第6段階：DNS と SSL 証明書

### 6-1. DNS

inmylife1965.com の DNS はさくらインターネットで管理している。2つのサブドメインの A レコードを、サーバーの IP アドレス（163.43.140.224）に向ける。

| ホスト名 | 種別 | 値 |
|---|---|---|
| `mikan` | A | 163.43.140.224 |
| `mikan-trs01-staff` | A | 163.43.140.224 |

- 注意：さくらのレンタルサーバーの「ドメイン/SSL」の画面に、これらのサブドメインを追加する必要はない（追加すると、レンタルサーバー側を向く DNS 設定が作られる場合がある）。DNS のレコードだけを登録する。

反映を確認する（ローカル端末から）：

```
nslookup mikan.inmylife1965.com
nslookup mikan-trs01-staff.inmylife1965.com
```

### 6-2. SSL 証明書

DNS が反映されてから、ドメインごとに証明書を取得する。nginx プラグインが、証明書の設定と HTTP→HTTPS のリダイレクトを nginx の設定ファイルに書き込む。

```bash
sudo certbot --nginx -d mikan.inmylife1965.com
sudo certbot --nginx -d mikan-trs01-staff.inmylife1965.com
sudo certbot renew --dry-run
```

---

## 第7段階：オブジェクトストレージの CORS

動画・写真は、ブラウザからオブジェクトストレージへ署名付き URL で直接アップロードする。そのため、**さくらのオブジェクトストレージのバケット（`trs01-media-prod`）の CORS 設定** で、本番ドメインからのアップロードを許可する。Laravel 側の `config/cors.php` とは別物なので混同しない。

コントロールパネル：オブジェクトストレージ → 東京第1サイト → バケット `trs01-media-prod` → 「CORS設定」タブ。

本番の設定（CORSRule 1）：

| 項目 | 値 |
|---|---|
| AllowedOrigins | `https://mikan.inmylife1965.com`、`https://mikan-trs01-staff.inmylife1965.com` |
| AllowedMethods | GET、POST、PUT、HEAD（DELETE は許可しない） |
| AllowedHeaders | `*` |
| ExposeHeaders | なし |

- 開発環境（`http://127.0.0.1:8080` など）のオリジンは、本番のバケットには入れない。

---

## 第8段階：キューワーカーの常駐　【未適用】

### 8-1. 現在の状態と、処理時間の上限

現在は `QUEUE_CONNECTION=sync` のため、メディア変換や AI の処理が、ブラウザからのリクエストの中で実行される。そのため、1回のリクエストにかけられる時間の上限を意識する必要がある。

- **PHP の `max_execution_time`（30 秒）**：Linux では、PHP 自身が計算している時間だけを数える。外部コマンド（FFmpeg・ImageMagick）の実行、API（OpenAI・Anthropic）の応答、DB の応答を待つ時間は数えない。そのため、変換や AI の処理では、この上限には当たりにくい。（Windows ではこれらの待ち時間も数えるため、開発環境では 30 秒で止まることがある。）
- **nginx の `fastcgi_read_timeout`（初期値 60 秒）**：nginx が PHP の応答を待つ時間。trs01 のサイト設定では指定しておらず、初期値の 60 秒になっている。本番で実際に先に効くのはこちら。60 秒を超えると、ブラウザに「504 Gateway Time-out」が表示される。このとき PHP 側の処理は裏で続いていることがあり、画面はエラーでも、結果は保存されている、という分かりにくい状態になり得る。

### 8-2. 運用の方針

AI の応答を同期にするか非同期にするかも含めて、実際の運用と相談しながら決める。設定値は現在のまま（`QUEUE_CONNECTION=sync`、`OPENAI_REQUEST_TIMEOUT=300`、nginx の `fastcgi_read_timeout` は初期値）とし、504 が出る、画面の待ち時間が長い、といった状況が出てきたら、次のどちらかを検討する。

- **nginx の `fastcgi_read_timeout` を延ばす**：trs01 のサイト設定にだけ効くため、他のアプリには影響しない。ただし、会員やトレーナーはその間、画面で待ち続けることになる。
- **キューに切り替える**（8-3 の手順）：処理を裏（ワーカー）で実行するため、リクエストの時間の上限から切り離せる。根本的な解決策。

### 8-3. キューに切り替える手順

1. `.env` の `QUEUE_CONNECTION` を `database` に変更する。
2. `jobs` / `failed_jobs` テーブルのマイグレーションがあるか確認し、なければ整備する。
3. supervisor にワーカーの設定を追加する（下は案。導入時に検証して確定する）。

```bash
sudo nano /etc/supervisor/conf.d/training-record-system-01-worker.conf
```

```ini
[program:training-record-system-01-worker]
command=/usr/bin/php /var/www/training-record-system-01/src/artisan queue:work --sleep=3 --tries=1 --timeout=600
user=www-data
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/training-record-system-01/src/storage/logs/worker.log
stopwaitsecs=610
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

- キューの設定はアプリ全体で共通のため audio 系のジョブもキューに乗るが、**audio 関連のコード・ジョブ定義は変更しない**。対象はメディア変換系のみ。

---

## 第9段階：DB バックアップの cron

`www-data` の crontab に、毎日 04:00 のバックアップを登録する。スケジューラ（`schedule:run`）は使わず、コマンドを直接登録する。

```bash
sudo crontab -u www-data -e
```

```
0 4 * * * cd /var/www/training-record-system-01/src && /usr/bin/php artisan db:backup >> storage/logs/cron-backup.log 2>&1
```

---

## 第10段階：動作確認

- [ ] 2つのドメインが HTTPS で開く（HTTP は HTTPS に転送される）
- [ ] トレーナーがログインできる（`mikan-trs01-staff`）
- [ ] 会員の登録メールが届き、登録・ログインできる（`mikan`）
- [ ] 動画・写真をアップロードできる（オブジェクトストレージへの直接アップロード）
- [ ] メディア変換（heic→jpeg、mov→mp4）が完了する
- [ ] サムネイルが表示される
- [ ] 会員のプロフィール写真をアップロードできる
- [ ] 翌朝、R2 のバケット `trs01-backup-prod` にバックアップが作られている（`storage/logs/cron-backup.log` も確認）

---

## 第11段階：定常のデプロイ（コード更新の反映）

```bash
cd /var/www/training-record-system-01
git pull
cd src
composer install --no-dev --optimize-autoloader   # composer.lock が変わったとき
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan route:clear
# キューワーカー導入後は、コードを読み直させるために再起動する
# sudo supervisorctl restart training-record-system-01-worker
```

- `npm run build` は不要（`public/build/` はコミット済み）。SCSS などを変更したときは、開発環境でビルドして、ハッシュ付きの CSS と `manifest.json` ごとコミットする。
- `.env` だけを変更したときは `config:clear` を実行する。
- `optimize:clear` は使わない。3つのキャッシュに加えてアプリケーションのキャッシュ（`cache` テーブル）まで消すため、キャッシュに記録されたデータ（ログインの連続失敗の回数など）がリセットされるおそれがある。

---

## 付録　本番の未対応事項（2026-09-26 時点）

- [ ] 第8段階 キューワーカーの常駐（`QUEUE_CONNECTION=database` への切り替えと supervisor の導入）：AI の応答の同期・非同期も含め、実際の運用を見て判断する
- [ ] （任意）nginx の `client_max_body_size` を 200M から 25M にそろえる：PHP 側の上限が 25MB のため、200M のままでも実際には 25MB までしか受け付けない。値をそろえて設定の意図を分かりやすくする
