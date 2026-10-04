# 開発の環境でのキューの使い方

本番のキュー（`database` ＋ supervisor のワーカー）は、アプリ構築手順書（`sakura-cloud-ubuntu-laravel-app-setup.md`）の第10段階を参照。この文書は、開発の環境（Mac・Windows）でのキューの使い方をまとめる（2026-10）。

## ふだんは sync のまま使う

- 開発の環境の `.env` は、ふだん `QUEUE_CONNECTION=sync` のまま使う（`.env.example` のとおり）。ジョブはその場で動くので、ワーカーは要らない。
- メディアの登録のモーダル（S-1302-M02）は、キューでも sync でも動く作り（変換・サムネイルの API の応答が「処理中」なら、状態を返す API を問い合わせて待つ。sync なら応答の時点で終わっている）。
- 文字起こし・要約は、キューの設定にかかわらず、その場で動く（`dispatchSync`）。

## キューの動きを確かめたいとき

メディアの変換・サムネイルが、キュー（本番と同じ `database`）で後ろで動くことを確かめたいときだけ、次のようにする。

1. `.env` の `QUEUE_CONNECTION` を `database` にする。`jobs`・`failed_jobs` のテーブルは、マイグレーションで作られている。
2. 設定を読み直させる。
3. 別のターミナルで、ワーカー（`queue:listen`）を動かす。`queue:listen` は、ジョブを 1 件流すたびにコードを読み直すので、コードを変えてもワーカーを立ち上げ直さなくてよい。
4. 画面でメディアを登録し、行が「処理中」のあと「完了」に変わることを確かめる。ワーカーを止めたまま登録すると「処理中」のまま待ち、ワーカーを動かすと「完了」に変わる。
5. 確かめ終わったら、`.env` を `QUEUE_CONNECTION=sync` に戻して、設定を読み直させる。ワーカーは Ctrl+C で止める。

### Mac（zsh）

```zsh
cd ~/workspace/dev/training-record-system-01/src
# .env の QUEUE_CONNECTION を database にしてから
php artisan config:clear
# 別のターミナルで
php artisan queue:listen database --tries=1 --timeout=600
```

### Windows（PowerShell 5.1）

```powershell
cd C:\path\to\training-record-system-01\src
# .env の QUEUE_CONNECTION を database にしてから（BOM を付けないエディタで編集する。CLAUDE.md の「BOM」の注意）
php artisan config:clear
# 別のターミナルで
php artisan queue:listen database --tries=1 --timeout=600
```

- コマンドは Mac と同じ。`.env` を PowerShell のコマンド（`Set-Content` など）で書き換えると BOM が付くことがあるので、エディタで編集する。

### `composer dev` について

- `composer.json` の `dev` のスクリプト（`composer run dev`）は、`php artisan serve`・`queue:listen --tries=1 --timeout=0`・`pail`・`npm run dev` をまとめて動かす（Laravel の初期のまま）。
- このプロジェクトの開発サーバーは `php -S`（または Herd）で動かしているため、`composer dev` は使っていない。キューを確かめるときは、上のとおり `queue:listen` を別のターミナルで動かす。
- `composer dev` の `queue:listen` は `--timeout=0`（時間の上限なし）なので、時間切れの動きは確かめられない。

## Windows の PHP の違い（時間の上限）

- **Windows の PHP には `pcntl` がない**ため、ジョブの時間の上限（ジョブの `$timeout`・ワーカーの `--timeout`）が効かない。本番の Linux とは動きが違う。
- 開発で困ることはほとんどないが、時間切れの動き（ワーカーがジョブを止め、記録の状態が「エラー」になる）は、本番（または Mac。`php -m | grep pcntl` で `pcntl` が出る）で確かめる。
- Windows では、PHP の `max_execution_time`（30 秒）に外部コマンド（FFmpeg・ImageMagick）・API の待ち時間も数えられるため、sync で長い変換をすると 30 秒で止まることがある（Linux・Mac では、PHP 自身が計算している時間だけを数えるので、当たりにくい）。
