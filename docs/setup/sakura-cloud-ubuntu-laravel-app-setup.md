# 【トレーニング記録システム（みかん）】さくらのクラウド Ubuntu アプリ構築手順書

## この手順書の範囲

この手順書は、**トレーニング記録システム（以下「本システム」）を本番サーバーに載せるための、アプリごとの作業** をまとめたものです。

サーバー自体の準備（OS・ファイアウォール・SSH・nginx・PHP・MySQL・Composer・certbot・supervisor の導入）は、**サーバー構築手順書**（`sakura-cloud-ubuntu-laravel-server-setup.md`）で済ませてある前提です。

このサーバーには、同じサーバーの他のシステムも載る。そのため、このアプリは **専用の実行ユーザー（`trs01`）と専用の PHP-FPM のプール** で動かし、他のシステムと互いの `.env` や `storage/` を読めないようにする（第3段階・第6段階）。

- 表記：【未適用】…手順としては予定しているが、本番ではまだ実施していない

---

## 前提・構成

### 本番の構成（2026-09-26 確認）

| 項目 | 説明 | 値 |
|---|---|---|
| サーバー | アプリを載せるサーバー | sakura-cloud-prod-01（さくらのクラウド 東京第2ゾーン） |
| ドメイン（トレーナー用） | トレーナーが使う画面 | `mikan-trs01-staff.inmylife1965.com` |
| ドメイン（会員用） | 会員が使う画面 | `mikan.inmylife1965.com` |
| 配置先 | アプリのコードを置く場所 | `/var/www/training-record-system-01`（Laravel 本体は `src/` 配下） |
| 実行ユーザー | PHP・artisan・cron を動かすユーザー（同じサーバーの他のシステムに脆弱性があり、外から PHP のコードを実行されても、このアプリの `.env`（DB のパスワード、外部サービスのキー、バックアップの暗号化キー）や `storage/` を読めないようにするため） | `trs01`（専用の PHP-FPM のプール `trs01`、ソケット `/run/php/php8.4-fpm-trs01.sock`） |
| ブランチ | 本番に載せるブランチ | `main` |
| データベース | DB 名 / ユーザー | `training_record_01` / `trs_user_01@localhost` |
| メディアの保存先 | 動画・写真・会員のプロフィール写真 | さくらのオブジェクトストレージ 東京第1サイト（バケット `trs01-media-prod`） |
| DB バックアップの保存先 | 毎日のバックアップ | Cloudflare R2（バケット `trs01-backup-prod`）。サーバー内の `storage/app/backups` は、R2 に送る前にバックアップファイルを一時的に置く場所 |
| 外部 API | 音声の文字起こし・要約 | OpenAI（Whisper）、Anthropic（Claude） |
| アクセス制限 | 画面を開ける場所 | nginx で、事業所2拠点の固定 IP アドレスからだけ許可する。利用者が使う事前入力の画面だけは、どこからでも開ける |
| メール送信 | 招待・通知メール | さくらのレンタルサーバーの SMTP（ポート 587）、送信元 `noreply@inmylife1965.com` |

1つのアプリを2つのドメインで公開し、`.env` の `TRAINER_HOST` / `CLIENT_HOST` で、トレーナー用と会員用の画面を振り分けている。

逆に、このアプリに脆弱性があった場合も、他のシステムに届かない。

### 事前に用意しておくもの（サーバーの外）

- **さくらのオブジェクトストレージ**：メディア用バケットと、アクセスキー。バケットの CORS 設定（第9段階）。
- **Cloudflare R2**：バックアップ用バケットと、アクセスキー。
- **メール**：さくらのレンタルサーバーで、送信用のメールアカウント（`noreply@inmylife1965.com`）。
- **DNS**：inmylife1965.com の DNS はさくらインターネット（さくらのレンタルサーバーと同じアカウント）で管理している。2つのサブドメインの A レコードをここに登録する。
- **GitHub**：リポジトリ `y-ouchi-inmylife/training-record-system-01` にデプロイキーを登録できること（第4段階）。
- **外部 API のキー**：OpenAI、Anthropic。

---

## 第1段階：アプリ固有のパッケージ

### 1-1. FFmpeg と ImageMagick

動画変換（mov→mp4）に FFmpeg、画像変換（heic→jpeg、サムネイル、プロフィール写真のリサイズ）に ImageMagick を使う。

```bash
sudo apt install -y ffmpeg imagemagick libheif-plugin-libde265 libheif-plugin-aomdec
which ffmpeg convert
convert -list format | grep -i heic
```

- Ubuntu 24.04 の ImageMagick は **6 系** で、コマンドは `convert`（開発環境の 7 系 `magick` とは名前が違う）。`.env` の `MAGICK_PATH` で `/usr/bin/convert` を指定する（第5段階）。
- `convert -list format` で `HEIC  r--` と表示されれば、HEIC を読める。

