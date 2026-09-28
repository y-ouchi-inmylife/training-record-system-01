# 開発環境の起動手順および停止手順【Windows 11】

トレーニング記録管理システムの開発環境起動手順（Windows 11）です。

## 前提条件

- Windows 11
- Docker Desktop がインストール済み
- PHP 8.2 以上がインストール済み
- Composer がインストール済み

---

## 起動手順

### 1. Docker Desktop を起動

1. スタートメニューから「Docker Desktop」を起動
2. Docker Desktop が完全に起動するまで待つ（タスクトレイのアイコンが安定するまで）
3. Docker Desktop のダッシュボードが開いたら、左下の「Engine running」が表示されていることを確認

### 2. 最新を取り込む（機械を移った直後・ブランチ切り替え時）

```
cd ~\workspace\dev\training-record-system-01
git pull
cd src

# 新しいマイグレーションがある時のみ
php artisan migrate

# route/view/config キャッシュを破棄
php artisan optimize:clear
```

依存やフロント資産が変わっている場合は追加で：

- composer.json / composer.lock が変わった時 → `composer install`
- resources/ を変更した時 → `npm run build`

同じ機械で続けて作業する場合、pull していなければ本セクションは不要です。

### 3. 作業ディレクトリへ移動

```
cd ~\workspace\dev\training-record-system-01\src
```

### 4. MySQLコンテナを起動

```
# MySQLコンテナを起動（compose 経由）
docker compose up -d

# 起動確認
docker compose ps
```

**確認ポイント**:
- `training-mysql ` が `Up`（または `running`）状態になっているか
- `0.0.0.0:3308->3306/tcp` が表示されているか

**補足**:
- コンテナの設定（コンテナ名・DB名・ポート・文字セットなど）はすべて `compose.yml` が持っているため、再作成が必要な場合も `docker compose up -d` でよい（長い `docker run` コマンドを手で打つ必要はない）。
- 何らかの理由でコンテナを作り直したい場合は `docker compose down`（※ボリュームは残る）→ `docker compose up -d`。データごと消す場合のみ `docker compose down -v`（**DBデータが消えるので注意**）。

### 5. 開発サーバーを起動
```
php -S 127.0.0.1:8081 -t public
```

**表示されるメッセージ**:
```
[Sat Mar 15 12:00:00 2026] PHP 8.4.16 Development Server (http://127.0.0.1:8081) started
```

### 5. ブラウザでアクセス

ブラウザで以下のURLを開く：
```
http://localhost:8081
```

---

## トラブルシューティング

### エラー: "SQLSTATE[HY000] [2002] 対象のコンピューターによって拒否されたため、接続できませんでした"

**原因**: MySQLコンテナが起動していない

**解決方法**:
1. Docker Desktop が起動しているか確認
2. `docker start training-mysql` を実行
3. `docker ps` で起動確認

### エラー: "Address already in use"

**原因**: ポート 8080 が既に使用されている

**解決方法**:
1. 別のポートを使用: `php -S 127.0.0.1:8081 -t public`
2. ブラウザで `http://localhost:8081` を開く

### エラー: "No such container: training-mysql"

**原因**: MySQLコンテナが作成されていない

**解決方法**:
上記の「MySQLコンテナを起動」の「エラーが出た場合」の手順でコンテナを再作成

### エラー: "failed to connect to the docker API"

**原因**: Docker Desktop が起動していない

**解決方法**:
1. Docker Desktop を起動
2. 完全に起動するまで待つ
3. 再度コマンドを実行

---

作成日: 2026-03-15
更新日: 2026-05-22