実績：ImageMagick 6.9.12、libheif 1.17.6、FFmpeg 6.1.1。本番でメディア変換・プロフィール写真のアップロードが正常に動作することを確認済み。

---

## 第2段階：実行ユーザー

本システムの PHP・artisan・cron・キューワーカーは、すべて **専用のユーザー `trs01`** で動かす。

```bash
sudo adduser --system --group --no-create-home --home /nonexistent --shell /usr/sbin/nologin trs01
sudo usermod -aG trs01 ubuntu
id trs01
```

- `trs01` はログインできないシステムユーザー。
- `ubuntu` を `trs01` グループに入れるのは、`git pull` や `composer install` で `storage/`・`bootstrap/cache/` の中のファイルを更新できるようにするため。グループへの追加は、`ubuntu` がログインし直してから有効になる。

役割の分担：

| 対象 | ユーザー |
|---|---|
| コード（`git pull`、`composer install`） | `ubuntu` |
| PHP（PHP-FPM のプール） | `trs01`（第6段階） |
| artisan | `trs01`（`sudo -u trs01 php artisan …`） |
| cron | `trs01` の crontab（第11段階） |
| キューワーカー | `trs01`（第10段階） |
| nginx | `www-data`（PHP-FPM のソケットにつなぐだけ） |

---

## 第3段階：データベースの作成

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

## 第4段階：ソースコードの取得（GitHub デプロイキー）

### 4-1. デプロイキーを作成し、GitHub に登録する

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

### 4-2. clone する

```bash
sudo mkdir -p /var/www/training-record-system-01
sudo chown ubuntu:ubuntu /var/www/training-record-system-01
git clone git@github.com:y-ouchi-inmylife/training-record-system-01.git /var/www/training-record-system-01
cd /var/www/training-record-system-01
git branch --show-current   # main であること
```

---

## 第5段階：アプリの設定

以降、特に断りがなければ `src/` で作業する。

```bash
cd /var/www/training-record-system-01/src
```

### 5-1. PHP の依存ライブラリを入れる

```bash
composer install --no-dev --optimize-autoloader
```

フロントのビルド（`npm run build`）は不要。ビルド成果物（`public/build/`）はリポジトリにコミットしてある。

### 5-2. `.env` を作成する

```bash
cp .env.example .env
nano .env
```

主な設定値（本番の実際の値。秘密情報は値を載せず「秘密情報」と記載）：

#### アプリの基本

| 項目 | 値 |
|---|---|
| `APP_KEY` | 秘密情報（5-3 で生成） |
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
| `QUEUE_CONNECTION` | `sync`（第10段階の段階 2 で `database` に切り替える） |
| `DB_QUEUE_RETRY_AFTER` | 書かない（既定の `660`。第10段階の段階 2 で明示する） |
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

#### 外部 API

| 項目 | 値 |
|---|---|
| `OPENAI_API_KEY` | 秘密情報 |
| `OPENAI_ORGANIZATION` | 設定しない |
| `OPENAI_REQUEST_TIMEOUT` | `300` |
| `ANTHROPIC_API_KEY` | 秘密情報 |
| `ANTHROPIC_MODEL` | `claude-sonnet-4-6` |

秘密情報は、サーバー上の `.env` にだけ記載する。`.env` を変更したら `sudo -u trs01 php artisan config:clear` を実行する。

### 5-3. アプリケーションキーを生成する

キーを画面に表示させ、`.env` の `APP_KEY=` に貼り付ける。

```bash
sudo -u trs01 php artisan key:generate --show
```

### 5-4. 権限を設定する

- コード：`ubuntu` の所有で、誰でも読める（秘密情報は含まない）。
- `storage/`・`bootstrap/cache/`：`trs01` の所有。`trs01` グループ（`ubuntu` を含む）も書ける。それ以外のユーザー（`www-data` を含む）は読めない。
- `.env`：`ubuntu` が編集でき、`trs01`（PHP）が読めるだけ。それ以外のユーザーは読めない。

```bash
sudo chown -R trs01:trs01 storage bootstrap/cache
sudo chmod -R g+rwX,o-rwx storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
sudo chown ubuntu:trs01 .env
chmod 640 .env
```

- 3行目（フォルダに `g+s` を付ける）は、`ubuntu` が `composer install` などで作ったファイルも、グループが `trs01` になるようにするため。`composer install` は最後に `php artisan package:discover` を実行し、`bootstrap/cache/` にファイルを書く。

### 5-5. テーブルを作成し、キャッシュを整える

artisan は `trs01` で実行する（ログやキャッシュのファイルが `ubuntu` の所有で作られて、PHP から書き込めなくなるのを防ぐ）。

```bash
sudo -u trs01 php artisan migrate --force
sudo -u trs01 php artisan config:clear
sudo -u trs01 php artisan view:clear
sudo -u trs01 php artisan route:clear
```

- 初期データ（システム管理者アカウントなど）は、シーダーで投入する（本番では `--force` が必要）。

```bash
sudo -u trs01 php artisan db:seed --force
```

- `php artisan storage:link` は不要。メディアもプロフィール写真も `media` ディスク（オブジェクトストレージ）に保存しており、`public/storage` は使わない。

---

## 第6段階：PHP-FPM のプール

このアプリ専用の PHP-FPM のプールを作る。プールは `trs01` で PHP を動かし、PHP から読み書きできる場所を、アプリの配置先と専用の一時ディレクトリだけに制限する。

前提：サーバー構築手順書の「アプリごとに別のユーザーで PHP を動かすための設定（OPcache）」を適用済みであること。

### 6-1. PHP の一時ファイルの置き場を作る

```bash
sudo install -d -o trs01 -g trs01 -m 700 /var/lib/php/trs01-tmp
```

アップロードされたファイルの一時置き場と、PHP の一時ファイルの置き場。`/tmp` は他のシステムと共有の場所なので使わない。

### 6-2. PHP-FPM のプールを作る

```bash
sudo nano /etc/php/8.4/fpm/pool.d/trs01.conf
```

```ini
[trs01]
user = trs01
group = trs01

listen = /run/php/php8.4-fpm-trs01.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 60s
pm.max_requests = 500

php_admin_value[open_basedir] = /var/www/training-record-system-01/:/var/lib/php/trs01-tmp/
php_admin_value[upload_tmp_dir] = /var/lib/php/trs01-tmp
php_admin_value[sys_temp_dir] = /var/lib/php/trs01-tmp
php_admin_value[upload_max_filesize] = 100M
php_admin_value[post_max_size] = 110M
```

| 設定 | 説明 |
|---|---|
| `user` / `group` | PHP を動かすユーザー |
| `listen` | nginx からつなぐソケット。`www-data`（nginx）だけがつなげる |
| `pm = ondemand` | リクエストが来たときだけ PHP のプロセスを起動し、60 秒使われなければ止める（メモリを空けておくため） |
| `pm.max_children` | 同時に動かす PHP のプロセスの上限 |
| `open_basedir` | PHP から読み書きできる場所を、アプリの配置先と一時ディレクトリだけに制限する。外部コマンド（ImageMagick・FFmpeg・mysqldump・openssl）が起動した先のプロセスには効かない |
| `upload_tmp_dir` / `sys_temp_dir` | アップロードの一時ファイルと、PHP の一時ファイルの置き場所 |
| `upload_max_filesize` | アップロードする1つのファイルの上限。録音のアプリの上限（100MB）に合わせる |
| `post_max_size` | 1回の送信全体の上限。ファイルのほかの項目の分を見込んで、ファイルの上限より少し大きくする |

- `memory_limit` は設定しない（PHP の既定の 128M）。
- アップロードの上限は、このプールで個別に上げている（サーバー全体の 25MB は変えない。サーバー構築手順書 2-2）。録音のファイル（アプリの上限 100MB）を受け取るため。
- アプリが使う一時ファイル（メディア変換・サムネイル・プロフィール写真）は、すべて `storage/app/tmp/` の中に作られるため、`open_basedir` の範囲に入っている。

### 6-3. 反映する

```bash
sudo php-fpm8.4 -t
sudo systemctl restart php8.4-fpm
ls -l /run/php/php8.4-fpm-trs01.sock
```

- `php-fpm8.4 -t` で `test is successful` と表示されること。
- PHP-FPM の再起動の間、同じサーバーのすべてのシステムが一瞬止まるので、利用の少ない時間帯に行う。
- `pm = ondemand` なので、リクエストが来るまで `trs01` の PHP のプロセスは起動しない。

---

## 第7段階：nginx の設定

### 7-1. サイトの設定

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

    client_max_body_size 110M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm-trs01.sock;
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

- `fastcgi_pass` は、第6段階で作ったこのアプリ専用のプールのソケット。2つの `server` ブロックの両方に書く。
- `client_max_body_size` は、このアプリで受け付けるリクエストの大きさの上限。第6段階のプールの `post_max_size`（110M）に合わせる。
- このアプリで Laravel 経由で受け取るファイルは、会員のプロフィール写真（アプリの上限 20MB）と録音（アプリの上限 100MB）。動画・写真の本体は、オブジェクトストレージに直接アップロードするため、この上限とは関係ない。
- 上限を超えたときの表示：100MB を少し超える程度なら、アプリのエラーメッセージが表示される。110MB を超えると nginx が先に断り、nginx の既定のエラーページ（413）が表示される。
- `location ~ /\.(?!well-known).*` は、`.env` など `.` で始まるファイルを Web から見えなくする設定。
- SSL の設定は、第8段階で certbot が書き足す。

### 7-2. 文字起こし・要約の API の待ち時間を延ばす

文字起こしと要約は、ブラウザからのリクエストの中で実行する（キューの設定にかかわらず `dispatchSync` でその場で動かす。第10段階）。60 分の録音の文字起こしは、音声の変換と外部 API の応答を合わせて 1 分半〜2 分かかり、nginx が PHP の応答を待つ時間の初期値（`fastcgi_read_timeout` 60 秒）を超える。そのため、**この2つの API だけ**、待ち時間を 300 秒に延ばす。

トレーナー用ドメイン（`mikan-trs01-staff.inmylife1965.com`）の `server` ブロックの中、`location ~ \.php$` の前に、次を加える。

```nginx
    # 文字起こし・要約は処理に時間がかかるため、この API だけ PHP の応答を待つ時間を延ばす
    location ~ ^/api/audio-records/[0-9]+/(transcribe|summarize)$ {
        fastcgi_pass unix:/run/php/php8.4-fpm-trs01.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_read_timeout 300s;
    }
```

- この2つの API へのリクエストを、`index.php` に直接渡す。`SCRIPT_FILENAME` と `SCRIPT_NAME` を `index.php` に合わせるのは、Laravel が URL を正しく読み取れるようにするため。
- 300 秒の根拠：変換後に上限に収まる最長の録音（約 2 時間 10 分）でも、変換が約 1 分、外部 API が 2〜3 分の見込みで、300 秒に収まる。`OPENAI_REQUEST_TIMEOUT`（300 秒）とも合う。
- ほかのページやリクエストの待ち時間は、初期値（60 秒）のまま。
- 処理が終わるまで、PHP のプロセスを1つ使い続ける（プールの `pm.max_children` は 5）。同時に何人も長い文字起こしをすると、ほかの画面の表示が遅くなることがある。利用が増えてきたら、文字起こし・要約のキューへの切り替え（第10段階。録音実行の流れの作り直しが要る）を検討する。
- メディアの変換・サムネイルの API は、待ち時間を延ばしていない（60 秒のまま）。本番でキューを使う（第10段階の段階 2）と、API はジョブを渡してすぐ返るため、延ばす必要はない。

実績（2026-09-28）：60 分の録音で、変換約 27 秒、外部 API 約 70 秒、合わせて約 1 分半〜2 分。

### 7-3. 有効にして設定を反映する

```bash
sudo ln -s /etc/nginx/sites-available/training-record-system-01 /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 第8段階：DNS と SSL 証明書

### 8-1. DNS

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

### 8-2. SSL 証明書

DNS が反映されてから、ドメインごとに証明書を取得する。nginx プラグインが、証明書の設定と HTTP→HTTPS のリダイレクトを nginx の設定ファイルに書き込む。

```bash
sudo certbot --nginx -d mikan.inmylife1965.com
sudo certbot --nginx -d mikan-trs01-staff.inmylife1965.com
sudo certbot renew --dry-run
```

---

## 第9段階：オブジェクトストレージの CORS

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

## 第10段階：キューワーカーの常駐　【段階 1 で準備済み。本番への適用は段階 2】

**2026-10 変更**：メディアの変換・サムネイル（`ConvertMediaJob`・`GenerateThumbnailJob`）を、キュー（本番は `database`）で後ろで動かす準備をした（段階 1）。本番への適用（`.env` の切り替えと supervisor の導入）は段階 2 で行う。

### 10-1. 何がキューで動き、何がその場で動くか

| 処理 | 動き方 | 画面の待ち方 |
|---|---|---|
| メディアの変換・サムネイル（`ConvertMediaJob`・`GenerateThumbnailJob`） | キューの設定（`QUEUE_CONNECTION`）に従う。本番（段階 2 のあと）は `database` のワーカーが後ろで動かす | 登録のモーダルが、状態を返す API（`GET /api/media-records/{id}/status`）を 3 秒ごとに問い合わせて終わりを待つ（最大 15 分）。キューでも sync でも動く |
| 文字起こし・要約（`TranscribeAudioJob`・`SummarizeJob`） | **キューの設定にかかわらず、その場で動く**（`dispatchSync`）。nginx の待ち時間は 7-2 のとおり 300 秒 | 画面は API の応答を待つ（応答＝完了）。キューに移すのは別の段階 |
| 会員のプロフィール写真（ImageMagick）・メールの送信 | その場で動く（ジョブを使っていない） | — |

- メディアのジョブは 1 回だけ試す（`$tries = 1`）。時間の上限は 600 秒（`$timeout`）。失敗したとき（ワーカーの時間切れを含む）は、記録の状態を「エラー」にする。
- **時間の関係**：ワーカーの `--timeout=600`（ジョブの時間の上限）＜ キューの `retry_after=660`（ジョブが戻ってこない＝ワーカーが落ちたとみなすまでの秒数）。`retry_after` のほうが短いと、まだ動いている長いジョブが「戻ってこない」とみなされ、もう一度渡されて二重に動く。そのため `config/queue.php` の既定値を 660 にしてある（`.env` の `DB_QUEUE_RETRY_AFTER`）。
- その場で動く処理の時間の上限：PHP の `max_execution_time`（30 秒）は、Linux では PHP 自身が計算している時間だけを数え、外部コマンド（FFmpeg・ImageMagick）・API・DB を待つ時間は数えないため、当たりにくい。先に効くのは nginx の `fastcgi_read_timeout`（初期値 60 秒。文字起こし・要約の API だけ 300 秒。7-2）で、超えるとブラウザに「504 Gateway Time-out」が出る（PHP 側の処理は裏で続いていることがある）。
- ワーカーは 1 つ（`numprocs=1`）から始める。メモリ 2GB のサーバーに、nginx・PHP-FPM・MySQL と同居し、FFmpeg・ImageMagick が PHP-FPM と同時に動くため。導入後は `free -h` でメモリの使い方を見る。

### 10-2. 本番に適用する手順（段階 2）

1. supervisor を入れる（サーバー構築手順書 2-6）。

2. 本番の `.env` に、次の 2 行を書く（`QUEUE_CONNECTION` の行は書き換える）。

```
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=660
```

```bash
cd /var/www/training-record-system-01/src
sudo -u trs01 php artisan config:clear
```

3. supervisor のワーカーの設定を作る。

```bash
sudo nano /etc/supervisor/conf.d/training-record-system-01-worker.conf
```

```ini
[program:training-record-system-01-worker]
command=/usr/bin/php /var/www/training-record-system-01/src/artisan queue:work database --sleep=3 --tries=1 --timeout=600 --max-time=3600
user=trs01
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=610
redirect_stderr=true
stdout_logfile=/var/www/training-record-system-01/src/storage/logs/worker.log
```

- `--tries=1`：ジョブの `$tries`（1）と同じ。失敗したジョブはくり返さない。
- `--timeout=600`：ジョブの時間の上限。`retry_after`（660）より短くする（10-1）。
- `--max-time=3600`：1 時間ごとにワーカーを立ち上げ直す（メモリの増え続けを防ぐ。supervisor が `autorestart` で立ち上げ直す）。
- `stopwaitsecs=610`：止めるとき、動いているジョブ（最大 600 秒）が終わるまで待つ。
- ワーカーは PHP-FPM のプールを通らない（コマンドラインの PHP で動く）ため、第6段階の `open_basedir` などの制限はかからない。実行ユーザーは `trs01` にそろえる。
- ジョブの時間の上限を効かせるには、PHP の `pcntl` が要る。`php -m | grep pcntl` で出ることを確かめる（Ubuntu の PHP の CLI には入っている）。

4. 設定を読み込ませ、動いていることを確かめる。

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

- `training-record-system-01-worker` が `RUNNING` であること。

5. 確認。

- メディアを 1 件登録し（写真の heic か動画の mov が分かりやすい）、登録のモーダルの行が「処理中」のあと「完了」になり、一覧にサムネイルが出ること。
- `jobs` のテーブルが空に戻ること、`failed_jobs` が増えないこと。

```bash
mysql -u trs_user_01 -p training_record_01 -e "SELECT COUNT(*) AS jobs FROM jobs; SELECT COUNT(*) AS failed FROM failed_jobs;"
sudo tail -n 20 storage/logs/worker.log
```

- 文字起こし・要約が、今までどおりその場で動くこと（音声記録一覧から、短い音声で 1 回）。

6. 戻し方（うまくいかなかったとき）。

```bash
# .env の QUEUE_CONNECTION を sync に戻す
cd /var/www/training-record-system-01/src
sudo -u trs01 php artisan config:clear
sudo supervisorctl stop training-record-system-01-worker
```

- sync に戻すと、メディアの変換・サムネイルはまたその場で動く（画面はどちらでも動く）。戻す前に `jobs` に残っていたジョブは動かないので、その記録は「処理中」のまま残る。ワーカーを止める前に `jobs` が空であることを確かめるか、残ったジョブを `sudo -u trs01 php artisan queue:work database --once --tries=1 --timeout=600` で 1 件ずつ流す。

### 10-3. 監視と、失敗したジョブの扱い

- **ワーカーが動いているか**：`sudo supervisorctl status`（`RUNNING` であること）。落ちても supervisor が立ち上げ直す（`autorestart`）。
- **たまっているジョブ**：`SELECT COUNT(*) FROM jobs;` が 0 に戻ること。増え続けていたら、ワーカーが止まっている。
- **失敗したジョブ**：

```bash
cd /var/www/training-record-system-01/src
sudo -u trs01 php artisan queue:failed            # 一覧
sudo -u trs01 php artisan queue:retry <ID>        # もう一度流す（all で全部）
sudo -u trs01 php artisan queue:forget <ID>       # 一覧から消す
```

  - メディアのジョブが失敗すると、記録の状態は「エラー」になる。`queue:retry` で流し直しても、ジョブは状態が「処理中」のときしか動かないため、何もせずに終わる。やり直すときは、そのメディアを削除して登録し直す。
- **`worker.log` のローテーション**：supervisor が書き出すログは Laravel のログと別なので、logrotate の対象にする。

```bash
sudo nano /etc/logrotate.d/training-record-system-01-worker
```

```
/var/www/training-record-system-01/src/storage/logs/worker.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

- `copytruncate`：ワーカーがファイルを開いたまま書き続けるため、写しを取ってから中身を空にする。

### 10-4. 開発の環境

開発の環境でのキューの使い方（ふだんは sync、確かめたいときだけワーカーを動かす）は、`docs/setup/dev-environment-queue.md` を参照。

---

## 第11段階：バッチの cron（Laravel のスケジュール）

定期的に動かすバッチ（アカウントのロック・音声ファイルの削除・DB のバックアップ）は、Laravel のスケジュール（`bootstrap/app.php` の `withSchedule`）にまとめてある。cron は、`schedule:run` を毎分動かす 1 行だけを `trs01` の crontab に登録する。

**2026-10 変更**：以前は「スケジューラ（`schedule:run`）は使わず、コマンドを直接登録する」として、バックアップ（毎日 04:00）の 1 行だけを登録していた。サーバーの移行のときに、cron に直接書いていたバッチのうちバックアップしか移されず、ほかのバッチ（アカウントのロック・音声ファイルの削除）が止まっていたため、スケジュールにまとめる形に変えた。まとめておけば、バッチが増えても cron を触らずに済み、`schedule:list` で全部の時刻を確かめられる。

```bash
sudo crontab -u trs01 -e
```

```
* * * * * cd /var/www/training-record-system-01/src && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

スケジュールに登録しているバッチ（毎日。詳細はバッチ設計書 1 章）：

| 時刻 | ID | バッチ | ログ |
|---|---|---|---|
| 2:00 | B-0201 | 長期間ログインしていないアカウントのロック（`trainers:lock-inactive`） | `storage/logs/laravel*.log` の `[LockInactiveTrainers]` |
| 2:10 | B-0202 | 一度も使われていないアカウントのロック（`trainers:lock-unused`） | 同 `[LockUnusedTrainers]` |
| 2:20 | B-0101 | 音声ファイルの削除（`audio-records:delete-expired`） | 同 `[DeleteExpiredAudioRecords]` |
| 2:30 | B-0301 | DB のバックアップ（`db:backup`） | 同 `[BackupDatabase]`。コンソールへの出力は `storage/logs/cron-backup.log` に追記 |

### 11-1. 既存の環境で cron を書き換える（2026-10）

2026-10 より前に構築した環境では、`trs01` の crontab に `db:backup` を直接動かす行（`0 4 * * * … artisan db:backup …`）がある。この行を消して、上の `schedule:run` の行に**置き換える**。両方を残すと、バックアップが 2:30（スケジュール）と 04:00（cron）の 2 回動く。

```bash
sudo crontab -u trs01 -l      # 今の行を確かめる（db:backup の 1 行のはず）
sudo crontab -u trs01 -e      # db:backup の行を消し、schedule:run の行を書く
sudo crontab -u trs01 -l      # schedule:run の 1 行だけになったことを確かめる
```

### 11-2. 日数の設定

バッチが使う日数は `.env` で設定する。書いていなければ初期値で動く。有効な範囲の外・整数でないときは、そのバッチは何もせず（ロック・削除をせず）、エラーをログに残して終わる。

| 項目 | 意味 | 初期値 | 有効な範囲 |
|---|---|---|---|
| `COUNSELOR_LOCK_INACTIVE_DAYS` | B-0201：最終ログインからの日数 | `30` | 1〜90 |
| `COUNSELOR_LOCK_UNUSED_DAYS` | B-0202：作成からの日数（一度もログインしていないアカウント） | `7` | 1〜90 |
| `AUDIO_RETENTION_DAYS` | B-0101：音声ファイルを残す日数 | `7` | 1〜30 |

`.env` を変えたら、`sudo -u trs01 php artisan config:clear` を実行する。

### 11-3. 確認

```bash
cd /var/www/training-record-system-01/src
sudo -u trs01 php artisan schedule:list
```

- 4 行（`0 2 * * *` `trainers:lock-inactive`、`10 2 * * *` `trainers:lock-unused`、`20 2 * * *` `audio-records:delete-expired`、`30 2 * * *` `db:backup`）が出ること。

翌朝、各バッチのログを確かめる。

```bash
sudo grep -h -E "\[(LockInactiveTrainers|LockUnusedTrainers|DeleteExpiredAudioRecords|BackupDatabase)\]" storage/logs/laravel*.log | tail -n 20
sudo tail -n 20 storage/logs/cron-backup.log
```

- 各バッチの「開始します」と結果（「N件のトレーナーをロックしました。」「音声ファイル自動削除完了」「…をバックアップ用ストレージにアップロードしました」）が、その日の 2:00〜2:30 ごろの時刻で出ていること。
- 「有効な範囲の外のため、何もせずに終了します」が出ていたら、`.env` の値を直して `config:clear` を実行する。

---

## 第12段階：動作確認

- [ ] 2つのドメインが HTTPS で開く（HTTP は HTTPS に転送される）
- [ ] トレーナーがログインできる（`mikan-trs01-staff`）
- [ ] 会員の登録メールが届き、登録・ログインできる（`mikan`）
- [ ] 動画・写真をアップロードできる（オブジェクトストレージへの直接アップロード）
- [ ] メディア変換（heic→jpeg、mov→mp4）が完了する
- [ ] サムネイルが表示される
- [ ] 会員のプロフィール写真をアップロードできる
- [ ] PHP が `trs01` で動いている（画面を開いた後に `ps -eo user,cmd | grep "pool trs01"` で `trs01` のプロセスが表示される）
- [ ] 新しく作られたログのファイル（`storage/logs/`）が `trs01` の所有になっている
- [ ] `sudo -u trs01 php artisan db:backup` を手動で実行し、R2 にバックアップが作られる
- [ ] `sudo -u trs01 php artisan schedule:list` で、4 つのバッチ（2:00・2:10・2:20・2:30）が出る（第11段階）
- [ ] 翌朝、R2 のバケット `trs01-backup-prod` にバックアップが作られている（`storage/logs/cron-backup.log` も確認）

---

## 第13段階：定常のデプロイ（コード更新の反映）

`git pull` と `composer install` は `ubuntu` で、artisan は `trs01` で実行する。

```bash
cd /var/www/training-record-system-01
git pull
cd src
composer install --no-dev --optimize-autoloader   # composer.lock が変わったとき
sudo -u trs01 php artisan migrate --force
sudo -u trs01 php artisan config:clear
sudo -u trs01 php artisan view:clear
sudo -u trs01 php artisan route:clear
sudo systemctl reload php8.4-fpm
sudo -u trs01 php artisan queue:restart
```

- **`queue:restart` を最後に実行する**：キューのワーカー（第10段階）は、立ち上がったときのコードを読んだまま動き続ける。`queue:restart` は、ワーカーに「今のジョブを終えたら止まる」ように知らせ（キャッシュに印を置く）、supervisor が新しいコードで立ち上げ直す。キューを使っていない環境（`QUEUE_CONNECTION=sync`）でも、印を置くだけなので、実行して害はない。

- `npm run build` は不要（`public/build/` はコミット済み）。SCSS などを変更したときは、開発環境でビルドして、ハッシュ付きの CSS と `manifest.json` ごとコミットする。
- `.env` だけを変更したときは `config:clear` を実行する。
- `optimize:clear` は使わない。3つのキャッシュに加えてアプリケーションのキャッシュ（`cache` テーブル）まで消すため、キャッシュに記録されたデータ（ログインの連続失敗の回数など）がリセットされるおそれがある。
- **`sudo systemctl reload php8.4-fpm` を最後に実行する**：OPcache（PHP のコンパイル済みコードのキャッシュ）はファイルの更新をそのままでは読み直さない。更新を検知するかどうかは `opcache.validate_timestamps`（と `revalidate_freq`）の設定しだいで、無効のまま運用しているなら、`composer install` でファイルが入れ替わっても、`config/` を変えても、古いままで動き続ける。`reload` は処理中のリクエストを切らずに、新しいコードで動く状態に PHP-FPM を入れ替える（`restart` と違って、動いているリクエストは最後まで完走させる）。**`composer install` のあと・`config/` を変えたあとは特に必要**。毎回実行してよい（害はない）。
  - **`opcache.validate_permission` と `opcache.validate_root`** はファイルの更新の検知とは別の目的（同じサーバーに同居するアプリが、他のユーザーのファイルのキャッシュを使えないようにする権限・chroot チェック。サーバー構築手順書 2-2 の該当節参照）。これらが `On` でも、`validate_timestamps` が `Off` なら更新は反映されない。

---

## 付録A　本番の未対応事項（2026-09-26 時点）

- [ ] 第10段階 キューワーカーの常駐（`QUEUE_CONNECTION=database` への切り替えと supervisor の導入）：段階 1（メディアの変換・サムネイルを、キューでも sync でも動く形にする）は 2026-10 に済み。本番への適用（段階 2）は 10-2 の手順で行う。文字起こし・要約のキューへの切り替えは、録音実行の流れの作り直しが要るため、別の段階で検討する

---

## 付録B　本番を専用の実行ユーザー・プールに移した手順（2026-09-26 実施）

本番は `www-data`（PHP-FPM の標準のプール）で動いていたが、これを第3段階・第6段階の構成（専用のユーザー `trs01` と専用のプール）に移した。同じ構成のアプリを、あとから専用のプールに移すときの手順としても使える。

実施結果（2026-09-26）：

- B-1〜B-3 を手順どおり実施。サイトが止まったのは、PHP-FPM の再起動の一瞬と、B-2 の数秒だけ。
- B-4 の確認はすべて正常：2つのドメインでのログイン、動画・写真のアップロードと変換、サムネイル、会員のプロフィール写真のアップロード、`sudo -u trs01 php artisan db:backup`（R2 へのアップロードまで成功）、ログが `trs01` の所有で書かれること。
- OPcache の設定（サーバー構築手順書 2-2）も、この移行で適用した。
- 移行の直後、`ubuntu` はログインし直すまで `trs01` グループの権限を持たないため、`storage/` の中を `ls` すると「Permission denied」になる（`sudo ls` なら見られる）。

移行前の状態（2026-09-26 確認）：

- `storage/`・`bootstrap/cache/` の中のファイル・フォルダ（80 個）は、すべて `www-data` の所有
- `.env` は `ubuntu:www-data`・`640`
- nginx の `fastcgi_pass` は、2つの `server` ブロックとも `unix:/run/php/php8.4-fpm.sock`
- cron は `www-data` の crontab の `db:backup`（毎日 04:00）だけ

作業は、利用の少ない時間帯に行う。サイトが止まるのは、B-1 の PHP-FPM の再起動の一瞬と、B-2 の数秒だけ。

### B-1. 準備（サイトは今のまま動き続ける）

1. 実行ユーザーを作る（第3段階）。

```bash
sudo adduser --system --group --no-create-home --home /nonexistent --shell /usr/sbin/nologin trs01
sudo usermod -aG trs01 ubuntu
```

2. 一時ディレクトリを作る（6-1）。

```bash
sudo install -d -o trs01 -g trs01 -m 700 /var/lib/php/trs01-tmp
```

3. サーバー構築手順書 2-2 の OPcache の設定（`99-opcache-multiuser.ini`）が未適用なら、ここで作る（再起動は次の手順でまとめて行う）。

4. プールの設定を作り（6-2）、PHP-FPM を再起動する（6-3）。この時点では、サイトはまだ標準のプールで動いている。

```bash
sudo php-fpm8.4 -t
sudo systemctl restart php8.4-fpm
ls -l /run/php/php8.4-fpm-trs01.sock
sudo php-fpm8.4 -i | grep -E 'opcache.validate_(permission|root)'
```

5. nginx の設定の控えを取っておく（戻すときに使う）。

```bash
sudo cp /etc/nginx/sites-available/training-record-system-01 ~/training-record-system-01.nginx.bak
```

### B-2. 切り替え（数秒止まる）

所有者を変えた時点で、標準のプール（`www-data`）からは `storage/` に書けなくなる。続けてすぐに nginx を切り替える。

```bash
cd /var/www/training-record-system-01/src
sudo chown -R trs01:trs01 storage bootstrap/cache
sudo chmod -R g+rwX,o-rwx storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
sudo chown ubuntu:trs01 .env
sudo sed -i 's#unix:/run/php/php8.4-fpm.sock#unix:/run/php/php8.4-fpm-trs01.sock#' /etc/nginx/sites-available/training-record-system-01
sudo nginx -t && sudo systemctl reload nginx
```

確認：

```bash
grep -n fastcgi_pass /etc/nginx/sites-available/training-record-system-01
ls -la .env storage storage/logs | head -20
```

- `fastcgi_pass` が2か所とも `php8.4-fpm-trs01.sock` になっていること。

### B-3. cron を移し替える

```bash
sudo crontab -u www-data -l
sudo crontab -u trs01 -e
```

`trs01` の crontab に、第11段階の行を登録する。登録できたら、`www-data` の crontab を削除する（`www-data` の crontab にはこの1行しかないことを、上の `-l` で確認してから）。

```bash
sudo crontab -u www-data -r
sudo crontab -u trs01 -l
```

### B-4. 動作確認

第12段階の確認をすべて行う。特に次の点を確認する。

- 2つのドメインでログインできる
- 動画・写真のアップロードと変換、サムネイル、会員のプロフィール写真のアップロード（`open_basedir` と一時ディレクトリの確認）
- `ps -eo user,cmd | grep "pool trs01"` で、`trs01` の PHP のプロセスが動いている
- `sudo -u trs01 php artisan db:backup` を手動で実行し、R2 にバックアップが作られる
- `storage/logs/` に新しく作られたログが `trs01` の所有になっている

`ubuntu` は、ログインし直すと `trs01` グループに入った状態になる（`id ubuntu` で確認）。

### B-5. うまくいかなかったときの戻し方

```bash
cd /var/www/training-record-system-01/src
sudo cp ~/training-record-system-01.nginx.bak /etc/nginx/sites-available/training-record-system-01
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chown ubuntu:www-data .env
sudo nginx -t && sudo systemctl reload nginx
```

cron を移し替えた後なら、`www-data` の crontab に第11段階の行を登録し直し、`sudo crontab -u trs01 -r` で `trs01` の側を削除する。追加したプールとユーザーは、残しておいても害はない。
