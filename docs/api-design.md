**位置づけ**: 仕様文書（API設計書）
**対象読者**: 開発者
**上位文書**: requirements.md（機能一覧、6章）
**詳細**: 詳細は doc-index.md を参照

---

# API設計書: トレーニング記録管理システム

## 1. 認証方式

- Laravelの標準セッション認証（Cookie + CSRFトークン）を使用する。
- JWT（トークンベース認証）は使用しない。ブラウザ経由の利用のみのため、セッション認証で十分。
- トレーナー（`web` ガード）とクライアント（`client` ガード）でセッションを分離する。本番ではサブドメインを分け（トレーナー用 `miraidogwellness-trs01-staff.inmylife1965.com` / クライアント用 `miraidogwellness.inmylife1965.com`）、`SESSION_DOMAIN` を各サブドメイン限定にしたうえで、セッション Cookie 名も役割別に別名化する（トレーナー用 `trs01-staff-session` / クライアント用 `trs01-client-session`）。
- セッション有効期限（時間経過ログアウト）はトレーナー／クライアントで別の固定値とし、ミドルウェアがリクエストのサブドメイン（またはガード）を判定して、Cookie 名と有効期限をセットで動的に切り替える。
- サブドメイン構成・ホスト名管理（`config/subdomain.php`）の詳細は、アーキテクチャ設計書（architecture.md 2-4 サブドメイン構成）を参照。

---

## 2. 共通ミドルウェア

認証後の全エンドポイントに横断的に適用される挙動を記述する。これらは routes/web.php のルート全体に適用され、各エンドポイントの詳細では繰り返さない。リクエストは、エンドポイントの処理に到達する前に、以下のミドルウェアを順に通過する。

### 2-1. CheckIpRestriction（IPアドレス制限）

IP アドレス制限は、**トレーナー用サブドメイン（内部）のルートにのみ適用する**。クライアント用サブドメイン（外部。クライアント本人が自宅・スマートフォン等、機関の許可 IP 外から利用する）には適用しない。これにより、従来の「全体に適用したうえでクライアント閲覧機能を対象外にする」という例外構造が不要になる。

トレーナー用サブドメインにおいて、IP アドレス制限が有効で許可リストが登録されている場合、許可された IP アドレス以外からのアクセスを遮断する（403）。許可リストは完全一致または CIDR 範囲で判定する。IP アドレス制限が無効、または許可リストが空の場合は、すべて許可する。

ただし、トレーナー用サブドメイン内でも次のアクセスは制限の対象外とする。
- ログイン・ログアウト（ログイン画面には到達させ、ログイン後の業務機能で制御する）
- システム管理者
- ローカルホストからのアクセス（開発環境。ローカルホストは常に許可されるため、開発環境では IP 制限による遮断は発生しない）

### 2-2. CheckPasswordChange（強制パスワード変更）

初回ログイン等でパスワード変更が必要な状態のユーザーを、強制パスワード変更画面（S-0102）へ誘導する（警告メッセージ：「初回ログインのため、パスワードを変更してください。」）。パスワード変更画面・ログアウト以外のすべてのエンドポイントが対象。パスワード変更を完了すると、通常どおり利用できる。

### 2-3. LogAccess（操作履歴の記録）

クライアント・トレーニング記録の参照・登録・更新・削除、トレーニー・計測値・契約・利用の登録・更新・削除、愛犬アンケート案内・愛犬性格診断の案内の発行・取消が成功したとき、操作者・操作種別・操作対象・IPアドレス・ユーザーエージェントを、トレーナー操作履歴（access_logs）に記録する。記録される操作種別は、DB設計書の access_logs テーブル定義を参照。

なお、ログイン・ログアウトの記録は、このミドルウェアではなく、それぞれのエンドポイントの処理で行う。

お客様（クライアント）の操作は記録しない（`access_logs.trainer_id` が NOT NULL で、アクセスログはトレーナーの操作の記録のため）。愛犬アンケートの回答（`POST /client-portal/intake-survey/{token}`）も記録せず、回答日時（`trainee_intake_survey_responses.answered_at`）が回答の記録を兼ねる（requirements.md 6-15-17 備考）。愛犬性格診断の回答（`POST /client-portal/personality-diagnosis/{token}`。**2026-10 追加**）も同じく記録しない（回答日時 `trainee_personality_diagnosis_results.answered_at` が記録を兼ねる。requirements.md 6-15-18 備考）。

---


## 3. エンドポイント一覧

**記載範囲**:
- 本章では、本システムが提供する全エンドポイントを列挙する。

| カテゴリ | 対応画面 | メソッド | パス | 概要 | 認証 | 権限 |
|---------|---------|------|------|------|------|-----------|
| 認証 | S-0101 ログイン画面 | GET | `/login` | ログイン画面を表示する | guest | - |
| 認証 | S-0101 ログイン画面 | POST | `/login` | ログインする | guest | - |
| 認証 | S-0102 強制パスワード変更画面 | GET | `/password/change` | パスワード変更画面を表示する | auth | 全員 |
| 認証 | S-0102 強制パスワード変更画面 | POST | `/password/change` | パスワードを変更する | auth | 全員 |
| ダッシュボード | S-0201 ダッシュボード画面 | GET | `/dashboard` | ダッシュボード画面を表示する | auth | 管理者、一般 |
| クライアント管理 | S-0301 クライアント登録画面 | GET | `/clients/create` | クライアント登録画面を表示する | auth | 管理者、一般 |
| クライアント管理 | S-0301 クライアント登録画面 | POST | `/clients` | クライアントを登録する | auth | 管理者、一般 |
| クライアント管理 | S-0304 クライアント一覧画面 | GET | `/clients` | クライアント一覧画面を表示する | auth | 管理者、一般 |
| クライアント管理 | S-0305 クライアント詳細画面 | GET | `/clients/{id}` | クライアント詳細画面を表示する | auth | 管理者、一般 |
| クライアント管理 | S-0305 クライアント詳細画面 | DELETE | `/clients/{id}` | クライアントを削除する | auth | 管理者、一般 |
| クライアント管理 | S-0306 クライアント編集画面 | GET | `/clients/{id}/edit` | クライアント編集画面を表示する | auth | 管理者、一般 |
| クライアント管理 | S-0306 クライアント編集画面 | PUT | `/clients/{id}` | クライアント情報を更新する | auth | 管理者、一般 |
| クライアント管理 | S-0307 メールアドレス登録用 URL 印刷画面 | GET | `/clients/{client}/email-registration-tokens/print` | メールアドレス登録用 URL の印刷用ページを表示する | auth | 管理者、一般 |
| クライアント管理 | S-0305 クライアント詳細画面 | DELETE | `/clients/{client}/email-registration-tokens` | マイページ登録案内を取り消す（未使用のメールアドレス登録用トークンを物理削除する。6-3-7） | auth | 管理者、一般 |
| クライアント管理 | S-0305 クライアント詳細画面 | DELETE | `/clients/{client}/email` | クライアントのメールアドレスとパスワードを削除し、送信済みのメールアドレス登録用 URL・ログイン用リンクを無効化する（6-3-8） | auth | 管理者、一般 |
| トレーニー管理 | S-0308 トレーニー登録画面 | GET | `/clients/{client}/trainees/create` | トレーニー登録画面を表示する（会員は URL パラメータから取得。ルート名 `trainees.create`） | auth | 管理者、一般 |
| トレーニー管理 | S-0308 トレーニー登録画面 | POST | `/clients/{client}/trainees` | トレーニーを登録する（ルート名 `trainees.store`） | auth | 管理者、一般 |
| トレーニー管理 | S-0309 トレーニー詳細画面 | GET | `/trainees/{trainee}` | トレーニー詳細画面を表示する（ルート名 `trainees.show`）。愛犬性格診断の結果（**2026-10 追加**。レーダーチャートの今回・前回・初回と、新しい順の結果の表に使う。**2026-10 変更**：当初は古い順の表）も渡す | auth | 管理者、一般 |
| トレーニー管理 | S-0310 トレーニー編集画面 | GET | `/trainees/{trainee}/edit` | トレーニー編集画面を表示する（ルート名 `trainees.edit`） | auth | 管理者、一般 |
| トレーニー管理 | S-0310 トレーニー編集画面 | PUT | `/trainees/{trainee}` | トレーニーを更新する（ルート名 `trainees.update`） | auth | 管理者、一般 |
| トレーニー管理 | S-0309 トレーニー詳細画面 | DELETE | `/trainees/{trainee}` | トレーニーを削除する（物理削除。CASCADE で計測値も削除される。愛犬アンケート・愛犬性格診断の案内と回答・結果も一緒に削除される。ルート名 `trainees.destroy`。**管理者のみ**、コントローラ内で `auth()->user()->isAdmin()` チェック） | auth | 管理者 |
| トレーニー管理 | S-0309 トレーニー詳細画面 | POST | `/trainees/{trainee}/measurements` | 計測値を登録する（ルート名 `trainee-measurements.store`）。送信元は S-0309 だけ（**2026-10 変更**）。成功後は S-0309 に戻す（下の「計測値エンドポイントのリダイレクト先」参照） | auth | 管理者、一般 |
| トレーニー管理 | S-0309 トレーニー詳細画面 | PUT | `/trainee-measurements/{measurement}` | 計測値を更新する（ルート名 `trainee-measurements.update`）。**編集の送信元は現在 S-0309 のみ**だが、コントローラは将来 S-0305 からも呼ばれる可能性を考えて `return_to` を受け付ける（同上） | auth | 管理者、一般 |
| トレーニー管理 | S-0309 トレーニー詳細画面 | DELETE | `/trainee-measurements/{measurement}` | 計測値を削除する（物理削除。ルート名 `trainee-measurements.destroy`）。**削除の送信元は現在 S-0309 のみ**だが、コントローラは将来 S-0305 からも呼ばれる可能性を考えて `return_to` を受け付ける（同上） | auth | 管理者、一般 |
| トレーニー管理 | S-0305 会員詳細画面 | POST | `/trainees/{trainee}/intake-survey-tokens` | 愛犬アンケート案内を発行する（愛犬アンケート用 URL のトークンを 1 件作成する。ルート名 `trainee-intake-survey-tokens.store`。**2026-10 追加**）。成功後は S-0305 会員詳細画面にリダイレクトし、完了メッセージを出す（有効な案内がすでにあれば発行せず、メッセージも出さずに会員詳細に戻る。**2026-10-17 変更**。下の「愛犬アンケート案内エンドポイントのリダイレクト先」参照） | auth | 管理者、一般 |
| トレーニー管理 | （発行の URL を GET で開いたとき） | GET | `/trainees/{trainee}/intake-survey-tokens` | 発行せずに S-0305 会員詳細画面にリダイレクトする（405 にしない。**2026-10-17 追加**。下の「発行の URL を GET で開いたとき」参照） | auth | 管理者、一般 |
| トレーニー管理 | S-0305 会員詳細画面 | DELETE | `/trainees/{trainee}/intake-survey-tokens` | 愛犬アンケート案内を取り消す（未回答のトークンを物理削除する。ルート名 `trainee-intake-survey-tokens.destroy`。**2026-10 追加**）。リダイレクト先は発行と同じ | auth | 管理者、一般 |
| トレーニー管理 | S-0311 愛犬アンケート案内画面 | GET | `/trainees/{trainee}/intake-survey-tokens/print` | 愛犬アンケート案内画面を表示する（ルート名 `trainee-intake-survey-tokens.print`。**2026-10 追加**） | auth | 管理者、一般 |
| トレーニー管理 | S-0305 会員詳細画面 | POST | `/trainees/{trainee}/personality-diagnosis-tokens` | 愛犬性格診断の案内を発行する（愛犬性格診断用 URL のトークンを 1 件作成する。ルート名 `trainee-personality-diagnosis-tokens.store`。**2026-10 追加**）。成功後は S-0305 会員詳細画面にリダイレクトし、完了メッセージを出す（有効な案内がすでにあれば発行せず、メッセージも出さずに会員詳細に戻る。**2026-10-17 変更**） | auth | 管理者、一般 |
| トレーニー管理 | （発行の URL を GET で開いたとき） | GET | `/trainees/{trainee}/personality-diagnosis-tokens` | 発行せずに S-0305 会員詳細画面にリダイレクトする（405 にしない。**2026-10-17 追加**。下の「発行の URL を GET で開いたとき」参照） | auth | 管理者、一般 |
| トレーニー管理 | S-0305 会員詳細画面 | DELETE | `/trainees/{trainee}/personality-diagnosis-tokens` | 愛犬性格診断の案内を取り消す（未回答のトークンを物理削除する。ルート名 `trainee-personality-diagnosis-tokens.destroy`。**2026-10 追加**）。成功後は S-0305 に戻す | auth | 管理者、一般 |
| トレーニー管理 | S-0312 愛犬性格診断案内画面 | GET | `/trainees/{trainee}/personality-diagnosis-tokens/print` | 愛犬性格診断案内画面を表示する（ルート名 `trainee-personality-diagnosis-tokens.print`。**2026-10 追加**） | auth | 管理者、一般 |
| トレーニー管理 | S-0313 愛犬性格診断詳細画面 | GET | `/trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}` | 愛犬性格診断の結果 1 件の詳細（タイプ・レーダーチャート・点数・50問の回答・評価文）を表示する（ルート名 `trainee-personality-diagnosis-results.show`。**2026-10-18 追加**） | auth | 管理者、一般 |
| トレーニー管理 | S-0313 愛犬性格診断詳細画面 | PUT | `/trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}` | 評価文を保存する（ルート名 `trainee-personality-diagnosis-results.update`。公開済みでも保存できる〔空にはできない〕。**2026-10-18 変更**：その前は公開済みなら 409。**2026-10-18 追加**） | auth | 管理者、一般 |
| トレーニー管理 | S-0313 愛犬性格診断詳細画面 | POST | `/trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}/publish` | 評価文を保存して公開する（公開した日時・トレーナーを入れる。トレーニング記録は作らない。ルート名 `trainee-personality-diagnosis-results.publish`。**2026-10-18 追加**。**2026-10-18 変更**：その前は評価文を本文にしたトレーニング記録を作っていた） | auth | 管理者、一般 |
| トレーニー管理 | S-0313 愛犬性格診断詳細画面 | POST | `/trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}/unpublish` | 評価文の公開取消（下書きに戻す。ルート名 `trainee-personality-diagnosis-results.unpublish`。**2026-10-18 追加**） | auth | 管理者、一般 |
| トレーニー管理 | S-0313 愛犬性格診断詳細画面 | DELETE | `/trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}` | 愛犬性格診断の結果を 1 件削除する（物理削除。公開済みの評価文も一緒に消え、会員様の画面からも消える。ルート名 `trainee-personality-diagnosis-results.destroy`。**管理者のみ**、コントローラ内で `auth()->user()->isAdmin()` チェック。**2026-10 追加**。**2026-10-18 変更**：送信元を S-0309 から S-0313 に移した） | auth | 管理者 |
| 回数管理 | S-0305 会員詳細画面 | POST | `/clients/{client}/contracts` | 契約を登録する（ルート名 `client-contracts.store`）。処理後は S-0305 に戻る（下の注記参照） | auth | 管理者、一般 |
| 回数管理 | S-0305 会員詳細画面 | PUT | `/client-contracts/{contract}` | 契約を更新する（ルート名 `client-contracts.update`）。回数を、その契約に割り当て済みの利用の件数より少なくする更新は入力エラー | auth | 管理者、一般 |
| 回数管理 | S-0305 会員詳細画面 | DELETE | `/client-contracts/{contract}` | 契約を削除する（物理削除。ルート名 `client-contracts.destroy`）。割り当てられていた利用は「契約なし」になる（DB の ON DELETE SET NULL。利用そのものは消えない） | auth | 管理者、一般 |
| 回数管理 | S-0305 会員詳細画面 | POST | `/clients/{client}/usages` | 利用を登録する（ルート名 `client-usages.store`）。会員の残り回数がマイナスになる登録も受け付けるが、残りのない契約（`client_contract_id`）への割り当ては入力エラー（S-0305 に戻り、モーダルを開き直す） | auth | 管理者、一般 |
| 回数管理 | S-0305 会員詳細画面 | PUT | `/client-usages/{usage}` | 利用を更新する（ルート名 `client-usages.update`）。会員の残り回数がマイナスになる更新も受け付けるが、残りのない契約への割り当て（その利用自身は除いて数える）は入力エラー | auth | 管理者、一般 |
| 回数管理 | S-0305 会員詳細画面 | DELETE | `/client-usages/{usage}` | 利用を削除する（物理削除。ルート名 `client-usages.destroy`） | auth | 管理者、一般 |
| トレーニング記録管理 | S-0401 トレーニング記録登録画面 | GET | `/training-records/create` | トレーニング記録登録画面を表示する | auth | 管理者、一般 |
| トレーニング記録管理 | S-0401 トレーニング記録登録画面 | POST | `/training-records` | トレーニング記録を新規登録する | auth | 管理者、一般 |
| トレーニング記録管理 | S-0402 トレーニング記録一覧画面 | GET | `/training-records` | トレーニング記録一覧画面を表示する | auth | 管理者、一般 |
| トレーニング記録管理 | S-0403 トレーニング記録詳細画面 | GET | `/training-records/{id}` | トレーニング記録詳細画面を表示する | auth | 管理者、一般 |
| トレーニング記録管理 | S-0403 トレーニング記録詳細画面 | DELETE | `/training-records/{id}` | トレーニング記録を削除する | auth | 管理者、一般 |
| トレーニング記録管理 | S-0404 トレーニング記録編集画面 | GET | `/training-records/{id}/edit` | トレーニング記録編集画面を表示する | auth | 管理者、一般 |
| トレーニング記録管理 | S-0404 トレーニング記録編集画面 | PUT | `/training-records/{id}` | トレーニング記録を更新する | auth | 管理者、一般 |
| 音声記録管理 | S-0501 録音準備画面 | GET | `/recording-v2` | 録音準備画面を表示する | auth | 管理者、一般 |
| 音声記録管理 | S-0501 録音準備画面 | POST | `/recording-v2/start` | 録音実行画面に遷移する | auth | 管理者、一般 |
| 音声記録管理 | S-0502 録音実行画面 | GET | `/recording-v2/session` | 録音実行画面を表示する | auth | 管理者、一般 |
| 音声記録管理 | S-0502 録音実行画面 | POST | `/audio-records/recording` | 録音した音声をアップロードして音声記録を作成する | auth | 管理者、一般 |
| 音声記録管理 | S-0503 音声記録登録（文字起こしテキスト）画面 | GET | `/audio-records/text-paste/create` | 音声記録登録（文字起こしテキスト）画面を表示する | auth | 管理者、一般 |
| 音声記録管理 | S-0503 音声記録登録（文字起こしテキスト）画面 | POST | `/audio-records/text-paste` | 文字起こしテキストから音声記録を登録する | auth | 管理者、一般 |
| 音声記録管理 | S-0504 音声記録登録（音声ファイルのアップロード）画面 | GET | `/audio-records/upload/create` | 音声記録登録（音声ファイルのアップロード）画面を表示する | auth | 管理者、一般 |
| 音声記録管理 | S-0504 音声記録登録（音声ファイルのアップロード）画面 | POST | `/audio-records/upload` | 音声ファイルをアップロードして音声記録を登録する | auth | 管理者、一般 |
| 音声記録管理 | S-0505 音声記録一覧画面 | GET | `/audio-records` | 音声記録一覧画面を表示する | auth | 管理者、一般 |
| 音声記録管理 | S-0505 音声記録一覧画面 | GET | `/audio-records/{id}` | 音声記録の詳細を取得する | auth | 管理者、一般 |
| 音声記録管理 | S-0505 音声記録一覧画面 | PUT | `/audio-records/{id}` | 音声記録のタイトル・文字起こしテキスト・要約テキストを編集する | auth | 管理者、一般 |
| 音声記録管理 | S-0505 音声記録一覧画面 | DELETE | `/audio-records/{id}` | 音声記録を削除する | auth | 管理者、一般 |
| 音声記録管理 | S-0505 音声記録一覧画面 | DELETE | `/audio-records/{id}/delete-audio` | 音声記録の音声ファイルのみ削除する（文字起こし・要約は保持） | auth | 管理者、一般 |
| 音声記録管理 | S-0505 音声記録一覧画面 | GET | `/audio-records/{id}/play` | 音声ファイルを再生する（ストリーミング） | auth | 管理者、一般 |
| 要約 | S-0601 要約プロンプト画面 | GET | `/settings/summary-prompts` | 要約プロンプト画面を表示する | auth | 管理者 |
| 要約 | S-0601 要約プロンプト画面 | PUT | `/settings/summary-prompts` | 要約プロンプトを更新する | auth | 管理者 |
| 音声ファイル容量管理 | S-0701 音声ファイル一覧画面 | GET | `/usage-stats` | 音声ファイル一覧画面を表示する（サーバー容量管理用） | auth | システム管理者 |
| トレーナー管理 | S-0801 トレーナー登録画面 | GET | `/trainers/create` | トレーナー登録画面を表示する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0801 トレーナー登録画面 | POST | `/trainers` | トレーナーを登録する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0802 トレーナー管理画面 | GET | `/trainers` | トレーナー管理画面を表示する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0802 トレーナー管理画面 | PATCH | `/trainers/{id}/move-up` | トレーナーの表示順を上に移動する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0802 トレーナー管理画面 | PATCH | `/trainers/{id}/move-down` | トレーナーの表示順を下に移動する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0802 トレーナー管理画面 | PATCH | `/trainers/{id}/unlock` | アカウントロックを解除する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0802 トレーナー管理画面 | PATCH | `/trainers/{id}/toggle-active` | アカウントの有効/無効を切り替える | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0802 トレーナー管理画面 | DELETE | `/trainers/{id}` | トレーナーを削除する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0803 トレーナー編集画面 | GET | `/trainers/{id}/edit` | トレーナー編集画面を表示する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0803 トレーナー編集画面 | PUT | `/trainers/{id}` | トレーナーを更新する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0804 パスワードリセット画面 | GET | `/trainers/{id}/reset-password` | パスワードリセット画面を表示する | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0804 パスワードリセット画面 | PUT | `/trainers/{id}/reset-password` | パスワードをリセットする | auth | 管理者、システム管理者 |
| トレーナー管理 | S-0805 トレーナー操作履歴画面 | GET | `/access-logs` | トレーナー操作履歴画面を表示する | auth | 管理者 |
| セキュリティ設定 | S-1002 IPアドレス制限画面 | GET | `/settings/ip-restriction` | IPアドレス制限画面を表示する | auth | システム管理者 |
| セキュリティ設定 | S-1002 IPアドレス制限画面 | PUT | `/settings/ip-restriction` | IPアドレス制限設定を更新する | auth | システム管理者 |
| レポート | S-1101 トレーニング記録数推移画面 | GET | `/statistics/clients` | トレーニング記録数推移画面を表示する | auth | 管理者、一般 |
| マイプロフィール | S-1201 マイプロフィール画面 | GET | `/profile` | マイプロフィール画面を表示する | auth | 全員 |
| マイプロフィール | S-1201 マイプロフィール画面 | PUT | `/profile` | プロフィールを更新する | auth | 全員 |
| マイプロフィール | S-1202 パスワード変更画面 | GET | `/profile/password` | パスワード変更画面を表示する | auth | 全員 |
| マイプロフィール | S-1202 パスワード変更画面 | PUT | `/profile/password` | パスワードを変更する | auth | 全員 |
| メディア管理 | S-1302 メディア一覧画面 | GET | `/media-records` | メディア一覧画面を表示する | auth | 管理者、一般 |
| メディア管理 | S-1302 メディア一覧画面 | GET | `/media-records/{id}` | メディアの詳細を取得する | auth | 管理者、一般 |
| メディア管理 | S-1302 メディア一覧画面 | PUT | `/media-records/{id}` | メディアの表示名を更新する | auth | 管理者、一般 |
| メディア管理 | S-1302 メディア一覧画面 | DELETE | `/media-records/{id}` | メディアを削除する（レコード・ファイル実体） | auth | 管理者、一般 |
| 共通 | ログアウト | POST | `/logout` | ログアウトする | auth | 全員 |
| クライアント閲覧 | S-1401 クライアントログイン画面 | GET | `/client-portal/login` | クライアントログイン画面を表示する | guest:client | - |
| クライアント閲覧 | S-1401 クライアントログイン画面 | POST | `/client-portal/login` | クライアントとしてログインする | guest:client | - |
| クライアント閲覧 | - | POST | `/client-portal/logout` | クライアントとしてログアウトする | auth:client | クライアント |
| クライアント閲覧 | S-1402 クライアントダッシュボード画面 | GET | `/client-portal/dashboard` | クライアントダッシュボード画面を表示する | auth:client | クライアント |
| クライアント閲覧 | S-1402 クライアントダッシュボード画面 | POST | `/client-portal/trainees/{trainee}/photo` | トレーニー写真をアップロードする（サーバ経由の multipart POST。サーバ側で長辺 400px にリサイズ・JPEG 変換して保存） | auth:client | クライアント |
| クライアント閲覧 | S-1402 クライアントダッシュボード画面 | DELETE | `/client-portal/trainees/{trainee}/photo` | トレーニー写真を削除する（DB の `photo_path` を NULL に戻し、実ファイルもストレージから削除） | auth:client | クライアント |
| クライアント閲覧 | S-1403 クライアントマイページ登録画面 | GET | `/client-portal/setup/{token}` | マイページ登録画面を表示する（トークン検証、自動ログイン） | public | - |
| クライアント閲覧 | S-1403 クライアントマイページ登録画面 | POST | `/client-portal/setup/{token}` | マイページ登録（パスワード＋基本情報）を保存する | public | - |
| クライアント閲覧 | S-1404 クライアントトレーニング記録詳細画面 | GET | `/client-portal/training-records/{id}` | クライアントが自分のトレーニング記録の詳細を表示する | auth:client | クライアント |
| クライアント閲覧 | S-1404 クライアントトレーニング記録詳細画面 | GET | `/client-portal/media/{id}/play` | クライアントが自分の記録に紐づくメディアを表示・再生する | auth:client | クライアント |
| クライアント閲覧 | S-1405 クライアントメールアドレス登録画面 | GET | `/client-portal/email-registration/{token}` | メールアドレス登録画面を表示する（トークン検証） | public | - |
| クライアント閲覧 | S-1405 クライアントメールアドレス登録画面 | POST | `/client-portal/email-registration/{token}` | メールアドレスを登録し、ログイン用リンクを送信する | public | - |
| クライアント閲覧 | S-1406 クライアント登録情報画面 | GET | `/client-portal/profile` | 登録情報の確認画面を表示する（登録内容の一覧＋各変更画面への入口ボタン） | auth:client | クライアント |
| クライアント閲覧 | S-1411 クライアント登録情報の変更画面 | GET | `/client-portal/profile/edit` | 登録情報の変更フォームを表示する | auth:client | クライアント |
| クライアント閲覧 | S-1411 クライアント登録情報の変更画面 | PUT | `/client-portal/profile` | 連絡先（電話番号・住所）を更新する | auth:client | クライアント |
| クライアント閲覧 | S-1417 クライアントの愛犬の情報の変更画面 | GET | `/client-portal/trainees/{trainee}/edit` | 愛犬の情報の変更フォームを表示する（ルート名 `client-portal.trainees.edit`。自分の愛犬だけ。**2026-10-18 追加**） | auth:client | クライアント |
| クライアント閲覧 | S-1418 クライアントの愛犬性格診断の評価画面 | GET | `/client-portal/personality-diagnosis-results/{personalityDiagnosisResult}` | 公開済みの愛犬性格診断の評価文を表示する（ルート名 `client-portal.personality-diagnosis-results.show`。自分のトレーニーの公開済みだけ。**2026-10-18 追加**） | auth:client | クライアント |
| クライアント閲覧 | S-1417 クライアントの愛犬の情報の変更画面 | PUT | `/client-portal/trainees/{trainee}` | 愛犬の基本情報（名前・犬種・性別・誕生日・去勢・避妊）を更新する（ルート名 `client-portal.trainees.update`。自分の愛犬だけ。**2026-10-18 追加**） | auth:client | クライアント |
| クライアント閲覧 | S-1409 クライアントメールアドレス変更画面 | GET | `/client-portal/settings/email` | メールアドレス変更画面を表示する | auth:client | クライアント |
| クライアント閲覧 | S-1409 クライアントメールアドレス変更画面 | POST | `/client-portal/settings/email` | メールアドレス変更を申し込み、新しいアドレス宛にメールアドレス確認リンクを送信する | auth:client | クライアント |
| クライアント閲覧 | S-1410 クライアントパスワード変更画面 | GET | `/client-portal/settings/password` | パスワード変更画面を表示する | auth:client | クライアント |
| クライアント閲覧 | S-1410 クライアントパスワード変更画面 | PUT | `/client-portal/settings/password` | パスワードを変更する | auth:client | クライアント |
| クライアント閲覧 | (S-1409 派生・専用画面なし) | GET | `/client-portal/email-change/{token}` | メールアドレス確認リンクを開き、メールアドレスを切り替えてログアウトする（無効時のみ無効トークン画面を表示） | public | - |
| クライアント閲覧 | S-1407 クライアントパスワード再設定申し込み画面 | GET | `/client-portal/password-reset` | パスワード再設定申し込み画面を表示する | public | - |
| クライアント閲覧 | S-1407 クライアントパスワード再設定申し込み画面 | POST | `/client-portal/password-reset` | メールアドレスを受け取り、該当が「利用中」なら再設定リンクを送信する（該当しない場合も同じ画面・同じ文言を返す） | public | - |
| クライアント閲覧 | S-1408 クライアントパスワード再設定画面 | GET | `/client-portal/password-reset/{token}` | パスワード再設定画面を表示する（トークン検証） | public | - |
| クライアント閲覧 | S-1408 クライアントパスワード再設定画面 | POST | `/client-portal/password-reset/{token}` | 新しいパスワードを保存し、ログイン画面へ遷移する | public | - |
| クライアント閲覧 | S-1414 愛犬アンケート回答完了画面 | GET | `/client-portal/intake-survey/thanks` | 愛犬アンケートの回答完了画面を表示する（トークンを持たない固定の画面。ルート名 `client-portal.intake-survey.thanks`。**2026-10 追加**） | public | - |
| クライアント閲覧 | S-1413 愛犬アンケート回答画面 | GET | `/client-portal/intake-survey/{token}` | 愛犬アンケート回答画面を表示する（トークン検証。ルート名 `client-portal.intake-survey.show`。**2026-10 追加**） | public | - |
| クライアント閲覧 | S-1413 愛犬アンケート回答画面 | POST | `/client-portal/intake-survey/{token}` | 愛犬アンケートの回答を保存し、トレーニーの空欄の項目に反映する（ルート名 `client-portal.intake-survey.store`。**2026-10 追加**） | public | - |
| クライアント閲覧 | S-1416 愛犬性格診断回答完了画面 | GET | `/client-portal/personality-diagnosis/thanks` | 愛犬性格診断の回答完了画面を表示する（トークンを受け取らない固定の画面。ルート名 `client-portal.personality-diagnosis.thanks`。**2026-10 追加**） | public | - |
| クライアント閲覧 | S-1415 愛犬性格診断回答画面 | GET | `/client-portal/personality-diagnosis/{token}` | 愛犬性格診断回答画面を表示する（トークン検証。ルート名 `client-portal.personality-diagnosis.show`。**2026-10 追加**） | public | - |
| クライアント閲覧 | S-1415 愛犬性格診断回答画面 | POST | `/client-portal/personality-diagnosis/{token}` | 愛犬性格診断の回答を採点して結果を保存する（ルート名 `client-portal.personality-diagnosis.store`。**2026-10 追加**） | public | - |
| クライアント管理 | S-0305 クライアント詳細画面 | POST | `/clients/{client}/email-registration-tokens` | マイページ登録案内を発行する（未発行時の発行と期限切れからの発行し直しを同じエンドポイントで扱う。有効な案内がすでにあれば発行せずに会員詳細に戻る。成功後は S-0305 会員詳細画面に戻り、完了メッセージを出す〔**2026-10-17 変更**〕。トークン自体は「メールアドレス登録用 URL」で、URL パスもそのまま） | auth | 管理者、一般 |
| クライアント管理 | （発行の URL を GET で開いたとき） | GET | `/clients/{client}/email-registration-tokens` | 発行せずに S-0305 会員詳細画面にリダイレクトする（405 にしない。**2026-10-17 追加**。下の「発行の URL を GET で開いたとき」参照） | auth | 管理者、一般 |
| 内部API | - | GET | `/api/clients/search` | クライアントを検索する | auth | 管理者、一般 |
| 内部API | - | POST | `/api/training-records/auto-create` | 音声記録の要約からトレーニング記録を作成する（**2026-10**：画面からは呼ばれなくなった。録音実行は `/api/audio-records/{id}/auto-create-training-record` を使う） | auth | 管理者、一般 |
| 内部API | - | GET | `/api/training-records/available-media` | トレーニング記録に紐づけ可能なメディア一覧を取得する | auth | 管理者、一般 |
| 内部API | - | POST | `/api/audio-records/{id}/transcribe` | 文字起こしを実行する | auth | 管理者、一般 |
| 内部API | - | POST | `/api/audio-records/{id}/summarize` | 要約を実行する | auth | 管理者、一般 |
| 内部API | - | GET | `/api/audio-records/{id}/status` | 文字起こし・要約の状態を返す（**2026-10 追加**） | auth | 管理者、一般 |
| 内部API | - | POST | `/api/audio-records/{id}/auto-create-training-record` | 文字起こし → 要約 → トレーニング記録の作成をひとつながりで始める（録音実行の「作成する」。**2026-10 追加**） | auth | 管理者、一般 |
| 内部API | - | GET | `/api/audio-records/{id}/summary` | 音声記録の要約テキストを取得する | auth | 管理者、一般 |
| 内部API | - | GET | `/api/audio-records/summaries` | 要約取り込み候補の音声記録一覧を取得する | auth | 管理者、一般 |
| 内部API | - | GET | `/api/trainers` | 担当トレーナーの選択候補を取得する | auth | 管理者、一般 |
| 内部API | - | POST | `/api/media-records/upload-url` | アップロード用の署名付きURLを発行する | auth | 管理者、一般 |
| 内部API | - | POST | `/api/media-records` | アップロード完了後、メディアレコードを作成する | auth | 管理者、一般 |
| 内部API | - | GET | `/api/media-records/{id}/play` | メディアを表示・再生する（ストリーミング） | auth | 管理者、一般 |
| 内部API | - | POST | `/api/media-records/{id}/convert` | 表示用変換（heic→jpeg / mov→mp4）を起動する | auth | 管理者、一般 |
| 内部API | - | POST | `/api/media-records/{id}/generate-thumbnail` | サムネイル生成を起動する | auth | 管理者、一般 |
| 内部API | - | GET | `/api/media-records/{id}/status` | 表示用変換・サムネイル生成の状態を返す（**2026-10 追加**） | auth | 管理者、一般 |

※ 認証列は実装のミドルウェア区分を示す。`public`＝認証不要（誰でもアクセス可）、`guest`＝未認証ユーザー向け（ログイン済みはホーム画面へリダイレクト）、`auth`＝要認証（ログイン済みのトレーナー）。（クライアント閲覧機能では guard を明示し、`guest:client`＝未認証のクライアント向け（ログイン済みはクライアントダッシュボードへリダイレクト）、`auth:client`＝要認証（ログイン済みのクライアント）を用いる。）
※ 権限列は認証後のロール制限を示す。`-`＝認証不要のため対象外、`全員`＝ログイン済みの全トレーナー（システム管理者を含む）、`管理者、一般`＝システム管理者を除く実務トレーナー、`管理者`＝管理者のみ、`システム管理者`＝システム管理者のみ、`管理者、システム管理者`＝管理者とシステム管理者のみ（一般トレーナーを除く）。`クライアント`＝ログイン済みのクライアント（飼い主）。

**計測値エンドポイントのリダイレクト先**（**2026-10 変更**）：`POST /trainees/{trainee}/measurements` / `PUT /trainee-measurements/{measurement}` / `DELETE /trainee-measurements/{measurement}` の 3 メソッドは、成功後は**常に S-0309 トレーニー詳細画面**（`redirect()->route('trainees.show', $trainee)`）に戻す。計測値の登録・編集・削除はトレーニー詳細（S-0309）の計測値の欄だけで行うため（requirements.md 6-16-5）、送信元を判別する `return_to` は持たない（送られても使わない）。バリデーションエラー時は Laravel の `back()` で S-0309 に戻り、hidden `_trainee_id` で計測値モーダルを開き直す（screen-design.md S-0309「計測値モーダル」参照）。

**愛犬アンケート案内エンドポイントのリダイレクト先**（**2026-10 変更**）：`DELETE /trainees/{trainee}/intake-survey-tokens`（取消）は、成功後は**常に S-0305 会員詳細画面**（`redirect()->route('clients.show', $trainee->client_id)`）に戻し、完了メッセージはフラッシュ `success`（画面の上端）で出す。`POST /trainees/{trainee}/intake-survey-tokens`（発行）も、成功後は S-0305 会員詳細画面に戻し、完了メッセージをフラッシュ `success` で出す（**2026-10-17 変更**：当初は S-0311 愛犬アンケート案内画面にリダイレクトし、完了メッセージは出さなかった。下の発行のエンドポイント参照）。愛犬アンケート案内の発行・表示・取消を会員詳細だけで行う形にしたため（requirements.md 6-16-8 備考「操作を会員詳細に寄せた理由」）、`return_to` は持たない（送られても使わない）。

**愛犬性格診断の案内エンドポイントのリダイレクト先**（**2026-10 追加**）：愛犬アンケート案内と同じ。`DELETE /trainees/{trainee}/personality-diagnosis-tokens`（取消）は成功後に S-0305 会員詳細画面に戻し、完了メッセージ「愛犬性格診断の案内を取り消しました。」をフラッシュ `success`（画面の上端）で出す。`POST /trainees/{trainee}/personality-diagnosis-tokens`（発行）も成功後に S-0305 会員詳細画面に戻し、完了メッセージ「{表示名}の愛犬性格診断の案内を発行しました。」をフラッシュ `success` で出す（**2026-10-17 変更**：当初は S-0312 愛犬性格診断案内画面にリダイレクトし、完了メッセージは出さなかった）。`return_to` は持たない。

**経緯：当初の `return_to`**（**2026-10 追加**。同じ 2026-10 に、操作を会員詳細に寄せたため廃止。経緯として残す）：当初は発行・取消を S-0305 と S-0309 の両方から行えるようにし、当時の計測値エンドポイントと同じく hidden `return_to`（`client` / `trainee` の 2 値、既定は `trainee`）で送信元を判別し、成功時のリダイレクト先を切り替えていた（当時の要件定義書 6-16-8・6-16-9「操作した画面に戻る」）。S-0309 に戻るときの完了メッセージは、愛犬アンケートの欄の中に出すための別のキー（`intake_survey_success`）で渡していた。

- `return_to=client`：S-0305 会員詳細画面（`redirect()->route('clients.show', $trainee->client_id)`）に戻す
- `return_to=trainee`（**既定**）：S-0309 トレーニー詳細画面の愛犬アンケートの欄（`redirect()->to(route('trainees.show', $trainee) . '#intake-survey')`）に戻す。愛犬アンケートの欄は画面の下のほうにあるため、状態が変わったことが目に入る位置に戻す
- 値の扱い（上記以外・欠落時は既定にする、URL そのものは受け取らない）は当時の計測値エンドポイントと同じ

**契約・利用エンドポイントのリダイレクト先**：`client-contracts.*` / `client-usages.*` の 6 メソッドは、処理後（成功時）は**常に S-0305 会員詳細画面**（`redirect()->route('clients.show', …)`）に戻す。送信元は S-0305 だけのため、`return_to` は持たない。認証・権限は計測値と同じ（`auth` ＋ `practitioners` ミドルウェア。一般トレーナーも削除まで可。管理者限定の操作はない）。バリデーションエラー時は `back()` で S-0305 に戻り、該当モーダルを開き直す（screen-design.md S-0305-M04 / M05 参照）。

---


## 4. エンドポイント詳細

**記載範囲**:
- 本章では、本システムが提供する全エンドポイントを列挙する。
- 全エンドポイントとは、「3. エンドポイント一覧」で挙げているものを指す。
- エンドポイントを、利用者がブラウザで開く画面（routes/web.php）と、画面のJavaScriptがAjaxで呼び出す内部API（routes/api.php）に分けて記述する。

**参照ルール**:
- 各エンドポイントの認証・権限は、「3. エンドポイント一覧」で管理している。本章では同じメソッド・パスを使用しているため、メソッドとパスで突き合わせて参照すること。

**記載する項目**: 各エンドポイントについて、以下の項目を記載する。
- **概要**（必須）: エンドポイントが何をするかを一文で示す。
- **リクエスト**: 受け取るパラメータの一覧。「パラメータ」「型」「必須」「バリデーション」「説明」の5列で記載する。パラメータがない場合は記載しない。
- **処理**: エンドポイント内の挙動のうち、特記すべきものを記述する。単純な表示など特記事項がない場合は記載しない。
- **レスポンス**（必須）: 返す内容（画面の描画、リダイレクト、JSON）を記載する。

**必須欄の記号**: 「リクエスト」表の「必須」列では、以下の記号を用いる。

| 記号 | 意味 |
|---|---|
| ● | 必須 |
| ▲ | 条件付き必須（条件は表の下か説明列に書く。**2026-10 追加**） |
| 空欄 | 任意 |

**バリデーションの補足**: 「リクエスト」表の「バリデーション」列は、Laravelのバリデーションルールを記載する。標準ルール以外のカスタムルールを以下に補足する。

- **StrongPassword**: パスワードの強度をチェックするカスタムルール。8文字以上で、大文字・小文字・数字・記号をそれぞれ1文字以上含み、よくあるパスワード（password 等）を禁止する。


### 4-1. 画面（routes/web.php）

利用者がブラウザで直接開くエンドポイント。`routes/web.php` に定義する。
レスポンスはBladeテンプレートで描画したHTML（画面遷移）。
Laravelのセッション認証（Cookie + CSRF）で保護する。


#### 4-1-1. 認証

##### S-0101 ログイン画面

###### GET /login

**概要**: ログイン画面を表示する。

**レスポンス**:
- 未ログインの場合：view `auth.login`
- ログイン済みの場合：権限に応じたホーム画面へリダイレクト（システム管理者：音声ファイル一覧画面、それ以外：ダッシュボード画面）


###### POST /login

**概要**: ログインする。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| login_id | string | ● | required, string, max:50 | ログインID |
| password | string | ● | required, string | パスワード |

**処理**:
- ログインに連続して5回失敗すると、アカウントがロックされる
- ログイン成功時、ログインを操作履歴に記録する

**レスポンス**:
- 成功：権限に応じたホーム画面へリダイレクト（システム管理者：音声ファイル一覧画面、それ以外：ダッシュボード画面）
- 認証失敗・無効化・ロックは、入力項目ではなく専用のキー `login` で `withErrors(['login' => '…'])` で返す（画面設計書 §2-7「ログインの認証の失敗・アカウントの無効化・ロックの出し方」の共通ルール）：
  - 認証失敗：「ログインIDまたはパスワードが正しくありません。」
  - 無効化されたアカウント：「このアカウントは無効化されています。管理者にお問い合わせください。」
  - ロックされたアカウント：「アカウントがロックされています。管理者に連絡してください。」

---

##### S-0102 強制パスワード変更画面

###### GET /password/change

**概要**: パスワード変更画面を表示する。

**レスポンス**:
- view `auth.change-password`


###### POST /password/change

**概要**: パスワードを変更する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| new_password | string | ● | required, string, confirmed, StrongPassword | 新しいパスワード |
| new_password_confirmation | string | ● | — | 新しいパスワード（確認） |

**処理**:
- パスワードを更新し、初回ログイン時のパスワード変更を済みにする

**レスポンス**:
- 成功：権限に応じたホーム画面へリダイレクト（システム管理者：音声ファイル一覧画面、それ以外：ダッシュボード画面）

---


#### 4-1-2. ダッシュボード

##### S-0201 ダッシュボード画面

###### GET /dashboard

**概要**: ダッシュボード画面を表示する。

**クエリパラメータ**:

| パラメータ | 型 | 必須 | 説明 |
|-----------|----|------|------|
| `recent` | string |  | 「最近のトレーニング記録」ブロックの絞り込み。`all`（既定）または `primary`（**会員の主担当**がログイン中のトレーナーである記録に絞る。担当1・担当2 では絞らない — 詳細は screen-design.md S-0201 設計方針参照）。**不正な値は `all` として扱う** |

**処理**:
- **最近のトレーニング記録**を取得（**2026-09 追加**、従来の「主担当のクライアント一覧の取得」処理は本画面から削除。詳細は screen-design.md S-0201 設計方針「『主担当の会員一覧』ブロックを削除」参照）
  - 期間：`training_date` が今日の 6 日前〜今日（今日を含む 7 日間、未来日は含めない）
  - 並び順：トレーニング日の新しい順（同日内は S-0402 と同じ既定に合わせる）
  - `recent=primary` のときは `whereHas('client', fn ($q) => $q->where('primary_trainer_id', auth()->id()))` で絞る
  - ページングなし。`with(['client.trainees', 'trainer1', 'trainer2'])` で eager load、さらに `withCount('mediaRecords')` でメディア件数を集計（S-0402 と同じ構え）

**レスポンス**:
- view `dashboard`

---


#### 4-1-3. クライアント管理

##### S-0301 クライアント登録画面

###### GET /clients/create

**概要**: クライアント登録画面を表示する。

**リクエスト**:
なし

**レスポンス**:
- view `clients.create`


###### POST /clients

**概要**: クライアントを登録する。

**リクエスト**:
クライアント情報の全項目（詳細はDB設計書の clients テーブル定義を参照）。
以下に主要項目を記載。

| パラメータ | 型 | 必須 | バリデーション | 備考 |
|-----------|-----|------|---------------|------|
| internal_id | string | | — | サーバー側で採番（既存の最大値+1）。クライアントからは送信しない |
| initial_consultation_date | date | ● | required, date | |
| last_name | string | ● | nullable, string, max:50 | |
| *_kana | string | | nullable, string, max:50, regex:/^[\p{Hiragana}\s　]+$/u | |
| phone* | string | | nullable, string, max:20, regex:/^[0-9\-]+$/ | phone2 は緊急連絡先（ご家族等）（**2026-10-17 変更**：その前は電話番号（予備）） |
| emergency_contact_relationship | string | | nullable, string, max:20 | 続柄（**2026-10-17 追加**） |
| email | string | | nullable, email, max:255 | |
| primary_trainer_id | integer | | nullable, exists:trainers,id | |

**処理**（**2026-10-18 追加**。requirements.md 6-3-1）:
- 1 つのトランザクションで、内部 ID を採番して会員を保存し、**その会員のトレーニーを 1 件作る**（`trainees`。`client_id` と `updated_by` だけを入れ、ほかの項目は空）。会員の保存に失敗したらトレーニーも作られない（入力エラーはトランザクションの前に止まる）
- 操作の記録：`create_client`（LogAccess ミドルウェア）と、`create_trainee`（`target_type='Trainee'`、`target_id` は作ったトレーニーの id。コントローラで残す。ミドルウェアはルートの 1 件しか残せないため）
- 内部 ID の採番は `app(ClientInternalIdService::class)` から呼ぶ（テストで MySQL だけの採番の SQL を差し替えられるように）
- 理由：2026-10-18 のユーザーレビューで、会員を登録したあとはほとんどすぐトレーニーを追加し、愛犬アンケート・愛犬性格診断の案内もトレーニーごとに発行するため、会員を登録したらすぐ案内を出せるようにした

**レスポンス**:
- 成功：`redirect('/clients/{id}')` ＋「会員を登録しました。」（今までどおり）
- 失敗：`back()` ＋ バリデーションエラーメッセージ

---

##### S-0304 クライアント一覧画面

###### GET /clients

**概要**: クライアント一覧画面を表示する。

**クエリパラメータ**（すべて任意）:

| パラメータ | 型 | 説明 |
|-----------|-----|------|
| internal_id | string | 内部ID（部分一致） |
| keyword | string | 氏名・かなの複合検索（クライアントの姓名およびそのかな、計4項目を部分一致でOR検索） |
| primary_trainer_id | integer | 主担当トレーナーID |
| date_from | date | 最終トレーニング日（開始日）。呼び方は screen-design.md §2-6 参照 |
| date_to | date | 最終トレーニング日（終了日） |
| sort | string | ソートカラム（internal_id, last_name, initial_consultation_date）。未指定時・不正値は initial_consultation_date。internal_idは数値順、last_nameは画面表示上の氏名で五十音順。第2キーとして id を第1キーと同じ方向で付け、同値時の並びを固定する |
| direction | string | ソート方向（asc, desc）。asc以外はdesc（未指定時は desc）。**sort が未指定・不正な値のときは、direction の指定にかかわらず desc**（既定の初回日の新しい順に戻す） |
| page | integer | ページ番号 |

**処理**:
- 検索条件・並び替え・ページネーションに沿ってクライアントを取得する
- 各行に表示する**状態**（メールアドレスなし／メールアドレス登録待ち／初回設定待ち／利用中）に必要な値を、**行ごとに個別のクエリを発行しない形で**まとめて取得する（N+1 を避ける）。具体的な取得方法（サブクエリ、JOIN、リレーションの事前読み込み等）は実装時に決めるが、方針として「1 ページの描画で `client_email_registration_tokens` へのクエリが行数分発行されない」ことを満たすこと

**レスポンス**:
- view `clients.index`（各行に**クライアントの状態**〔メールアドレスなし／メールアドレス登録待ち／初回設定待ち／利用中〕を表示。状態は clients.email・clients.password と、対応する `client_email_registration_tokens` の有無から導出する。**期限判定の参照元は `client_email_registration_tokens.expires_at`**（ログイン用リンクの `expires_at` は参照しない）。判定順序と具体的なクエリ例は screen-design.md の S-0305 状態一覧を参照。**状態列は並び替え・絞り込みの対象にしない**）
- 各行に**残り回数**（契約回数の合計 − 利用の件数。契約も利用も 0 件なら空欄）を表示する。状態と同じく、行ごとに個別のクエリを発行しない形で取得する（N+1 を避ける）。**残り回数列は並び替え・絞り込みの対象にしない**（screen-design.md S-0304 参照）

---

##### S-0305 クライアント詳細画面

###### GET /clients/{id}

**概要**: クライアント詳細画面を表示する。

**レスポンス**:
- view `clients.show`（クライアント情報、トレーニング記録一覧。トレーニング記録は新しい順。**各記録のメディア件数を先読みする**（`withCount('mediaRecords')`。派生カラム `media_records_count` を各記録に付与し、タイムラインの担当行の次の行に「メディア数 N」を表示する。0 件時は行ごと非表示。Blade で N+1 を発生させないため）。**クライアントの状態**〔メールアドレスなし／メールアドレス登録待ち／初回設定待ち／利用中〕はヘッダーに強調バッジで表示する。導出は clients.email・clients.password・対応する `client_email_registration_tokens` の有無から。**期限判定の参照元は `client_email_registration_tokens.expires_at`**（ログイン用リンクの `expires_at` は参照しない）。判定順序と具体的なクエリ例は screen-design.md の S-0305 状態一覧を参照。**状態別ボタンは基本 1 つだけ**（メールアドレスなし・期限切れなら「マイページ登録の案内を発行」、利用中なら「メールアドレスを削除」、初回設定待ちは無し）だが、**「メールアドレス登録待ち（期限内）」のときだけ「マイページ登録の案内を表示」と「取消」の 2 つが並ぶ**（表示と取消は性質の違う操作で、どちらも必要なため）。URL 文字列・QR コード・有効期限は詳細画面には出さず、印刷ページ〔S-0307〕に集約する）
- **公開済みの愛犬性格診断の評価文を混ぜる**（**2026-10-18 追加**。requirements.md 6-3-3・6-16-16）：会員のトレーニーの結果のうち `evaluation_published_at` が NULL でないものを、トレーニーと公開したトレーナー（`evaluation_published_by`）と一緒に読み込み（eager load。件数でクエリが増えない）、トレーニング記録と 1 本の並び（日付・時刻の新しい順。評価文は `evaluation_published_at` の日付・時刻。時刻のない記録はその日のいちばん後ろ。日付と時刻がまったく同じなら記録が先）にコントローラで組み立てて view に渡す（`App\Support\RecordTimeline::merge`。S-1402 と共通。結果は `TraineePersonalityDiagnosisResult::publishedEvaluationsOfClient` で 1 本のクエリで読む）。件数の上限・ページ送りはない（記録と同じく全件）。カードの見出しの件数はトレーニング記録だけを数える
- 同じ view に、**残り回数**（「回数」カードの見出し）と**契約・利用の一覧**（「回数」カード、回数券型の表示）も含める。各契約のマスには、その契約に割り当てた利用（`client_usages.client_contract_id`）を並べる。表示のときに自動で割り当てる処理は持たない（screen-design.md S-0305 セクション5「回数」参照）


###### POST /clients/{client}/email-registration-tokens

**概要**: マイページ登録案内を発行する（内部的にはメールアドレス登録用 URL のトークンを 1 件作成する）。未発行時の発行と、期限切れの URL がある場合の発行し直しを同じエンドポイントで扱う。**有効な（未使用で期限内の）URL がすでにあるときは発行しない**（**2026-10 変更**。下の「レスポンス」）。

**処理**:
- 対象クライアントに有効な（`is_used=false` かつ `expires_at` が現在より後の）`client_email_registration_tokens` があれば、何も作らず・消さずに、その案内の S-0307 にリダイレクトする（下の「レスポンス」）
- なければ、以下を 1 つのトランザクションで実行する：
  - 対象クライアントの `client_email_registration_tokens` のうち未使用（is_used=false）のトークンがあれば物理削除する（**再発行時、それまでのメールアドレス登録用 URL は無効になる**）
  - 対象クライアントの `client_login_link_tokens` のうち未使用（is_used=false）のトークンがあれば物理削除する（**再発行時、送信済みのログイン用リンクも無効になる**）
  - ランダムなトークンを生成し `client_email_registration_tokens` に 1 件作成する。有効期限は今日から **7 日後の日の終わり（23:59:59）**（設定値は `architecture.md` §3-1 参照）（**2026-10-18 変更**：2026-10-18 のユーザーレビューで、7 日後の日の終わり〔23:59:59。日本時間〕までにした。文面には日付だけ〔「2026年10月25日（日）まで」〕を出すので、その日のうちは使えるようにそろえるため。その前は発行した時刻の 7 日後の同じ時刻で切れていた〔10/18 15:00 に発行 → 10/25 15:00 で切れる〕。すでに発行した URL の期限は変えない）。**発行し直したときは、期限が新しく 7 日になる**（引き継ぎではなく新規の 7 日）（**2026-10-18 変更**：2026-10-18 のユーザーレビューで、3 日では短いため 7 日にした。その前は 3 日。すでに発行した URL の期限は変えない）
- メールアドレスの登録有無を問わず発行できる
- **この時点で決まる `expires_at` が初回設定完了までの全体の期限**となる。以降、対応する `client_login_link_tokens`（ログイン用リンク）を作成する際は、この `expires_at` をそのまま引き継ぐ

**レスポンス**:
- 成功（新規発行・期限切れからの発行し直しとも）：`redirect()->route('clients.show', $client)` — **発行のあとは S-0305 会員詳細画面に戻る**（**2026-10-17 変更**）。完了メッセージ「マイページ登録の案内を発行しました。」をフラッシュ `success` に載せ、`layouts.app` の `session('success')` 表示で描画する（取消と同じ出し方）。案内は［マイページ登録の案内を表示］から S-0307 を開いて見る
  - 経緯：2026-10 に、発行のあとは S-0307 印刷ページにリダイレクトし、完了メッセージは載せない作りにしていた（当初は会員詳細のフォームに `target="_blank"` を付けて別タブで送り、2026-10-17 に同じタブに直した）
- 有効な URL がすでにある（二重送信・古い画面から押し直したなど）：新しく発行せず、完了メッセージも載せずに `redirect()->route('clients.show', $client)`（会員詳細は新しい状態で開くので、ボタンは［…を表示］［取消］になっている）
- 発行のあとの戻り先と、その理由は requirements.md 6-3-6 備考「発行のあとの戻り先」
- 発行の URL を GET で開いたときは、下の「発行の URL を GET で開いたとき」

**備考**:
- 発行された URL の受け渡し方法は、**紙の案内シート（S-0307 印刷ページ）**を推奨する。QR コード・URL 文字列・有効期限・手順は印刷ページで確認する（**2026-10-18 変更**：印刷はしなくなった。S-0307 の画面で、LINE・メールで送る文面をコピーして送るか、QR コードをその場で読み取ってもらう。screen-design.md S-0307「2026-10-18 からの作り」）
- 詳細画面（S-0305）にこのエンドポイントを呼ぶボタンが出るのは、**状態が「メールアドレスなし」または「メールアドレス登録待ち（期限切れ）」のとき**。期限内の再発行の場面はない（URL がまだ使えるので同じ URL を渡し直せば足りるため。詳細は 6-3-6 の備考）
- 期限切れの場合、UI 側は未発行と同じ扱いになるため実質的に「新規発行」だが、処理は変わらない（未使用トークンを物理削除して新規作成する。トランザクションで一貫）。期限内の URL があるときは発行し直さない（上の「処理」）
- **メールアドレス登録用 URL を無効にできるのはトレーナーだけ**（このエンドポイントの発行操作、6-3-7 マイページ登録案内の取消、または 6-3-8 メールアドレスの削除）。お客様側の「入力し直す」操作では、メールアドレス登録用 URL のトークンは無効にならない（ログイン用リンクだけが差し替わる）
- 特定の 1 件だけを狙って消す UI（`DELETE /clients/{client}/email-registration-tokens/{tokenId}` のような個別削除）は用意しない。期限切れからの発行し直しで一括置き換えるか、取消（下記 DELETE エンドポイント）またはメールアドレスの削除でまとめて消す運用に統一する

###### GET /clients/{client}/email-registration-tokens（発行の URL を GET で開いたとき）

**2026-10-17 追加**。マイページ登録案内・愛犬アンケート案内・愛犬性格診断の案内の 3 つの発行の URL で共通の扱い。

| 発行の URL | GET で開いたときのリダイレクト先 |
|---|---|
| `/clients/{client}/email-registration-tokens` | `redirect()->route('clients.show', $client)` |
| `/trainees/{trainee}/intake-survey-tokens` | `redirect()->route('clients.show', $trainee->client_id)` |
| `/trainees/{trainee}/personality-diagnosis-tokens` | `redirect()->route('clients.show', $trainee->client_id)` |

**概要**: 発行の URL（POST）が GET で開かれたとき（古いタブ・ブラウザの読み込み直し・履歴など）に、405 のエラー画面を出さず、**発行もせずに**その会員の S-0305 会員詳細画面にリダイレクトする。

**処理**:
- 発行しない（トークンを作らない・消さない）。操作の記録（アクセスログ）も残さない
- 完了メッセージは載せない

**権限**: ふつうの画面と同じ（`auth`・`practitioners`。ログインしていなければログイン画面へ、システム管理者は 403）。会員・トレーニーがなければ 404（ルートモデルバインディング）。

**ルート**: 名前は付けない（画面からは使わないため）。コントローラは各案内のコントローラの `redirectToClient`。

**理由**: 2026-10-17、本番で、Android のタブレットの Chrome で、別のタブに送っていた発行のフォームが新しいタブで GET になり、405 のエラー画面になった。発行と表示を同じタブで開く形に戻し、発行のあとは会員詳細に戻す形にしたが（requirements.md 6-3-6 備考「発行のあとの戻り先」）、それでも古いタブや履歴から発行の URL が GET で開かれることはあるため、エラー画面にせず会員詳細に戻す。

###### DELETE /clients/{client}/email-registration-tokens

**概要**: マイページ登録案内を取り消す（6-3-7 マイページ登録案内の取消）。対象クライアントの未使用のメールアドレス登録用トークンを物理削除し、発行前の状態に戻す。

**パス選定の理由**: 発行の `POST /clients/{client}/email-registration-tokens` と**同じリソース URL に対する DELETE** とすることで、REST の一貫性を保つ（同エンドポイントの HTTP メソッドで発行と取消を対で表す）。既存の `DELETE /clients/{client}/email` と同じくコレクション相当のリソースに対する削除を素直に表現できる。

**処理**:
- 対象クライアントの状態が「メールアドレス登録待ち（期限内）」であることを確認する。当てはまらない場合は 409 を返す（未発行・期限切れ・登録済み等では取り消す対象がない）
- 以下を 1 つのトランザクションで実行する：
  - 対象クライアントの `client_email_registration_tokens` のうち未使用（is_used=false）のトークンを物理削除する（**送信済みのマイページ登録案内はその時点で無効になる**）
  - 対象クライアントの `client_login_link_tokens` のうち未使用（is_used=false）のトークンがあれば物理削除する（この状態では通常存在しないが、整合性のため。**発行 6-3-6・削除 6-3-8 と同じ扱い**）
- `clients.email`・`clients.password`・クライアント情報・トレーニング記録には**触らない**

**レスポンス**:
- 成功：`redirect('/clients/{client}')` — **取消後は S-0305 詳細画面に戻る**。フラッシュ `success` に「マイページ登録案内を取り消しました。」を載せ、`layouts.app` の `session('success')` 表示で描画する（完了メッセージ本体の文言は変えない）。取消後は状態が「メールアドレスなし」に戻り、S-0305 のバッジ隣に出るボタンは「登録案内を発行」だけになる
- 状態が「メールアドレス登録待ち（期限内）」でない：409（不整合。UI 側で取消ボタンをこの状態のときだけ表示しているため通常は到達しない）

**備考**:
- 詳細画面（S-0305）にこのエンドポイントを呼ぶボタンが出るのは、**状態が「メールアドレス登録待ち（期限内）」のとき**だけ。押下時に確認モーダル S-0305-M03 を挟む（文言：「発行済みのマイページ登録案内が使えなくなります。すでにお渡しした案内からは登録できなくなります。」）
- **期限切れは取り消せない**：期限切れの案内はもう使えず、取り消す意味がない。詳細画面のバッジ隣のボタンは「マイページ登録の案内を発行」だけが出て、押下でその場で新規発行する（6-3-6 と同じ扱い）
- **クライアント情報とトレーニング記録には触らない**（メールアドレスの削除 6-3-8 と異なり、`clients.email`・`clients.password` にも触らない）


###### GET /clients/{client}/email-registration-tokens/print

**概要**: 対象クライアントの有効なメールアドレス登録用 URL を、紙の案内シートとしてブラウザの印刷機能で出せる印刷用ページ（S-0307）を表示する。**2026-10-18 変更**：印刷はしなくなった。LINE・メールで送る文面と、その場で読み取ってもらう QR コードの画面にした（トレーナー側のふつうのレイアウト `layouts.app`。`layouts.print` は消した。下の「レスポンス」の印刷専用レイアウトの記述は経緯）。文面（`$copyText`）は `App\Support\InvitationMessage::mypage`（会員の氏名・訓練所名・URL・曜日つきの有効期限。文の中身は `lang/ja/messages.php` の `invitation_message`）で組み立て、画面に出す文とコピーする文に同じものを使う。愛犬アンケート（`GET /trainees/{trainee}/intake-survey-tokens/print`）・愛犬性格診断（`…/personality-diagnosis-tokens/print`）も同じ（`InvitationMessage::intakeSurvey`・`personalityDiagnosis`。トレーニーの呼び名を入れる）。URL・ルート名は変えない

**処理**:
- 対象クライアントに紐づく `client_email_registration_tokens` のうち、`is_used=false` かつ `expires_at > now()` のトークン 1 件を取得する
- 有効なトークンが存在すればそのトークンから URL を組み立て、QR コード用データ・URL 文字列・有効期限（日付のみ）を view に渡す
- **メール／LINE 送付用の案内文面もコントローラで組み立て、view に `$copyText` として渡す**。JavaScript で画面の文字を拾って組み立てる方式はとらない（画面の見た目と文面を独立させるため。screen-design.md S-0307「案内文面のコピー」参照）。事業者名（`config('app.client_portal_company')`）が未設定なら、事業者名の行は文面に含めない
- 見つからない場合はエラーメッセージ用の view を返す（QR・URL・有効期限・コピー文面は出さない）

**レスポンス**:
- 有効なトークンあり：view `clients.email-registration-token-print`。**印刷専用の新規レイアウト**（例：`layouts.print`）で描画する。`layouts.app` のナビゲーションバー・フッターは継承せず、レイアウト側で最初から出力しない。**ページ上部に「戻る」ボタンと「コピー」ボタンを横並びで含める**（両方とも画面表示のみで印刷時は非表示、`d-print-none`。狭い幅では折り返す。詳細は screen-design.md S-0307「操作エリアのまとめ方」参照）。「戻る」はその会員の S-0305 会員詳細へのふつうのリンク（条件分岐で出し分けはしない。**2026-10-17 変更**：その前は、別タブで開かれるため押すとタブを閉じる［閉じる］だった）。「コピー」は押下でサーバー側で組み立て済みの案内文面をクリップボードにコピーする（文面と決め事は screen-design.md S-0307「案内文面のコピー」参照）。**2026-10-18 変更**：ボタンの名前を「文面をコピー」から「コピー」にした。また印刷専用のレイアウトをやめ、［コピー］は「LINE・メールで送る」の欄の見出しの行の右に置いた（今の作りは screen-design.md S-0307「2026-10-18 からの作り」）
- 有効なトークンなし：同じ view を返し、「発行済みのマイページ登録案内がありません。クライアント詳細画面から発行してください」の案内を出す（**戻り導線はページ上部の共通リンクで済ませ、専用の CTA ボタンは置かない**。両状態で見せ方を揃える）。QR・URL・有効期限・コピー文面は出力しない

**備考**:
- **お客様の氏名は view に渡さない**（紙の紛失時に氏名と URL が同時に漏れることを避けるため。決定事項 #3）。**2026-10-18 変更**：印刷しなくなり、LINE・メールで送る文面に氏名を入れる（`InvitationMessage`）
- QR コードは**ビルドに含める JS ライブラリでクライアントサイド生成**する（外部 CDN からの読み込みはしない）。ライブラリ名は architecture.md §2-1 に沿って決定する


###### DELETE /clients/{client}/email

**概要**: クライアントのメールアドレス（と、あればパスワード）を削除し、送信済みのメールアドレス登録用 URL・ログイン用リンクを無効化する（6-3-8 メールアドレスの削除）。**状態は「利用中」または「初回設定待ち」**（`clients.email` が登録済み）で実行できる。

**処理**:
- `clients.email` が非 NULL であることを確認する。NULL の場合は 409（`{"error": "メールアドレスが登録されているクライアントのみ削除できます"}`）を返す
  - この判定で「利用中」（email あり／password あり）と「初回設定待ち」（email あり／password NULL）の両方を受け付ける（screen-design.md S-0305「操作ボタンの配置」参照）
- 以下を 1 つのトランザクションで実行する：
  - `clients.email` を NULL に更新
  - `clients.password` を NULL に更新（既に NULL の場合はそのまま。`hashed` cast は NULL を素通しする）
  - 対象クライアントの `client_email_registration_tokens` のうち `is_used=false` のトークンを物理削除
  - 対象クライアントの `client_login_link_tokens` のうち `is_used=false` のトークンを物理削除
- クライアント情報（氏名・連絡先・住所など）とトレーニング記録は保持する

**レスポンス**:
- 成功：`redirect('/clients/{id}')` ＋「マイページ登録を削除しました。」（**2026-10 変更**。会員詳細のボタン名「マイページ登録を削除」にそろえた）
- `clients.email` が NULL（メールアドレスなし／メールアドレス登録待ち）：409（不整合。UI 側で削除ボタンを「初回設定待ち」または「利用中」のときだけ表示しているため通常は到達しない）

**備考**:
- 送信済みのログイン用リンクの無効化は、対応するトークンを物理削除することで実現する（`clients.password` を NULL にした時点で client guard の attempt もハッシュ照合に失敗するため、二重の防御になる）
- 削除の確認はフロント側の S-0305-M02 メールアドレス削除確認モーダルで受け取り、承諾後にこのエンドポイントを呼ぶ
- **判定条件を `email` の有無だけにした理由**：初回設定待ちでも削除できるようにするため（screen-design.md S-0305 参照）。旧仕様は「利用中限定」（`email` かつ `password` の両方が非 NULL）だったが、初回設定待ちの行き止まりを解消するために `password` の判定を外した


###### DELETE /clients/{id}

**概要**: クライアントを削除する。

**処理**:
- 以下のいずれかが 1 件でも存在する場合は削除不可（判定順。最初に当てはまった 1 つだけでフラッシュを出し即リダイレクト）：
  1. トレーニング記録（`training_records.client_id`。DB 側は CASCADE だがアプリで拒否）
  2. **空でない**トレーニー（`trainees.client_id`。DB 側は CASCADE だがアプリで拒否。**2026-10-18 変更**：空のトレーニーだけなら拒否しない。判定は `Client::hasNonBlankTrainees`〔`Trainee::scopeNotBlank`〕。「空のトレーニー」＝トレーニーの項目（名前・犬種・性別・誕生日〔年・月・日〕・去勢避妊・写真）がどれも空で、トレーニーに結び付く表（計測値・愛犬アンケートの案内と回答・愛犬性格診断の案内と結果〔評価文を含む〕）のどれにも行がないトレーニー（`Trainee::scopeNotBlank`））
  3. 音声記録（`audio_records.client_id`。**2026-10 追加**。DB 側は RESTRICT）
- **契約（`client_contracts`）・利用（`client_usages`）は削除不可の条件にしない**。登録されていても削除を拒否せず、DB の外部キー（CASCADE）で会員と一緒に削除される（トレーニーの「あれば拒否」とは扱いが違う。requirements.md 6-3-5 参照）
- 物理削除（論理削除ではない）
- **空のトレーニーは一緒に削除する**（**2026-10-18 追加**）：1 つのトランザクションで、会員のトレーニー（ここに来るのは空のトレーニーだけ）を明示して削除してから会員を削除する（`trainees.client_id` は CASCADE だが、消すものが読んで分かるように明示する。空のトレーニーには結び付く表の行がないので、ほかに消えるものはない）。操作の記録は `delete_client` だけ（契約・利用を一緒に消すときと同じく、一緒に消したトレーニーの分は残さない）。理由：2026-10-18 のユーザーレビューで、会員の登録でトレーニーを自動で作るようにしたため、トレーニーが 1 頭でもいれば削除できない決まりのままだと、間違えて登録した会員を消せなくなる。中身のあるトレーニーは今までどおり守る
- **最後の守り**：画面・サーバの条件判定をすり抜けて `$client->delete()` が DB の外部キーに断られた場合（`Illuminate\Database\QueryException` で `SQLSTATE=23000`・エラー番号 1451）は、500 を返さずに会員詳細へリダイレクトし、`Log::warning` に会員 ID と例外クラス名を残す（**2026-10 追加**）。それ以外の例外は再 throw する

**レスポンス**:
- 成功：`redirect('/clients')` ＋「会員を削除しました。」
- トレーニング記録あり：`redirect('/clients/{id}')` ＋「この会員にはトレーニング記録が登録されているため削除できません。」
- 空でないトレーニーあり：`redirect('/clients/{id}')` ＋「この会員にはトレーニーが登録されているため削除できません。」
- 音声記録あり：`redirect('/clients/{id}')` ＋「この会員には音声記録が登録されているため削除できません。」
- 外部キーに断られた場合（最後の守り）：`redirect('/clients/{id}')` ＋「この会員には関連するデータが登録されているため削除できません。」
- 権限なし：403エラー（「管理者のみ削除できます。」）



---

##### S-0306 クライアント編集画面

###### GET /clients/{id}/edit

**概要**: クライアント編集画面を表示する。

**レスポンス**:
- view `clients.edit`


###### PUT /clients/{id}

**概要**: クライアント情報を更新する。

**リクエスト**:
POST /clients と同じ項目。ただし internal_id は登録時と異なり、ユーザーが手入力で変更可能:

| パラメータ | 型 | 必須 | バリデーション | 備考 |
|-----------|-----|------|---------------|------|
| internal_id | string | ● | required, numeric, unique:clients,internal_id,{自身を除外} | 登録時はサーバー採番だが、編集時は手入力で変更可能 |

**レスポンス**:
- 成功：`redirect('/clients/{id}')`
- 失敗：`back()` ＋ バリデーションエラーメッセージ

---


#### 4-1-4. トレーニング記録管理

##### S-0401 トレーニング記録登録画面

トレーニング記録は必ずクライアント詳細画面（S-0305）から登録する。

###### GET /training-records/create

**概要**: トレーニング記録登録画面を表示する。

**クエリパラメータ**:
| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| client_id | integer | ● | 登録対象クライアントID |
| audio_file_id | integer | | 録音実行画面（S-0502）からの遷移時に引き継ぐ音声記録ID |

**レスポンス**:
- view `training-records.create`
- client_id 未指定・不存在：クライアント一覧へリダイレクト


###### POST /training-records

**概要**: トレーニング記録を新規登録する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| client_id | integer | ● | required, exists:clients,id | クライアントID |
| training_date | date | ● | required, date | トレーニング日（過去・未来日付とも入力可）。呼び方は screen-design.md §2-6 参照 |
| training_time | time | | nullable, date_format:H:i | 時刻 |
| trainer1_id | integer | ● | required, exists:trainers,id | 担当1 |
| trainer2_id | integer | | nullable, exists:trainers,id, different:trainer1_id | 担当2（担当1と異なること） |
| record_content | text | | nullable, string | トレーナーからのノート |
| impression | text | | nullable, string | 所感 |
| media_record_ids | array | | nullable, array | この記録に紐づけるメディアのID配列。**配列の順序が表示順**となる。空配列・未送信は紐づけなし |
| media_record_ids.* | integer | | integer, distinct, exists:media_records,id | 各メディアID（重複不可、実在すること） |

**処理**:
- トレーニング記録を新規登録する
- メディア紐づけを作成する（記録の登録と同一トランザクション）：
  - media_record_ids の配列で中間テーブル（media_record_training_record）に紐づけを作成する
  - 配列の順序で sort_order を 0 始まりの連番（0, 1, 2, ...）として保存する
- メディア紐づけの作成とトレーニング記録の登録は、すべて1トランザクションで反映する（いずれかが失敗した場合はロールバックする）

**レスポンス**:
- 成功：`redirect('/training-records/{id}')`
- 失敗：`back()` ＋ バリデーションエラーメッセージ

---

##### S-0402 トレーニング記録一覧画面

###### GET /training-records

**概要**: トレーニング記録一覧画面を表示する。

**クエリパラメータ**（すべて任意）:

| パラメータ | 型 | 説明 |
|-----------|-----|------|
| internal_id | string | クライアントの内部ID（部分一致） |
| name | string | クライアントの氏名（姓名およびそのかな、計4項目を部分一致でOR検索） |
| date_from | date | トレーニング日（開始日）。呼び方は screen-design.md §2-6 参照 |
| date_to | date | トレーニング日（終了日） |
| trainer_id | integer | 担当トレーナーID（担当1・担当2のいずれかに一致） |
| keyword | string | 記録内容・所感を対象としたキーワード検索（部分一致でOR検索） |
| sort | string | ソートカラム（training_date, internal_id, client_name, created_at）。未指定時は training_date。internal_idは数値順、client_nameは画面表示上の氏名で五十音順 |
| direction | string | ソート方向（asc, desc）。asc以外はdesc |

**レスポンス**:

- view `training-records.index`

---

##### S-0403 トレーニング記録詳細画面

###### GET /training-records/{id}

**概要**: トレーニング記録詳細画面を表示する。

**処理**:
- 対象トレーニング記録を取得する
- この記録に紐づくメディアを sort_order 順（昇順）で取得し、view に渡す（閲覧用。再生は `GET /api/media-records/{id}/play` を利用する）
- （**2026-10-18**：段階1 の②では、結び付いた愛犬性格診断の結果があれば、その結果とレーダーチャートの値を渡して診断の欄を出していた。評価文を記録にしなくなったためやめた）

**レスポンス**:
- view `training-records.show`


###### DELETE /training-records/{id}

**概要**: トレーニング記録を削除する。

**処理**:
- 物理削除（論理削除ではない）

**レスポンス**:
- 成功：そのトレーニング記録が紐づくクライアントの詳細画面へリダイレクト（`redirect('/clients/{client_id}')`）
- 権限なし：403エラー（「管理者のみ削除できます。」）

---

##### S-0404 トレーニング記録編集画面

###### GET /training-records/{id}/edit

**概要**: トレーニング記録編集画面を表示する。

**処理**:
- 対象トレーニング記録を取得する
- この記録に紐づくメディアを sort_order 順（昇順）で取得し、view に渡す（メディアセクションの初期表示用）

**レスポンス**:
- view `training-records.edit`


###### PUT /training-records/{id}

**概要**: トレーニング記録を更新する。

**リクエスト**:
POST /training-records に以下を追加する。

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| media_record_ids | array | | nullable, array | この記録に紐づけるメディアのID配列。**配列の順序が表示順**となる。空配列・未送信は紐づけなし |
| media_record_ids.* | integer | | integer, distinct, exists:media_records,id | 各メディアID（重複不可、実在すること） |

- **`client_id` は受け取らない**（**2026-10-18 変更**。requirements.md 6-4-5）：POST の項目のうち `client_id` だけはバリデーションの対象から外し、送られてきても使わない（会員は作ったときのまま。記録に結び付くメディアや会員詳細の表示がずれないように）

**処理**:
- トレーニング記録本体を更新する
- メディア紐づけを更新する（記録本体の更新と同一トランザクション）：
  - media_record_ids の配列で中間テーブル（media_record_training_record）を総入れ替えする。配列にないメディアは紐づけ解除、新たに含まれるメディアは紐づけ追加となる
  - 配列の順序で sort_order を 0 始まりの連番（0, 1, 2, ...）として振り直す
  - 紐づけの追加・解除・並べ替えは、いずれもトレーニング記録の更新として扱い、本記録の updated_at / updated_by を更新する
- メディア紐づけの追加・解除・並べ替えとトレーニング記録本体の更新は、すべて1トランザクションで反映する（いずれかが失敗した場合はロールバックする）

**レスポンス**:
- 成功：そのトレーニング記録が紐づくクライアントの詳細画面へリダイレクト（`redirect('/clients/{client_id}')`）
- 失敗：`back()` ＋ バリデーションエラーメッセージ

---


#### 4-1-5. 音声記録管理

##### S-0501 録音準備画面

###### GET /recording-v2

**概要**: 録音準備画面を表示する。

**レスポンス**:
- view `recording-v2.index`（クライアント選択はSelect2で内部API `/api/clients/search` を使用）


###### POST /recording-v2/start

**概要**: 録音実行画面に遷移する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| client_id | integer | ● | required, exists:clients,id | 録音対象クライアントID |

**処理**:
- client_idをセッションに保存し、録音実行画面（GET /recording-v2/session）へリダイレクト

**レスポンス**:
- 成功：`redirect` で GET /recording-v2/session（録音実行画面）へ
- 失敗：`back()` ＋ バリデーションエラーメッセージ

---

##### S-0502 録音実行画面

録音して音声記録を作成することがこの画面の主機能。さらに、作成した音声記録に文字起こし・要約を行い、その要約からトレーニング記録を作成することも可能。

処理フロー（**2026-10 変更**）：
1. 録音した音声を POST /audio-records/recording でアップロードし、音声記録を作成
2. トレーニング記録を作る場合は、POST /api/audio-records/{id}/auto-create-training-record で、文字起こし → 要約 → トレーニング記録の作成をひとつながりで受け付けてもらう（後ろで進む。画面は待たずにログアウトする）

（以前は、画面が POST /api/audio-records/{id}/transcribe → POST /api/audio-records/{id}/summarize → POST /api/training-records/auto-create を、それぞれの応答を待ちながら順に呼んでいた）

###### GET /recording-v2/session

**概要**: 録音実行画面を表示する。

**処理**:
- セッションから client_id を取得し、対象クライアントを取得する
- client_id がない、または該当クライアントが存在しない場合は録音準備画面（S-0501）へリダイレクト

**レスポンス**:
- view `recording-v2.session`


###### POST /audio-records/recording

**概要**: 録音した音声をアップロードして音声記録を作成する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| client_id | integer | ● | required, exists:clients,id | 紐付けるクライアントID |
| file | file | ● | required, file, max:102400 | 録音した音声（最大100MB） |

**処理**:
- タイトルを `YYYYMMDD_HHMM_{ログインユーザーのlogin_id}` で自動生成
- 音声ソース種別は recording、状態（status）は未処理（unprocessed）で作成

**レスポンス**（JSON）:
- 成功：`{ "data": <音声記録> }`（201）

---

##### S-0503 音声記録登録（文字起こしテキスト）画面

###### GET /audio-records/text-paste/create

**概要**: 音声記録登録（文字起こしテキスト）画面を表示する。

**処理**:
- タイトルのデフォルト値（`YYYYMMDD_HHMM`形式）をサーバー側で生成する

**レスポンス**:
- view `audio.text-paste-create`


###### POST /audio-records/text-paste

**概要**: 文字起こしテキストから音声記録を登録する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| client_id | integer | ● | required, exists:clients,id | 紐付けるクライアントID |
| title | string | ● | required, string, max:255 | タイトル |
| transcription_text | string | ● | required, string | 文字起こしテキスト |

**処理**:

- 入力された文字起こしテキストで音声記録を作成する
- 音声ソース種別は text_paste、状態（status）は文字起こし済み（transcribed）で作成

**レスポンス**:
- `redirect('/audio-records')`

---

##### S-0504 音声記録登録（音声ファイルのアップロード）画面

###### GET /audio-records/upload/create

**概要**: 音声記録登録（音声ファイルのアップロード）画面を表示する。

**レスポンス**:
- view `audio.upload-create`


###### POST /audio-records/upload

**概要**: 音声ファイルをアップロードして音声記録を登録する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| client_id | integer | ● | required, exists:clients,id | 紐付けるクライアントID |
| file | file | ● | required, file, max:102400 | 音声ファイル（最大100MB） |
許可拡張子：mp3, m4a, wav, mp4, webm

**処理**:
- タイトルをアップロードファイル名（拡張子を除く）で自動生成
- 音声ソース種別は upload、状態（status）は未処理（unprocessed）で作成

**レスポンス**:
- `redirect('/audio-records')`

---

##### S-0505 音声記録一覧画面

###### GET /audio-records

**概要**: 音声記録一覧画面を表示する。

**クエリパラメータ**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| trainer_id | string | | — | トレーナーで絞り込む。未指定はログイン中のトレーナー、`all` で全トレーナー、トレーナーIDの指定でそのトレーナーの音声記録 |
| page | integer | | — | ページ番号（1 ページ 5 件） |

**処理**:
- ページ番号が最後のページを超えている（かつ、全件が 1 件以上ある）ときは、ほかのクエリ（`trainer_id`・`highlight`・`done` など）を保ったまま、`page` を最後のページにした一覧へリダイレクトする（**2026-10 追加**。そのページの最後の 1 件を削除したときなど）。全件が 0 件のときは、そのまま空の一覧を表示する

**レスポンス**:
- view `audio.index`（ページ番号が最後のページを超えたときは、上のリダイレクト）


###### GET /audio-records/{id}

**概要**: 音声記録の詳細を取得する。

**レスポンス**（JSON）:
- `{ "data": { ... } }`

`data` の内容（音声記録一覧画面の編集部分で使用する項目）：

| フィールド | 型 | 説明 |
|-----------|-----|------|
| title | string | 表示用タイトル |
| transcription_text | string\|null | 文字起こし結果 |
| summary_text | string\|null | 要約結果 |
| can_delete | boolean | 音声記録を削除できるか |
| has_audio_file | boolean | 音声ファイルを保持しているか |
| delete_audio_url | string\|null | 音声ファイル削除APIのURL。音声ファイルがない場合はnull |


###### PUT /audio-records/{id}

**概要**: 音声記録のタイトル・文字起こしテキスト・要約テキストを編集する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| title | string | ● | required, string, max:255 | タイトル |
| transcription_text | string | | nullable, string | 文字起こしテキスト |
| summary_text | string | | nullable, string | 要約テキスト |

**処理**:
- 処理中（止まっていない文字起こし中・要約中）の記録は編集を受け付けない（停滞判定に使う `updated_at` を上書きしないため。30 分以上経過して止まったとみなす記録は受け付ける。**2026-10 変更**。以前は 15 分）
- 表示名・文字起こしテキスト・要約テキストを保存する（`status` は変えない）

**レスポンス**:
- 通常のフォーム送信：`redirect('/audio-records')`（成功時は success、処理中で断ったときは error のフラッシュメッセージ）。入力エラーは Laravel 標準の戻り
- `Accept: application/json` のとき（**2026-09 追加**。音声記録一覧で要約の前に保存するため）：
  - 成功：HTTP 200 `{ "data": { "id": 音声記録ID, "message": "音声記録を保存しました。" } }`
  - 処理中：HTTP 409 `{ "error": { "message": "処理中の音声記録は編集できません。処理が完了してから編集してください。" } }`
  - 入力エラー：HTTP 422（Laravel 標準の `{ "message", "errors" }`）


###### DELETE /audio-records/{id}

**概要**: 音声記録を削除する（物理削除。サーバー上の音声ファイルとレコードの両方を削除する）。

**リクエスト**（**2026-10 追加**。削除のあとも一覧のページ番号と登録者の絞り込みを保つため）:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| page | integer | | — | 削除の前に見ていた一覧のページ番号。1 以上の整数のときだけ戻り先に使い、それ以外は無視する |
| trainer_id | string | | — | 削除の前の一覧の登録者の絞り込み。`all` か、存在するトレーナーの ID のときだけ戻り先に使い、それ以外は無視する |

**処理**:
- 処理中（文字起こし中・要約中）の場合は削除せず、音声記録一覧へリダイレクト

**レスポンス**:
- `redirect('/audio-records?page=…&trainer_id=…')`（受け取った `page`・`trainer_id` のうち、確かめた値だけを付けて一覧のルートで組み立てる。送られてきた URL はそのまま使わない）。成功時は success（「音声記録を削除しました。」）、処理中で断ったときは error のフラッシュメッセージ。成功時の文言は 2026-10 に「音声ファイルを削除しました。」から直した（「音声ファイルのみ削除」の完了メッセージと区別がつかなかったため）


###### DELETE /audio-records/{id}/delete-audio

**概要**: 音声記録の音声ファイルのみ削除する（文字起こし・要約は保持）。

**リクエスト**（**2026-10 追加**）: `page`・`trainer_id`（`DELETE /audio-records/{id}` と同じ）

**処理**:
- 処理中（文字起こし中・要約中）の場合は削除せず、音声記録一覧へリダイレクト

**レスポンス**:
- 成功：`redirect('/audio-records?page=…&trainer_id=…&highlight={id}&done=audio_deleted')`（**2026-10 変更**。一覧でその行の編集パネルを開き直し、パネルの中に完了メッセージ「音声ファイルを削除しました。」を出す。フラッシュメッセージは出さない）
- 処理中で断ったとき：`redirect('/audio-records?page=…&trainer_id=…')`（error のフラッシュメッセージ）


###### GET /audio-records/{id}/play

**概要**: 音声ファイルを再生する（ストリーミング）。

**処理**:
- 音声ファイル（file_path）が存在しない場合（テキスト貼り付け・音声削除済みなど）は 404 を返す

**レスポンス**:
- 音声ファイル（Content-Type は拡張子に応じて設定）

---


#### 4-1-6. 要約

##### S-0601 要約プロンプト画面

###### GET /settings/summary-prompts

**概要**: 要約プロンプト画面を表示する。

**レスポンス**:
- view `settings.summary-prompts`


###### PUT /settings/summary-prompts

**概要**: 要約プロンプトを更新する。

**リクエスト**:
| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| current_prompt | string | ● | required, string, max:5000 | 要約プロンプト |

**レスポンス**:
- `redirect('/settings/summary-prompts')`

---


#### 4-1-7. 音声ファイル容量管理

##### S-0701 音声ファイル一覧画面

###### GET /usage-stats

**概要**: 音声ファイル一覧画面を表示する（サーバー容量管理用）。

**処理**:
- 実ファイルが存在する音声記録（`file_path` があり `file_size > 0`）を対象に、総ファイル数・総容量を集計する
- 一覧を日時の降順で表示する

**レスポンス**:
- view `usage-stats.index`

---


#### 4-1-8. トレーナー管理

##### S-0801 トレーナー登録画面

###### GET /trainers/create

**概要**: トレーナー登録画面を表示する。

**レスポンス**:
- view `trainers.create`


###### POST /trainers

**概要**: トレーナーを登録する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| login_id | string | ● | required, string, max:50, regex:`/^[a-zA-Z0-9_]+$/`, unique:trainers | ログインID（半角英数字とアンダースコア） |
| name | string | ● | required, string, max:100 | 氏名 |
| password | string | ● | required, string, confirmed, StrongPassword | パスワード |
| password_confirmation | string | ● | — | パスワード（確認） |
| role | string | ● | required, in:admin,staff | 権限 |

**処理**:
- 初回ログイン時にパスワード変更を必須とする状態で作成する
- 表示順は末尾に自動で付番する

**レスポンス**:
- `redirect('/trainers')`

---

##### S-0802 トレーナー管理画面

###### GET /trainers

**概要**: トレーナー管理画面を表示する。

**処理**:
- システム管理者を除くトレーナーの一覧を表示する（各トレーナーの主担当クライアント数、トレーニング記録の担当件数を含む）

**レスポンス**:
- view `trainers.index`


###### PATCH /trainers/{id}/move-up

**概要**: トレーナーの表示順を上に移動する。

**処理**:
- 一覧の表示順で、ひとつ上のトレーナーと表示順を入れ替える

**レスポンス**:
- `redirect('/trainers')`


###### PATCH /trainers/{id}/move-down

**概要**: トレーナーの表示順を下に移動する。

**処理**:
- 一覧の表示順で、ひとつ下のトレーナーと表示順を入れ替える

**レスポンス**:
- `redirect('/trainers')`


###### PATCH /trainers/{id}/unlock

**概要**: アカウントロックを解除する。

**処理**:
- アカウントのロックを解除し、ログイン失敗の記録をリセットする

**レスポンス**:
- `redirect('/trainers')`


###### PATCH /trainers/{id}/toggle-active

**概要**: アカウントの有効/無効を切り替える。

**処理**:
- アカウントの有効・無効を切り替える
- 次の場合は無効化できない：システム管理者、自分自身、有効な管理者が自分ひとりだけの管理者
- 無効化されたトレーナーはログインできなくなる。主担当の割り当ては残るが、主担当の付け替え候補には表示されない

**レスポンス**:
- `redirect('/trainers')`


###### DELETE /trainers/{id}

**概要**: トレーナーを削除する。

**処理**:
- トレーナーを削除する（物理削除）
- 次の場合は削除できない：システム管理者、自分自身、最後の管理者、主担当クライアントがいる、トレーニング記録の担当者になっている

**レスポンス**:
- `redirect('/trainers')`

---

##### S-0803 トレーナー編集画面

###### GET /trainers/{id}/edit

**概要**: トレーナー編集画面を表示する。

**レスポンス**:
- view `trainers.edit`


###### PUT /trainers/{id}

**概要**: トレーナーを更新する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| login_id | string | ● | required, string, max:50, regex:`/^[a-zA-Z0-9_]+$/`, unique:trainers（自分自身は除く） | ログインID（半角英数字とアンダースコア） |
| name | string | ● | required, string, max:100 | 氏名 |
| role | string | ● | required, in:admin,staff | 権限 |

**処理**:
- システム管理者は編集できない
- 自分自身は編集できない
- 最後の管理者を一般に変更することはできない

**レスポンス**:
- `redirect('/trainers')`

---

##### S-0804 パスワードリセット画面

###### GET /trainers/{id}/reset-password

**概要**: パスワードリセット画面を表示する。

**レスポンス**:
- view `trainers.reset-password`


###### PUT /trainers/{id}/reset-password

**概要**: パスワードをリセットする。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| new_password | string | ● | required, string, confirmed, StrongPassword | 新しいパスワード |
| new_password_confirmation | string | ● | — | 新しいパスワード（確認） |

**処理**:
- パスワードを更新し、次回ログイン時にパスワード変更を必須とする
- システム管理者のパスワードはリセットできない

**レスポンス**:
- `redirect('/trainers')`

---

##### S-0805 トレーナー操作履歴画面

###### GET /access-logs

**概要**: トレーナー操作履歴画面を表示する。

**クエリパラメータ**（すべて任意）:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| trainer_id | integer | | — | トレーナーで絞り込む |
| action | string | | — | 操作種別で絞り込む |
| date_from | date | | — | 期間の開始日（その日を含む） |
| date_to | date | | — | 期間の終了日（その日を含む） |

**処理**:
- 操作履歴を日時の降順で表示する

**レスポンス**:
- view `access-logs.index`

---


#### 4-1-9. マスタ管理

（2026-09 削除。欠番）唯一のマスタだったトレーニング内容マスタを廃止したため、マスタ管理配下の全エンドポイント（`/master/training-types` 系）を廃止した。節番号は振り直さない。

##### S-0902 トレーニング内容マスタ画面

（2026-09 削除。欠番）画面と関連する 6 エンドポイントは廃止。画面 ID は振り直さない。

---

#### 4-1-10. セキュリティ設定

##### S-1002 IPアドレス制限画面

###### GET /settings/ip-restriction

**概要**: IPアドレス制限画面を表示する。

**処理**:
- 現在のIP制限の有効・無効の状態、許可IPアドレスの一覧、接続元のIPアドレスを表示する

**レスポンス**:
- view `settings.ip-restriction`

###### PUT /settings/ip-restriction

**概要**: IPアドレス制限設定を更新する。

**リクエスト**:
| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| enable_ip_restriction | boolean | | — | IPアドレス制限の有効・無効 |
| ip_addresses[] | array | | 各要素：IPv4またはCIDR形式、重複不可 | 許可するIPアドレス |
| descriptions[] | array | | 各要素：100文字以内 | 各IPアドレスの備考（拠点名など） |

**処理**:
- IP制限の有効・無効を保存する
- 許可IPアドレスの一覧を入れ替える（空の行はスキップ。形式・重複・備考の文字数に誤りがある場合は保存せず、エラーを表示）
- 制限が無効でも、許可IPアドレスの一覧は保持される

**レスポンス**:
- `redirect('/settings/ip-restriction')`

---


#### 4-1-11. レポート

##### S-1101 トレーニング記録数推移画面

###### GET /statistics/clients

**概要**: トレーニング記録数推移画面を表示する。

**クエリパラメータ**（すべて任意）:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| trainer_id | string | | — | トレーナーで絞り込む（`all` または トレーナーID）。一般トレーナー（staff）の場合は無視され、自分が担当するトレーニング記録のみが集計される |
| view_type | string | | — | 表示タイプ（`fiscal_year`：年度別／`calendar_year`：年別）。既定は年度別 |
| period | integer | | — | 表示する期間（年度別なら年度、年別なら年） |

**処理**:
- トレーニング記録数の年度別または年別の推移、月別の推移を集計する

**レスポンス**:
- view `statistics.clients`

---


#### 4-1-12. マイプロフィール

##### S-1201 マイプロフィール画面

###### GET /profile

**概要**: マイプロフィール画面を表示する。

**レスポンス**:
- view `profile.edit`

###### PUT /profile

**概要**: プロフィールを更新する。

**リクエスト**:
| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| name | string | ● | required, string, max:100 | 氏名 |

**レスポンス**:
- `redirect('/profile')`

---

##### S-1202 パスワード変更画面

###### GET /profile/password

**概要**: パスワード変更画面を表示する。

**レスポンス**:
- view `profile.password`


###### PUT /profile/password

**概要**: パスワードを変更する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| current_password | string | ● | required, string, current_password | 現在のパスワード（Laravel 標準の `current_password` ルールで照合。段階 3-5 で `Hash::check` の自前実装から置き換えた） |
| new_password | string | ● | required, string, confirmed, StrongPassword | 新しいパスワード |
| new_password_confirmation | string | ● | — | 新しいパスワード（確認） |

**処理**:
- 現在のパスワードが一致しない場合は、Validator が失敗を返しパスワード変更画面に戻る（文言は `lang/ja/validation.php` の `current_password`：「現在のパスワードが正しくありません。」）

**レスポンス**:
- `redirect('/profile')`

---


#### 4-1-13. メディア管理

##### S-1302 メディア一覧画面

メディアの一覧表示・詳細表示・編集・削除を行う。アップロードはメディア登録モーダル（S-1302-M02）から内部API（署名付きURL発行→レコード作成）で行う。

###### GET /media-records

**概要**: メディア一覧画面を表示する。

**リクエスト**（クエリパラメータ）:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| trainer_id | integer | | nullable, exists:trainers,id | 登録者で絞り込む。未指定時はログイン中のトレーナー、「全員」選択時は全件 |
| page | integer | | nullable, integer, min:1 | ページ番号 |

**処理**:
- メディアをサムネイルのグリッドで一覧表示する
- 登録者で絞り込む（デフォルトはログイン中のトレーナー）
- 登録日時の新しい順に並べ、最大表示件数を超える場合はページネーションする
- **日付グループ見出しの挿入は表示側（Blade）で行い、サーバ側のクエリの並び順・レスポンス形状は変えない**（Paginator の中身をそのまま渡し、`{{ $mediaRecords->links() }}` によるページネーションリンク生成を無傷にする。将来「サーバ側で `groupBy` すればよい」と誤解しないこと）

**レスポンス**:
- view `media-records.index`

###### GET /media-records/{id}

**概要**: メディアの詳細を取得する（詳細モーダル S-1302-M01 用）。

**処理**:
- 対象メディア1件を取得して返す

**レスポンス**（JSON）:
- 成功：`{ "data": <メディア> }`
- 該当なし：エラーを返す（HTTP 404）

###### PUT /media-records/{id}

**概要**: メディアの表示名を更新する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| title | string | | nullable, string, max:255 | 表示名。未入力時は元ファイル名を表示 |

**処理**:
- 表示名を更新する

**レスポンス**（JSON）:
- 成功：`{ "data": <メディア> }`

###### DELETE /media-records/{id}

**概要**: メディアを削除する。

**処理**:
- メディアレコードを削除し、あわせてオブジェクトストレージ上のファイル実体（本体・サムネイル）を削除する

**レスポンス**（JSON）:
- 成功：`{ "success": true }`

---


#### 4-1-14. 共通

##### ログアウト

###### POST /logout

**概要**: ログアウトする。

**処理**:
- ログアウトを操作履歴に記録する

**レスポンス**:
- `redirect('/login')`

---


#### 4-1-15. クライアント閲覧

##### S-1401 クライアントログイン画面

###### GET /client-portal/login

**概要**: クライアントログイン画面を表示する。

**レスポンス**:
- 未ログインの場合：view `client.login`
- ログイン済みの場合：クライアントダッシュボード画面へリダイレクト


###### POST /client-portal/login

**概要**: クライアントとしてログインする。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| email | string | ● | required, email | メールアドレス |
| password | string | ● | required, string | パスワード |

**処理**:
- 初回設定が完了している（clients.email と clients.password が両方 NOT NULL の）クライアントのみログインできる
- トレーナーのログイン（web guard）とは独立した client guard で認証する
- password が NULL のクライアント（初回設定未完了）は attempt が自然に失敗する（hashCheck が NULL に対して不一致となる）。専用のフラグを持たなくてもメール存在確認耐性は保たれる

**レスポンス**:
- 成功：クライアントダッシュボード画面へリダイレクト
- 認証失敗、または初回設定が未完了の場合：入力項目ではなく専用のキー `login` で `withErrors(['login' => '…'])` で返す（画面設計書 §2-7「ログインの認証の失敗・アカウントの無効化・ロックの出し方」の共通ルール。段階 4-1 で `email` キーから `login` キーに移した）：「メールアドレスまたはパスワードが正しくありません。」

---

##### S-1402 クライアントダッシュボード画面

###### GET /client-portal/dashboard

**概要**: クライアントダッシュボード画面を表示する。

**処理**:
- ログイン中のクライアント自身のトレーニング記録の一覧を、日付の新しい順（降順）で取得し、view に渡す。同じ日は時刻の新しい順（時刻のない記録はその日のいちばん後ろ。**2026-10-18 追加**：S-0305 と並びをそろえるため）
- **公開済みの愛犬性格診断の評価文を混ぜる**（**2026-10-18 追加**。requirements.md 6-15-6）：自分のトレーニーの結果のうち `evaluation_published_at` が NULL でないものを読み込み、記録と 1 本の並び（S-0305 と同じ並び。評価文は公開した日時。`App\Support\RecordTimeline::merge`）に組み立てて `timeline` として渡す。最新の記録のカード（hero）は、混ぜた並びの先頭。「全 N 回」は記録だけを数える（`recordCount`。0 のときは出さない）。ページ送りはない（今の記録と同じく全件）。下書き・公開を取り消したもの・ほかの会員のものは入れない
- トレーニーごとの体重推移データ（6-15-14）と、**トレーニー写真の表示用 URL**（6-15-15）も view に渡す。写真 URL は `Storage::disk('media')->temporaryUrl($trainee->photo_path, ...)` で発行する（`photo_path` が NULL のトレーニーは URL を渡さず、Blade で「写真を登録」の案内に切り替える）

**レスポンス**:
- view `client.dashboard`

---

###### POST /client-portal/trainees/{trainee}/photo

**概要**: トレーニー写真をアップロード（新規登録・差し替え）する（**2026-09 追加**、要件定義書 6-15-15）。**サーバ経由の multipart POST**方式で、署名付き URL の直アップロードは使わない（詳細は screen-design.md S-1402「検討して採らなかった案」#2 参照）。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| photo | file | ● | required, file, mimes:jpg,jpeg,png,heic,heif, max:20480 | 画像ファイル。JPEG / PNG / HEIC / HEIF を受け付け、サーバ側で JPEG に変換して保存する。上限 20MB（既存メディアの写真上限 `MediaRecord::MAX_PHOTO_SIZE` に揃える）。`image` ルールは使わない（HEIC を弾く可能性があるため、拡張子ベースの `mimes` にフォールバックさせる） |
| _trainee_id | integer |  | — | 送信元のトレーニー ID。バリデーションエラー・変換失敗で入力画面に戻ったとき、どのトレーニーのフォームでエラーかを識別するためのヒント。`TraineePhotoController` の `validate()` では規則に入れないため `validated()` に混入しない。計測値モーダル（S-0309）と同じ流儀 |

**処理**:
- 経路の `{trainee}` の所有権を検証する（`$trainee->client_id === auth('client')->id()` でなければ 403）
- 一時ディレクトリに multipart ファイルを保存し、`magick`（ImageMagick CLI）を子プロセスで呼ぶ：
  - `-auto-orient`（EXIF orientation を焼き込み、iPhone の縦撮り写真の横倒しを防ぐ）
  - `-resize 400x400\>`（長辺 400px、拡大はしない）
  - `-quality 85`
  - 出力は `.jpg` に強制
- ストレージキー `trainees/YYYYMM/{uuid}.jpg` を採番し、`Storage::disk('media')->put()` で保存する
- 既存の `photo_path` があれば**新ファイル保存後に旧ファイルを削除**する（順序：新ファイル PUT → DB カラム更新 → 旧ファイル削除。途中で失敗しても孤児ファイルにならないよう新ファイルを先に確定させる）
- `trainees.photo_path` を新しいキーに更新する。`updated_by` は**触らない**（会員操作のため、トレーナー更新の追跡カラムを汚さない）
- 一時ファイルは finally で必ず削除する（成功時・失敗時とも）

**レスポンス**:
- 成功：S-1402 ダッシュボードへリダイレクト＋完了メッセージ（`session('success')` に「写真を登録しました。」）
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ（screen-design.md §2-7 の規約で返す。該当トレーニーのカードにだけ上部の案内と欄下の文言を出す。`photo.file`〔アップロードそのものの失敗〕も `photo` キーで同じ欄下に出す）
- 変換・保存失敗（magick 実行失敗、ストレージ書き込み失敗など）：ログに記録し（既存 `MediaThumbnailService::toUtf8` と同型で stderr を UTF-8 化）、`back()` ＋ 入力項目ではなく専用のキー `form` で `withErrors(['form' => '…'])` で返す（画面設計書 §2-7「入力エラー以外の失敗を入力の項目のキーで返さないルール」。段階 4-4 で `photo` キーから `form` キーに移した）：「写真の登録に失敗しました。時間をおいて試してみてください。」。該当トレーニーのカードにだけ表示する

---

###### DELETE /client-portal/trainees/{trainee}/photo

**概要**: トレーニー写真を削除する（**2026-09 追加**、要件定義書 6-15-15）。

**処理**:
- 経路の `{trainee}` の所有権を検証する（`$trainee->client_id === auth('client')->id()` でなければ 403）
- `trainees.photo_path` が NULL なら 404 相当（削除対象が存在しないため）
- 実ファイルを `Storage::disk('media')->delete($trainee->photo_path)` で削除する
- 削除成功後に `trainees.photo_path` を NULL に更新する（順序：ファイル先削除 → DB カラム NULL 化。既存の `MediaRecordController::destroy` と同型）
- `updated_by` は**触らない**（会員操作のため）

**レスポンス**:
- 成功：S-1402 ダッシュボードへリダイレクト＋完了メッセージ

---

##### S-1403 クライアントマイページ登録画面

**2026-10-18 変更**：2026-10-18 のユーザーレビューで、目的がはっきり分かるよう、お客様に見せる名前を「初回設定」から「マイページ登録」にした（トレーナー側の「マイページ登録のご案内」〔S-0307〕から進む画面と言い方がそろう）。その前の画面名は「クライアント初回設定画面」。URL・ルート名・コントローラ名（`InitialSetupController`）は変えない。下の説明の「初回設定」は、この画面での登録を指す。

クライアントがメールで受け取ったログイン用リンクからアクセスする、認証不要の公開画面。トークン検証と同時にクライアントを client guard で自動ログインさせる（`auth:client` ミドルウェアは適用しない）。

###### GET /client-portal/setup/{token}

**概要**: ログイン用リンクのトークンを検証し、マイページ登録画面を表示する。

**処理**:
- トークンの有効性を「存在する／期限内／未使用」の順にチェック
- いずれかを満たさない場合はエラー画面を表示（無効・期限切れ・使用済みでメッセージを出し分ける）
- 有効な場合、対象クライアントで client guard に自動ログインさせる
- **リンクを開いただけでは使用済み（is_used=true）にはしない**。使用済みマークは POST 時（初回設定を送信した時点）のみ

**レスポンス**:
- 有効：view（初回設定フォーム。上部に対象クライアントのメールアドレスを表示、姓・名・かなはトレーナーが登録した値を初期表示）
- 無効：view（トークンエラー画面）


###### POST /client-portal/setup/{token}

**概要**: 初回設定を保存する（パスワード＋基本情報）。**愛犬（トレーニー）の情報は扱わない**（**2026-10 変更**。愛犬アンケート `POST /client-portal/intake-survey/{token}` で集める。requirements.md 6-15-5）。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| password | string | ● | required, string, confirmed, StrongPassword | 設定するパスワード |
| password_confirmation | string | ● | required | パスワード（確認） |
| last_name | string | ● | required, string, max:50 | 姓 |
| first_name | string | | nullable, string, max:50 | 名 |
| last_name_kana | string | | nullable, string, max:50, regex:ひらがな | せい |
| first_name_kana | string | | nullable, string, max:50, regex:ひらがな | めい |
| phone1 | string | | nullable, string, max:20, regex:/^[0-9\-]+$/ | 電話番号（**2026-10-18 変更**：任意にした。その前は required） |
| phone2 | string | | nullable, string, max:20, regex:/^[0-9\-]+$/ | 緊急連絡先（ご家族等）（**2026-10-17 変更**：その前は電話番号（予備）） |
| emergency_contact_relationship | string | | nullable, string, max:20 | 続柄（**2026-10-17 追加**） |
| postal_code | string |  | nullable, string, regex:/^\d{3}-?\d{4}$/ | 郵便番号（任意。入力された場合のみ形式チェック） |
| address1 | string | | nullable, required_with:address2,address3,address4, string, max:50 | 都道府県（**2026-10-18 変更**：任意。住所のほかの欄が入っていれば必須。その前は required） |
| address2 | string | | nullable, required_with:address1,address3,address4, string, max:50 | 市区町村（同上） |
| address3 | string | | nullable, required_with:address1,address2,address4, string, max:100 | 町名・番地（同上） |
| address4 | string | | nullable, string, max:100 | 建物名・部屋番号 |

**処理**:
- トークンの有効性を再チェック（無効ならエラー画面）
- **初回設定を送信した時点で使用済み化する**（GET では使用済みにしない）
- 以下を1つのトランザクションで実行する：
  - 対象クライアントの password・氏名・連絡先を更新する
  - トークンを使用済み（is_used=true）にする
  - 対応するメールアドレス登録用トークン（`client_email_registration_tokens`）を使用済み（is_used=true）にする — **初回設定完了時に使い切り化**
  - 登録完了メール（`ClientSetupCompletedMail`）を登録アドレス宛に送信する（ログインURLの案内）。**送信はトークン使い切り化と同一トランザクション内で行い、送信失敗時は全ロールバックする**（ログイン用リンクは未使用のまま残るため、お客様は同じURLからやり直せる）
- 対象クライアントで client guard にログインした状態を保つ

**トレーニーの登録・更新に別エンドポイントを設けない理由**: 会員向けにトレーニーを扱うエンドポイントを新設すると、`trainees.*` の `practitioners` ミドルウェアで保護している運用と競合する（トレーナー限定の CRUD を会員側に開放しない方針。requirements.md 6-16 参照）。初回設定の POST 内で処理を完結させることで、既存のトレーナー向けルート・ミドルウェアには一切影響しない

**2026-10 変更**：初回設定から愛犬の情報を外し、愛犬アンケート（`/client-portal/intake-survey/{token}`）に分離した（requirements.md 6-15-5 備考）。上の理由は経緯として残す。愛犬アンケートは、トレーナー向けの `trainees.*` を会員に開放するのではなく、**愛犬アンケート用 URL のトークンでだけ入れる専用のエンドポイント**として設けるため、「トレーナー限定の CRUD を会員側に開放しない」方針は変わらない（`trainees.*` と `practitioners` ミドルウェアには影響しない）

**二重開封時の挙動**:
- 複数の端末・タブでリンクを開いた場合、GET は使用済みにしないため両方でフォームを表示できる
- 先に POST（初回設定送信）した端末が成功しトークンは使用済みになる
- 後から POST した端末は「すでに使用されています」エラーになる（同じ画面のトークンエラー状態を表示）

**レスポンス**:
- 成功：クライアントダッシュボード画面（S-1402）へリダイレクト＋完了メッセージ表示
- トークン無効：トークンエラー画面
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ（screen-design.md §2-7 の規約で返す）
- 登録完了メールの送信失敗（全ロールバック後）：`back()` ＋ 入力項目ではなく専用のキー `form` で `withErrors(['form' => '…'])` で返す（画面設計書 §2-7「入力エラー以外の失敗を入力の項目のキーで返さないルール」。段階 4-2 で `setup` キーから `form` キーに移した）：「マイページの登録を完了できませんでした。時間を置いて再度お試しください。改善しない場合は担当のトレーナーにご連絡ください。」（**2026-10-18 変更**：その前は「初回設定を完了できませんでした。…」）

---

##### S-1404 クライアントトレーニング記録詳細画面

###### GET /client-portal/training-records/{id}

**概要**: クライアントが自分のトレーニング記録の詳細を表示する。

**処理**:
- 対象トレーニング記録が、ログイン中のクライアント自身のもの（記録の client_id がログイン中クライアントと一致）であることを確認する。本人のものでない場合は403
- 記録の詳細（基本情報・ノート）を view に渡す。所感などクライアント非開示の情報は渡さない
- （**2026-10-18**：段階1 の②では、結び付いた愛犬性格診断の結果のタイプとグラフを渡していた。評価文を記録にしなくなったためやめ、S-1418 に移した）
- この記録に紐づくメディアを sort_order 順（昇順）で取得し、各メディアのサムネイルの署名付きURLを発行して view に渡す（閲覧用。再生は `GET /client-portal/media/{id}/play` を利用する）。本人の記録に紐づくメディアであることは記録レベルの本人認可により保証されるため、サムネイル発行に個別の認可は要しない

**レスポンス**:
- view `client.training-records.show`
- 本人の記録でない場合：403

---

##### GET /client-portal/media/{id}/play

**概要**: クライアントが、自分のトレーニング記録に紐づくメディアを表示・再生する。メディアIDを直接受け取るため、記録レベルの認可では守れない。このエンドポイント自身でメディア単位の本人認可を行う。

**処理**:
- 対象メディアが、ログイン中のクライアント自身のトレーニング記録に紐づくこと（メディアが紐づくいずれかの記録の client_id がログイン中クライアントと一致すること）を確認する。紐づかない場合は403
- 認可を通ったら、対象メディアの表示用ファイル（display_path）に対する署名付きGET URL（presigned URL）を発行して返す
- display_path が NULL の場合（変換前・変換中・変換失敗）はエラーを返す

**レスポンス**（JSON）:
- 成功：`{ "data": { "url": <署名付きURL>, "expires_at": <有効期限> } }`
- 認可なし（本人の記録に紐づかない）：403
- display_path が NULL（表示用未生成）：エラーを返す（HTTP 409 等）
- 該当なし：エラーを返す（HTTP 404）

---

##### ログアウト

###### POST /client-portal/logout

**概要**: クライアントとしてログアウトする。

**レスポンス**:
- クライアントログイン画面へリダイレクト

---

##### S-1405 クライアントメールアドレス登録画面

トレーナーから受け取ったメールアドレス登録用 URL からアクセスする、認証不要の公開画面。

###### GET /client-portal/email-registration/{token}

**概要**: メールアドレス登録用 URL のトークンを検証し、メールアドレス登録画面を表示する。

**処理**:
- トークンの有効性を「存在する／期限内／未使用」の順にチェック
- いずれかを満たさない場合はエラー画面を表示

**レスポンス**:
- 有効：view（メールアドレス入力フォーム）
- 無効：view（トークンエラー画面）


###### POST /client-portal/email-registration/{token}

**概要**: 入力されたメールアドレスをクライアントに紐付け、ログイン用リンクを送信する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| email | string | ● | required, email, unique:clients,email,{client_id} | 入力されたメールアドレス。他クライアントで使用されている場合は登録できない |

**処理**:
- トークンの有効性を再チェック（無効ならエラー画面）
- **メールアドレスの重複を確認**（他クライアントで使用されている場合の扱いは下記「重複時の扱い」参照）
- 以下を1つのトランザクションで実行する：
  - 対象クライアントの clients.email を更新する
  - 対応する既存の `client_login_link_tokens` の未使用（is_used=false）トークンを物理削除する（**入力し直しの場合、前に送信したログイン用リンクは無効化**）
  - 新しい `client_login_link_tokens` レコードを 1 件作成する。**`expires_at` は現在のメールアドレス登録用トークン（`client_email_registration_tokens.expires_at`）をそのまま設定する**（発行時点から数え直さない）
  - 入力されたメールアドレス宛にログイン用リンクを送信する
- メール送信の失敗は全ロールバック
- **メールアドレス登録用トークン（`client_email_registration_tokens`）は使い切りにしない**。初回設定完了時（`POST /client-portal/setup/{token}` 成功時）に使い切り化する。それまでは同じ URL から何度でも入力し直せる（別アドレスへの変更にも対応）

**重複時の扱い**:
- 入力されたメールアドレスが既に他のクライアントで使われている場合：
  - 「このメールアドレスは登録できません。」を表示して登録を止める
  - **メールアドレス登録用 URL のトークンは使い切りにしない**（別のアドレスで入力し直せるようにするため）
  - **クライアントの `clients.email` は更新しない**（登録前の状態を保つ）
  - 送信済みのログイン用リンクがあれば無効化はしない（今回の入力は失敗扱いのため、前の状態を維持）

**レスポンス**:
- 成功：同画面の完了状態を表示（入力されたメールアドレスを表示。届かない場合の案内文と「メールアドレス入力に戻る」ボタンを提供）
- トークン無効：トークンエラー画面
- 重複：`back()` ＋ `email.unique` に「このメールアドレスは登録できません。」（入力状態を保持し、URL は引き続き有効）
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ
- メール送信失敗：`back()` ＋ 入力項目ではなく専用のキー `form` で `withErrors(['form' => '…'])` で返す（画面設計書 §2-7「入力エラー以外の失敗を入力の項目のキーで返さないルール」。段階 4-1 で `email` キーから `form` キーに移した）：「メールの送信に失敗しました。時間を置いて再度お試しください。」

---

##### S-1406 クライアント登録情報画面

ログイン中のクライアントが、自分の登録内容（氏名・住所・電話番号・メールアドレス等）を**確認する**画面。あわせて登録情報の変更（S-1411）・パスワード変更（S-1410）・メールアドレス変更（S-1409）への入口ボタンを置く。

###### GET /client-portal/profile

**概要**: 登録情報の確認画面を表示する（登録内容の一覧＋各変更画面への入口ボタン。その下に愛犬の情報の欄。**2026-10-18 追加**）。

**処理**:
- ログイン中のクライアントに紐づく現在の値（氏名・氏名かな・住所・電話番号・メールアドレス）を view に渡す
- 愛犬の情報の欄（**2026-10-18 追加**。requirements.md 6-15-20）：ログイン中のクライアントのトレーニー（`$client->trainees()`。`id` 昇順＝マイページの愛犬のカードと同じ並び）を、最新の愛犬アンケートの回答（`latestIntakeSurveyResponse`）と一緒に読み込んで渡す（eager load。トレーニーの数でクエリが増えない）。ほかのクライアントのトレーニーは出さない

**レスポンス**:
- view `client.settings.show`（登録内容の表示と、3 つの変更画面への入口ボタン、愛犬の情報の欄を含む）
- 直前に S-1417 で保存したときは、レイアウトの共通の受け皿（`session('success')`）に完了メッセージを出す

---

##### S-1411 クライアント登録情報の変更画面

ログイン中のクライアントが、自分の連絡先（電話番号・住所）を変更する独立画面。登録情報（S-1406）の「登録情報を変更」ボタンから開く。フォーム一つだけのシンプルな構成で、旧 S-1406（登録情報設定画面）の変更フォーム部分を移設したもの。

###### GET /client-portal/profile/edit

**概要**: 登録情報の変更フォームを表示する。

**処理**:
- ログイン中のクライアントに紐づく現在の値（氏名・連絡先）を view に渡す

**レスポンス**:
- view `client.settings.edit`（連絡先フォーム）


###### PUT /client-portal/profile

**概要**: 連絡先（電話番号・住所）を更新する。氏名は変更させない。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| phone1 | string | △ | 契約あり：required／契約なし：nullable。string, max:20, regex:/^[0-9\-]+$/ | 電話番号（**2026-10-18 変更**：契約がある会員だけ必須。△ は以下同じ） |
| phone2 | string | | nullable, string, max:20, regex:/^[0-9\-]+$/ | 緊急連絡先（ご家族等）（**2026-10-17 変更**：その前は電話番号（予備）） |
| emergency_contact_relationship | string | | nullable, string, max:20 | 続柄（**2026-10-17 追加**） |
| postal_code | string |  | nullable, string, regex:/^\d{3}-?\d{4}$/ | 郵便番号（任意。入力された場合のみ形式チェック） |
| address1 | string | △ | 契約あり：required／契約なし：nullable, required_with:address2,address3,address4。string, max:50 | 都道府県 |
| address2 | string | △ | 契約あり：required／契約なし：nullable, required_with:address1,address3,address4。string, max:50 | 市区町村 |
| address3 | string | △ | 契約あり：required／契約なし：nullable, required_with:address1,address2,address4。string, max:100 | 町名・番地 |
| address4 | string | | nullable, string, max:100 | 建物名・部屋番号 |

- **住所・電話番号の必須**（**2026-10-18 変更**）：ログイン中の会員に契約（`client_contracts`）が 1 件以上あれば必須、なければ初回設定（`POST /client-portal/setup/{token}`）と同じく任意（`ClientProfileRequest`・`App\Http\Requests\Concerns\ClientContactRules`）。2026-10-18 のユーザーレビューで、マイページの登録の時点ではまだ犬を預かるか決まっていない会員もいるため、住所・電話番号を任意にした。契約が登録された会員は預かりが決まっているので、入れてもらう（登録情報の変更で必須、マイページで入力を促す）。入力エラーの文は screen-design.md S-1411「バリデーションチェック」

**処理**:
- ログイン中クライアントの `clients` の該当カラムを更新する
- 氏名・メールアドレス・パスワードには一切触れない

**レスポンス**:
- 成功：`redirect('/client-portal/profile/edit')` ＋「登録情報を変更しました」（フラッシュメッセージ。S-1411 のフォーム上部に表示）
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ（screen-design.md §2-7 の規約で返す。上部に 1 文の案内と該当する欄の下の文言、入力値は保持）

---

##### S-1409 クライアントメールアドレス変更画面

ログイン中のクライアントが、自分のログイン用メールアドレスを変更する独立画面。登録情報（S-1406）からの入口で開く。フォーム一つだけのシンプルな構成。

###### GET /client-portal/settings/email

**概要**: メールアドレス変更画面を表示する。

**処理**:
- 認可は `auth:client` ミドルウェアで担保する。view には現在値を含めて特に何も渡さない（現在のメールアドレスは S-1406 で見た直後のため画面上に再掲しない。詳細は `screen-design.md` S-1409 備考 / `client-portal-design-plan.md` §4-10 参照）

**レスポンス**:
- view `client.settings.email`（新しいメールアドレス・現在のパスワードのフォームのみ）


###### POST /client-portal/settings/email

**概要**: メールアドレス変更を申し込み、新しいアドレス宛にメールアドレス確認リンクを送信する。**この時点では `clients.email` は書き換えない**。切替は次項 `GET /client-portal/email-change/{token}` で行う。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| new_email | string | ● | required, email, max:255, different:current-email, unique:clients,email,{client_id} | 新しいメールアドレス。現在と同じ場合と、他クライアントで使用中の場合は申し込めない |
| current_password | string | ● | required, current_password (client guard) | 本人確認のため入力する現在のパスワード |

**処理**:
- 現在のパスワードを照合する（一致しない場合はバリデーションエラー）
- 新しいメールアドレスの重複と、現在のアドレスと同じでないことを確認する
- 以下を 1 つのトランザクションで実行する：
  - 同一クライアントの `client_email_change_tokens` のうち未使用のトークンがあれば物理削除する（同時に生きる確認リンクは 1 本に制限）
  - `client_email_change_tokens` にランダムなトークンを 1 件作成する。`new_email` に新しいアドレスを保持、`expires_at` は現在日時から **3 日後**（設定値は `architecture.md` §3-1 の `client_tokens.email_change_confirm_expires_days`）
  - 新しいメールアドレス宛にメールアドレス確認リンクを送信する
- メール送信の失敗は全ロールバック
- **`clients.email` は書き換えない**（確認完了までは古いアドレスのままログイン可能）

**レスポンス**:
- 成功：`redirect('/client-portal/settings/email')` ＋「新しいメールアドレス宛に確認メールを送信しました。メールのリンクを開くとメールアドレスが切り替わります」（S-1409 のフォーム上部に表示）
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ（screen-design.md §2-7 の規約で返す。上部に 1 文の案内と該当する欄の下の文言、入力値は保持）
- 確認メール送信失敗（全ロールバック後）：`back()` ＋ 入力項目ではなく専用のキー `form` で `withErrors(['form' => '…'])` で返す（画面設計書 §2-7「入力エラー以外の失敗を入力の項目のキーで返さないルール」。段階 4-3 追補）：「確認メールを送信できませんでした。時間を置いて再度お試しください。」

**備考**:
- 「メールアドレス確認リンク」はメールアドレスを切り替えるだけの役割で、ログイン用リンク（DS-0600）とは性質が異なる（ログインさせる働きを持たない）
- 他のトークン（メールアドレス登録用 URL・ログイン用リンク）とは無関係に発行されるため、独立した有効期限を持つ


###### GET /client-portal/email-change/{token}

**概要**: メールアドレス確認リンクを開いたときの処理。**専用の画面を持たない**（切替後に S-1401 クライアントログイン画面へリダイレクトする）。ただし、リンクが無効・期限切れ・使用済み・重複の場合は既存の無効トークン画面（`client.setup.invalid-token`）を返す。**ログイン状態を問わず、同じ処理を実行する**（決定事項 #9）。

**処理**:
- トークンの有効性を「存在する／期限内／未使用」の順にチェック
- 有効な場合、以下を 1 つのトランザクションで実行する：
  - **切替の直前に、`client_email_change_tokens.new_email` の値が他クライアントで使われていないかを再チェックする**（申し込みから確認までの間に他クライアントが同じアドレスを登録した可能性があるため）
  - 対象クライアントの `clients.email` を `new_email` に更新する
  - 当該トークンを使用済み（is_used=true）にする
  - **古いメールアドレス宛に通知メール**を送信する（「メールアドレスが変更されました。心当たりがない場合は担当トレーナーにご連絡ください」）
- 通知メール送信の失敗は全ロールバック
- 切替後、`Auth::guard('client')->logout()` でログアウトさせ、クライアントログイン画面（S-1401）へリダイレクトする。ログイン画面には「メールアドレスを変更しました。新しいメールアドレスでログインしてください」を表示する
- **ログイン状態にかかわらず同じ処理**を行う（未ログインからリンクを開いても同じ結果になる。新しいアドレスでログインできることをその場で確かめられるようにするため）

**レスポンス**:
- 有効：`redirect('/client-portal/login')` ＋「メールアドレスを変更しました。新しいメールアドレスでログインしてください」（フラッシュメッセージ）。ログイン中だった場合は同時にログアウトを実行
- 無効：view `client.setup.invalid-token`「このURLは無効です」
- 期限切れ：view `client.setup.invalid-token`「このURLは期限切れです」
- 使用済み：view `client.setup.invalid-token`「このURLは既に使用されています」
- 重複（再チェックで発覚）：view `client.setup.invalid-token`「このメールアドレスは登録できません。」。**トークンは使用済みにしない**（後日、別アドレスで申し込み直せるよう `clients.email` も書き換えない）


---

##### S-1410 クライアントパスワード変更画面

ログイン中のクライアントが、自分のパスワードを変更する独立画面。登録情報（S-1406）からの入口で開く。フォーム一つだけのシンプルな構成。

###### GET /client-portal/settings/password

**概要**: パスワード変更画面を表示する。

**処理**:
- ログイン中のクライアントに紐づく現在のメールアドレス（パスワードマネージャー向けの `username` 用）を view に渡す

**レスポンス**:
- view `client.settings.password`（現在のパスワード・新しいパスワード・新しいパスワード（確認）のフォーム）


###### PUT /client-portal/settings/password

**概要**: パスワードを変更する。**ログアウトさせない**（決定事項 #12）。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| current_password | string | ● | required, current_password (client guard) | 現在のパスワード |
| new_password | string | ● | required, string, confirmed, StrongPassword | 新しいパスワード。S-1403 マイページ登録と同じ強度要件 |
| new_password_confirmation | string | ● | required | 新しいパスワード（確認） |

**処理**:
- 現在のパスワードを照合する（一致しない場合はバリデーションエラー）
- 新しいパスワードの強度を検証する
- ログイン中クライアントの `clients.password` を更新する
- ログイン状態はそのまま維持する（`Auth::guard('client')->logout()` は呼ばない）
- 登録済みのメールアドレス宛に**通知メール**を送信する（「パスワードが変更されました」）
- メール送信の失敗は全ロールバック

**レスポンス**:
- 成功：`redirect('/client-portal/settings/password')` ＋「パスワードを変更しました」（S-1410 のフォーム上部に表示）
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ（screen-design.md §2-7 の規約で返す。上部に 1 文の案内と該当する欄の下の文言、入力値は保持）
- 通知メール送信失敗（全ロールバック後、**パスワードは変わっていない**）：`back()` ＋ 入力項目ではなく専用のキー `form` で `withErrors(['form' => '…'])` で返す（画面設計書 §2-7。段階 4-3 追補）：「パスワードを変更できませんでした。時間を置いて再度お試しください。」（「変わっていない」ことが伝わる文言にする）

---

##### S-1407 クライアントパスワード再設定申し込み画面

パスワードを忘れたお客様が、ログイン画面から遷移してメールアドレスを入力し、パスワード再設定リンクを申し込む。認証不要の公開画面。**登録の有無を画面に出さない**（決定事項 #2 / 6-15-12）。

###### GET /client-portal/password-reset

**概要**: パスワード再設定申し込み画面を表示する。

**処理**:
- 認証状態は問わない（ログイン中でもアクセス可）
- 入力フォームのみを表示する

**レスポンス**:
- view `client.password-reset.request`（メールアドレス入力フォーム）


###### POST /client-portal/password-reset

**概要**: メールアドレスを受け取り、該当が「利用中」なら再設定リンクを送信する。**登録の有無を返さないため、該当しない場合も同じ画面・同じ文言を返す**。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| email | string | ● | required, email | 登録済みのメールアドレスを想定するが、**登録の有無はチェックしない**（露呈させないため） |

**処理**:
- 入力されたメールアドレスを持つクライアントを検索する
- 該当し、かつ状態が**「利用中」**（`clients.email` と `clients.password` の両方が非 NULL）である場合のみ、以下を 1 つのトランザクションで実行する：
  - 対象クライアントの `client_password_reset_tokens` のうち未使用のトークンを物理削除する（**同時に有効な再設定リンクは 1 本のみ**。決定事項 #5）
  - `client_password_reset_tokens` にランダムなトークンを 1 件作成する。`expires_at` は現在日時から **3 日後**（設定値は `architecture.md` §3-1 の `client_tokens.password_reset_expires_days` を参照）
  - 登録アドレス（`clients.email`）宛に**パスワード再設定リンク**メールを送信する
- メール送信の失敗は全ロールバック。**失敗を画面に出さず**、ログに記録したうえで下記の同じ完了画面を返す（段階 4-3 追補。登録の有無を露呈させないため、失敗が出るかどうかで有無を区別できないようにする）。会員はメールが届かなければ、もう一度申請できる
- **該当しない場合（未登録・状態が「利用中」でない）は何もせず**、下記の同じ画面・同じ文言を返す（決定事項 #2・#3）

**レスポンス**:
- 常に：view `client.password-reset.request`（送信後の完了状態、ワイヤーフレーム下段）。文言「ご登録のメールアドレスが確認できた場合は、パスワード再設定のご案内をお送りしました。メールをご確認ください」を表示する（送信失敗時も同じ表示。詳細は上記「処理」の最後）
- バリデーションエラー：`back()` ＋ バリデーションエラーメッセージ（形式不正のみエラーとして返す。「登録がありません」は返さない）

**備考**:
- 「パスワード再設定リンク」はログイン用リンク（DS-0600）・メールアドレス確認リンク（DS-0800）とは性質が異なる（ログインさせる働きは持たない、メールアドレスも変えない）。用途は「新しいパスワードを設定するためのリンク」のみ
- 他のトークンとは無関係に発行されるため、独立した有効期限を持つ


##### S-1408 クライアントパスワード再設定画面

お客様が届いたパスワード再設定リンクを開いて、新しいパスワードを設定する。認証不要の公開画面。

###### GET /client-portal/password-reset/{token}

**概要**: パスワード再設定リンクのトークンを検証し、パスワード再設定画面を表示する。

**処理**:
- トークンの有効性を「存在する／期限内／未使用」の順にチェック
- いずれかを満たさない場合はエラー画面（無効トークン画面）を表示
- 有効な場合、新しいパスワード入力フォームを表示する
- **リンクを開いた時点でお客様をログインさせない**（決定事項 #6 / 6-15-12 の備考）

**レスポンス**:
- 有効：view `client.password-reset.reset`（新しいパスワード入力フォーム）
- 無効：view `client.setup.invalid-token`（既存の無効トークン画面）


###### POST /client-portal/password-reset/{token}

**概要**: 新しいパスワードを保存し、ログイン画面へ遷移する。**そのままログインさせない**（決定事項 #6）。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| new_password | string | ● | required, string, confirmed, StrongPassword | 新しいパスワード。S-1403 マイページ登録と同じ強度要件 |
| new_password_confirmation | string | ● | required | 新しいパスワード（確認） |

**処理**:
- トークンの有効性を再チェック（無効なら無効トークン画面）
- 新しいパスワードの強度を検証する
- 以下を 1 つのトランザクションで実行する：
  - 対象クライアントの `clients.password` を更新する
  - トークンを使用済み（is_used=true）にする
- **完了通知メールは送らない**（決定事項 #7 / 6-15-12 の備考）：再設定リンク自体が登録アドレスに届いており、本人はすでにメールを受け取っている
- 認証状態は変えない。ログイン中だった場合もそのまま維持する（本画面は「未ログインが前提」だが、ログイン中で開いた場合の追加動作は持たない）

**レスポンス**:
- 成功：`redirect('/client-portal/login')` ＋「パスワードを再設定しました。新しいパスワードでログインしてください」（フラッシュメッセージ）
- トークン無効：view `client.setup.invalid-token`（既存の無効トークン画面）
- バリデーションエラー：`back()` ＋ バリデーションエラーメッセージ

---


##### S-1413 愛犬アンケート回答画面

**2026-10-19 変更**：2026-10-19 のユーザーレビューで、名前を「受付アンケート」から「愛犬アンケート」にした（画面・メール・文面など、人の目に触れるものすべて。トレーナー側・会員側とも）。名前は `lang/ja/messages.php` の 1 か所（`intake_survey_name`。愛犬性格診断の `personality_diagnosis_name` と同じ置き方）に置き、画面・文面はそこから出す。URL（`intake-survey`）・ルート名・クラス名・テーブル名・列名・操作の記録のキーは変えない。中身の見出し「暮らしの様子」も変えない。この設計書の「愛犬アンケート」は、2026-10-19 より前は「受付アンケート」と書いていたもの。

お客様がトレーナーから受け取った愛犬アンケート案内（愛犬アンケート用 URL）からアクセスする、認証不要の公開画面（**2026-10 追加**、requirements.md 6-15-17）。ログインはさせない（client guard に触らない）。マイページのアカウントを開設していなくても使える。

**ルートの定義順**：`GET /client-portal/intake-survey/thanks`（S-1414）を `{token}` のルートより**先に**定義する（パスワード再設定の申し込みルートと同じ扱い。`thanks` がトークンとして解釈されないようにするため）。

###### GET /client-portal/intake-survey/{token}

**概要**: 愛犬アンケート用 URL のトークンを検証し、愛犬アンケート回答画面を表示する。

**処理**:
- トークン（`trainee_intake_survey_tokens.token`）を検索し、次の順に確かめる
  1. 存在しない（取消済みを含む。取消は物理削除のため）→「使えない」
  2. 回答済み（`answered_at` が入っている）→「回答済み」
  3. 対象のトレーニーが存在しない → 「使えない」（通常は CASCADE でトークンも消えるため 1. に当たる）
- 有効なら、トレーニーの基本情報（名前・犬種・性別・誕生日の年・月・日・去勢・避妊。未登録の項目は NULL）を、入力欄の初期値として view に渡す（**2026-10 変更**。当初は名前だけを渡していた）。**会員の氏名・連絡先・住所は渡さない**（requirements.md 6-15-17）
- 有効期限の確認はしない（愛犬アンケート用 URL は期限を持たない。architecture.md §3-1）
- **開いただけでは使用済みにしない**（使用済みにするのは POST で回答を保存した時点）

**レスポンス**:
- 有効：view（回答フォーム。愛犬の基本情報の 5 項目〔名前・犬種・性別・誕生日・去勢・避妊〕はトレーニーの値を初期値にした入力欄。入力エラーで戻ったときは `old()` を優先する。説明文には愛犬の名前を使わない。**2026-10 変更**：当初は名前が登録済みなら名前を表示のみ、未登録なら入力欄にしていた）
- 回答済み：愛犬アンケート用の使えない URL の画面に「このアンケートはすでにご回答いただいています。」
- 使えない：同じ画面に「このアンケートはご利用いただけません。お手数ですがトレーナーにお問い合わせください。」
- 使えない URL の画面は、既存の `client.setup.invalid-token` を使い回さず愛犬アンケート用に作る（screen-design.md S-1413「使えない URL の画面」）


###### POST /client-portal/intake-survey/{token}

**概要**: 愛犬アンケートの回答を保存し、回答の基本情報でトレーニーの値を上書きする（空・未選択の項目は変えない。**2026-10 変更**：当初はトレーニーの空欄の項目に反映していた）。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| name | string | ● | required, string, max:50 | 愛犬の名前（**2026-10 変更**：トレーニーの名前の有無にかかわらず必須。当初は、未登録なら required、登録済みなら受け取らない〔▲〕としていた） |
| breed | string | | nullable, string, max:100 | 犬種 |
| sex | string | | nullable, in:male,female,unknown | 性別（男の子／女の子／不明。**2026-10 変更**：`unknown` を加えた。当初は「不明」を置いていなかった） |
| birth_year | string | | nullable（形式・範囲・組み合わせは下の注記のとおり 3 項目まとめて検証する） | 誕生日（年）。`type="text" inputmode="numeric"` の欄から送られる。今年から 30 年前まで |
| birth_month | string | | nullable（同上） | 誕生日（月）。1〜12。入れる場合は年が必須 |
| birth_day | string | | nullable（同上） | 誕生日（日）。入れる場合は年・月が必須 |
| neuter_status | string | | nullable, in:neutered,not_neutered,unknown | 去勢・避妊（している／していない／不明。**2026-10 変更**：当初は `dont_know`〔分からない〕） |
| wish | string | | nullable, string, max:2000 | 自由記述「こうなったらいいな」 |
| recent_events | string | | nullable, string, max:2000 | 自由記述「最近の様子」 |
| ideal_days | string | | nullable, string, max:2000 | 自由記述「理想の毎日」 |
| health_concerns | string | | nullable, string, max:2000 | 自由記述「体のこと」 |

- 選択肢と値はトレーナー側（トレーニー登録・更新）にそろえる（requirements.md 6-15-17 備考。**2026-10 変更**）
- 誕生日の 3 項目の検証（形式・範囲・組み合わせ・実在する日付・未来でない・30 年前まで）は、トレーナー側のトレーニー登録・更新と同じルール・同じ順で行う（下の 4-1-16 参照）。エラーは 1 つのキーにまとめて返す（キー名は実装で決める。screen-design.md §2-7 の但し書き）
- 経緯：当初は、名前が必須かどうかを **POST を処理する時点の**トレーニーの名前で判定していた（画面を開いてから送信するまでの間にトレーナーが名前を登録・削除した場合も、送信時点の状態に従う）。2026-10 に名前を常に必須にしたため、この判定はなくなった

**処理**:
- 以下を 1 つのトランザクションで実行する（同時送信への対策。決定事項 19）
  1. トークン行を**排他ロック**して取得する（`lockForUpdate`）。存在しなければ「使えない」画面
  2. `answered_at` が空であることを確かめる。入っていれば「回答済み」画面（ロックを待っていた 2 回目の送信はここで止まる）
  3. 対象のトレーニーの行を**排他ロック**して取得する。存在しなければ「使えない」画面
  4. 回答（`trainee_intake_survey_responses`）を 1 件保存する。`answered_at` は現在日時。**送られてきた値をそのまま残す**
  5. 回答の基本情報で、トレーニー（`trainees`）の値を**上書きする**（requirements.md 6-15-17「トレーニーへの反映」。**2026-10 変更**）
     - `name`：回答の値で上書き（必須なので必ず入る）
     - `breed` / `sex` / `neuter_status`：回答に値があれば上書き（「不明」〔`unknown`〕も上書き）。空・未選択なら変えない
     - 誕生日：`birth_year`・`birth_month`・`birth_day` をひとまとまりで扱う。3 つとも空なら変えない。どれかが入っていれば、3 つまとめて回答の値で上書き（空の欄は NULL で上書き）
     - 自由記述 4 欄は反映しない
     - 反映で値が変わらなければ、トレーニーを更新しない
     - `trainees.updated_by` は**触らない**（お客様の操作のため。写真の登録・削除と同じ扱い）
     - 画面を開いてから送るまでの間にトレーナーが値を変えても、送信の時点ではお客様の回答を優先する
     - 経緯：当初は、トレーニーの**空欄の項目だけ**に反映していた（`name` / `breed` / `sex` / `neuter_status` はトレーニーの値が NULL のときだけ、トレーナーが「不明」を選んでいれば入力済みとみなして反映しない、回答の `neuter_status = dont_know` は `unknown` として反映、誕生日は 3 項目がすべて NULL のときだけ反映）。また、名前が未登録なのに回答の名前が空なら、ロックのあとに確かめ直して入力エラーにしていた（検証とロックの間にトレーナーが名前を消した場合のため）。名前を常に必須にしたため、確かめ直しは不要になった
  6. トークンの `answered_at` に回答日時を記録する（使い切り）
- **アクセスログには記録しない**（§2-3。回答日時が回答の記録を兼ねる）
- メールは送らない

**同時送信時の挙動**（初回設定の「二重開封時の挙動」にならう）:
- 複数の端末・タブで同じ案内を開いた場合、GET は使用済みにしないため両方で回答画面を表示できる
- 先に処理された POST が回答を保存し、トークンは回答済みになる
- 後から処理された POST は、トークン行のロックが解けるのを待ったあと「回答済み」と判定され、「このアンケートはすでにご回答いただいています。」の画面を返す（回答は保存しない）

**レスポンス**:
- 成功：`redirect()->route('client-portal.intake-survey.thanks')`（PRG。再読み込みで二重送信にならないようにする）
- 回答済み・使えない：GET と同じ使えない URL の画面
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ（screen-design.md §2-7 の規約で返す）

**備考**:
- レート制限は付けない。トークンは `Str::random(32)` で推測が現実的でなく、既存のトークン付き URL（メールアドレス登録用 URL など）の公開ルートにもレート制限は付けていないため、同じ扱いにそろえる


##### S-1414 愛犬アンケート回答完了画面

###### GET /client-portal/intake-survey/thanks

**概要**: 愛犬アンケートの回答を受け付けたことを伝える固定の画面を表示する（**2026-10 追加**）。

**処理**:
- トークンを受け取らず、DB も参照しない。愛犬の名前は出さない（requirements.md 6-15-17 備考。回答後に名前を取り出す手段を持たないため）
- 直接開かれても同じ文面を返す（個人情報を含まないため）

**レスポンス**:
- view（回答完了画面）

##### S-1415 愛犬性格診断回答画面

お客様がトレーナーから受け取った愛犬性格診断の案内（愛犬性格診断用 URL）からアクセスする、認証不要の公開画面（**2026-10 追加**、requirements.md 6-15-18）。ログインはさせない（client guard に触らない）。**愛犬アンケート（S-1413）と同じ作り**で、違いは下に書く。

**ルートの定義順**：`GET /client-portal/personality-diagnosis/thanks`（S-1416）を `{token}` のルートより**先に**定義する（愛犬アンケートと同じ）。

###### GET /client-portal/personality-diagnosis/{token}

**概要**: 愛犬性格診断用 URL のトークンを検証し、愛犬性格診断回答画面を表示する。

**処理**:
- トークン（`trainee_personality_diagnosis_tokens.token`）を検索し、愛犬アンケートと同じ順に確かめる（存在しない → 使えない、回答済み → 回答済み、トレーニーが存在しない → 使えない）
- 有効なら、設問（今の版 `v1`）を view に渡す。**愛犬の名前は渡さない**（説明文は常に「愛犬」。requirements.md 6-15-18）。**会員の氏名・連絡先・住所も渡さない**
- 有効期限の確認はしない（愛犬性格診断用 URL は期限を持たない）
- **開いただけでは使用済みにしない**

**レスポンス**:
- 有効：view（回答フォーム。50 問・全問とも初期値なし。入力エラーや時間切れで戻ったときは `old()` で選んだ選択肢を選んだ状態にする）
- 回答済み：愛犬性格診断用の使えない URL の画面に「この愛犬性格診断はすでにご回答いただいています。」
- 使えない：同じ画面に「この愛犬性格診断はご利用いただけません。お手数ですがトレーナーにお問い合わせください。」

###### POST /client-portal/personality-diagnosis/{token}

**概要**: 愛犬性格診断の回答を採点し、結果（`trainee_personality_diagnosis_results`）を保存する。トレーニーは書き換えない。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| answers | array | ● | required, array | 50 問の回答。キーは `Q01`〜`Q50` |
| answers.Q01〜answers.Q50 | string | ● | required, in:0,1,2,3,4,na | 各問の回答（`na` は「未経験」。db-schema.md 5-25.） |

- 50 問より多いキー（`Q51` など、設問にないキー）は使わない（保存しない）
- 答えていない問があれば、エラーをまとめて返す。上部の案内には未回答の問の数と、最初の未回答の問へのリンクを出す（screen-design.md S-1415「バリデーションチェック」）

**処理**:
- 以下を 1 つのトランザクションで実行する（同時送信への対策。愛犬アンケートと同じ）
  1. トークン行を**排他ロック**して取得する（`lockForUpdate`）。存在しなければ「使えない」画面
  2. `answered_at` が空であることを確かめる。入っていれば「回答済み」画面
  3. 対象のトレーニーが存在することを確かめる。存在しなければ「使えない」画面（トレーニーは書き換えないので、ロックは要らない）
  4. 回答を採点する（requirements.md 6-16「愛犬性格診断の採点とタイプの判定」）：カテゴリごとに「未経験」を除いた平均を出し、**丸める前の値**でタイプを判定してから、平均を小数第2位に丸める
  5. 結果（`trainee_personality_diagnosis_results`）を 1 件保存する。`answered_at` は現在日時、`question_version` は `v1`、`answers` は送られてきたとおり（`Q01`〜`Q50` の 50 件）
  6. トークンの `answered_at` に回答日時を記録する（使い切り）
- **アクセスログには記録しない**（§2-3）
- メールは送らない
- 採点の処理は 1 か所（例：`PersonalityDiagnosisScorer` のようなクラス）にまとめ、突き合わせ用の固定データ（`tests/fixtures/personality-diagnosis-cases.json`）でテストする（requirements.md 6-16「検証の方針」）

**時間切れ（419）の扱い**（**愛犬アンケートと違う**。requirements.md 6-15-18 備考）:
- この POST がセッション切れ（419。CSRF トークンの不一致）になったときは、`bootstrap/app.php` の例外の扱い（愛犬アンケートの 419 と同じ場所）で、**送られてきた回答を入れたまま S-1415 を出し直す**（ステータスは 419 のままにせず 200 で返す。上部に「時間がたったため送信できませんでした。答えはそのまま残っています。もう一度［送信する］を押してください。」を出す）
  - 出し直すときも GET と同じ順でトークンを確かめる。回答済み・使えないときは、それぞれの画面を出す
  - 送られてきた回答は検証せずに選んだ状態にだけ使う（保存しない）。設問にない値・キーは無視する
  - 出し直した画面には新しい CSRF トークンが入るので、お客様はそのまま送り直せる
- 愛犬アンケートの 419（`client-portal/intake-survey/*`。専用の画面を出し、一から入れ直す）は今のまま変えない

**同時送信時の挙動**: 愛犬アンケートと同じ（先に処理された POST だけが結果を保存し、後の POST は「この愛犬性格診断はすでにご回答いただいています。」の画面を返す）

**レスポンス**:
- 成功：`redirect()->route('client-portal.personality-diagnosis.thanks')`（PRG）
- 回答済み・使えない：GET と同じ使えない URL の画面
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ（screen-design.md §2-7 の規約で返す）

**備考**:
- レート制限は付けない（愛犬アンケートと同じ理由）


##### S-1416 愛犬性格診断回答完了画面

###### GET /client-portal/personality-diagnosis/thanks

**概要**: 愛犬性格診断の回答を受け付けたことを伝える固定の画面を表示する（**2026-10 追加**）。

**処理**:
- トークンを受け取らず、DB も参照しない。愛犬の名前も結果も出さない（requirements.md 6-15-18 備考）
- 直接開かれても同じ文面を返す（個人情報を含まないため）

**レスポンス**:
- view（回答完了画面）

---

##### S-1417 クライアントの愛犬の情報の変更画面

ログイン中のクライアントが、自分の愛犬（トレーニー）1 頭の基本情報（名前・犬種・性別・誕生日・去勢・避妊）を変更する独立画面（**2026-10-18 追加**。requirements.md 6-15-20）。登録情報（S-1406）の愛犬のカードの下の［{呼び名}の情報を変更］から開く。

**権限**（2 つのルートで共通）:
- `auth:client`。ログインしていなければログイン画面（S-1401）へ（ほかの会員側のルートと同じ）
- `{trainee}` はルートモデルバインディング。存在しなければ 404。**ログイン中のクライアントのトレーニーでなければ 403**（`trainees.client_id` を照合する。写真の登録・削除〔`/client-portal/trainees/{trainee}/photo`〕と同じ扱い）
- トレーナー側のトレーニーのルート（`trainees.*`）は今のまま会員には開放しない

###### GET /client-portal/trainees/{trainee}/edit

**概要**: 愛犬の情報の変更フォームを表示する。

**処理**:
- トレーニーの今の値（名前・犬種・性別・誕生日の年・月・日・去勢・避妊）を初期値にする。入力エラーで戻ったときは、入力した値（old）だけを使う（愛犬アンケートの回答画面と同じ）

**レスポンス**:
- view `client.trainees.edit`

###### PUT /client-portal/trainees/{trainee}

**概要**: 愛犬の基本情報を更新する。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| name | string | ● | required, string, max:50 | 名前 |
| breed | string | | nullable, string, max:100 | 犬種 |
| sex | string | | nullable, in:male,female,unknown | 性別（男の子／女の子／不明） |
| birth_year | string | | nullable（形式・範囲・組み合わせは 3 項目まとめて検証する） | 誕生日（年） |
| birth_month | string | | nullable（同上） | 誕生日（月） |
| birth_day | string | | nullable（同上） | 誕生日（日） |
| neuter_status | string | | nullable, in:neutered,not_neutered,unknown | 去勢・避妊（している／していない／不明） |

- **愛犬アンケート（`POST /client-portal/intake-survey/{token}`）の基本情報の 7 項目と同じ規則・同じ文言**（規則は 1 か所にまとめて両方で使う。誕生日の検証とエラーのキー `birth_date` も同じ）
- 自由記述（暮らしの様子）・写真は受け取らない（送られてきても無視する）

**処理**:
- 7 項目でトレーニーを更新する（空にした項目は NULL にする。愛犬アンケートの「空なら変えない」とは違う）
- `updated_by`（最終更新者のトレーナー）は変えない（会員の操作のため）
- 操作の記録（`access_logs`）は残さない（会員側の操作は記録しない。requirements.md 6-15-20 備考）

**レスポンス**:
- 成功：`redirect('/client-portal/profile')`（S-1406）＋「{呼び名}の情報を変更しました。」（フラッシュメッセージ `success`。呼び名は変更したあとの名前から作る `Trainee::client_call_name`）
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ（screen-design.md §2-7 の規約。上部に 1 文の案内と該当する欄の下の文言、入力値は保持）

---

##### S-1418 クライアントの愛犬性格診断の評価画面

ログイン中のクライアントが、トレーナーが公開した愛犬性格診断の評価文を、タイプとその時点のレーダーチャートと一緒に見る画面（**2026-10-18 追加**。requirements.md 6-15-21）。マイページ（S-1402）の記録の一覧・最新の記録のカードから開く。

###### GET /client-portal/personality-diagnosis-results/{personalityDiagnosisResult}

**概要**: 公開済みの評価文を表示する（ルート名 `client-portal.personality-diagnosis-results.show`）。

**権限**:
- `auth:client`。ログインしていなければログイン画面（S-1401）へ
- 結果のトレーニーがログイン中のクライアントのトレーニーで、かつ公開済み（`evaluation_published_at` が NULL でない）のときだけ見せる。それ以外（下書き・なし・公開を取り消した・ほかのクライアントのもの・存在しない）は **404**（公開していない評価文があることも分からないように）
- トレーナー側のルート（`trainee-personality-diagnosis-results.*`）は会員に開放しない

**処理**:
- 結果・トレーニー・公開したトレーナーと、トレーニーの結果（新しい順）を読み込み、レーダーチャートの値（`Trainee::personalityRadarDataAsOf`。その結果の時点）を組み立てる
- view にはタイプ・回答日・呼び名・公開した日・公開したトレーナーの名前・評価文・レーダーチャートの値だけを渡す（点数・50問の回答・評価文の状態・更新した人は渡さない）
- 表示はアクセスログに記録しない

**レスポンス**:
- view `client.personality-diagnosis-results.show`
- 見られないとき：404

---

#### 4-1-16. トレーニー管理

トレーニー管理のエンドポイントのうち、愛犬アンケート（第1段階、**2026-10**）・愛犬性格診断（**2026-10 追加**）で変わるもの・新しく設けるものの詳細。その他のトレーニー・計測値のエンドポイントは「3. エンドポイント一覧」を参照。

##### S-0308 トレーニー登録画面 / S-0310 トレーニー編集画面

###### POST /clients/{client}/trainees（登録）・PUT /trainees/{trainee}（更新）

**概要**: トレーニーを登録・更新する。**2026-10 変更**：名前を任意にし、誕生日を年・月・日の 3 項目に分け、去勢・避妊を加えた。登録では愛犬アンケート案内の発行を選べる。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| name | string | | nullable, string, max:50 | 名前（**2026-10 に必須を外した**） |
| breed | string | | nullable, string, max:100 | 犬種 |
| sex | string | | nullable, in:male,female,unknown | 性別 |
| birth_year | string | | nullable（形式・範囲・組み合わせは下の注記のとおり 3 項目まとめて検証する） | 誕生日（年）。`type="text" inputmode="numeric"` の欄から送られる。今年から 30 年前まで |
| birth_month | string | | nullable（同上） | 誕生日（月）。1〜12。入れる場合は年が必須 |
| birth_day | string | | nullable（同上） | 誕生日（日）。入れる場合は年・月が必須 |
| neuter_status | string | | nullable, in:neutered,not_neutered,unknown | 去勢・避妊（している／していない／不明） |

- 誕生日の 3 項目は、FormRequest の `after()` などで次の順に検証し、最初に当てはまった 1 つだけを、1 つにまとめたキーで返す（文言は screen-design.md S-0308「バリデーションチェック」）：数字であること → 月は 1〜12 → 月があるなら年がある → 日があるなら年・月がある → 3 つそろったら実在する日付 → 未来でない（年だけなら今年以前、年・月までなら今月以前、年・月・日なら今日以前）→ 年は今年から 30 年前まで
- 同じルールを、入力中の年齢表示（JS）でも使う（requirements.md 6-16「年齢の表示規則」）

**処理**:
- 登録では愛犬アンケート案内を発行しない（**2026-10 変更**。案内の発行は S-0305 の［愛犬アンケートの案内を発行］〔`POST /trainees/{trainee}/intake-survey-tokens`〕だけで行う。requirements.md 6-16-1）。ほかのパラメータ（以前の `issue_intake_survey`、**2026-10 になくした備考の `note`** など）が送られてきても使わない

**レスポンス**:
- 登録成功：`redirect('/clients/{client}')`（S-0305）。完了メッセージは「トレーニーを登録しました。」
- 更新成功：従来どおり
- バリデーション失敗：`back()` ＋ バリデーションエラーメッセージ


##### S-0305 会員詳細画面

###### POST /trainees/{trainee}/intake-survey-tokens

**概要**: 愛犬アンケート案内を発行する（愛犬アンケート用 URL のトークンを 1 件作成する。requirements.md 6-16-8）。

**リクエスト**: パラメータなし（**2026-10 変更**：当初は `return_to` を受け取っていた。§3 の「愛犬アンケート案内エンドポイントのリダイレクト先」参照）

**処理**:
- 以下を 1 つのトランザクションで実行する
  - 対象トレーニーの行を**排他ロック**する（同じトレーニーへの同時の発行で、有効な案内が 2 つできないようにするため。MySQL では「`answered_at` が NULL の行は 1 つまで」という条件付きのユニーク制約を書けないので、アプリ側で守る。db-schema.md DS-1000）
  - 状態が「未発行」であることを確かめる（回答がなく、`answered_at` が NULL のトークンもない）。当てはまらない場合は発行しない（下の「レスポンス」）
  - `Str::random(32)` でトークンを生成し、`trainee_intake_survey_tokens` に 1 件作成する（`created_by` は操作したトレーナー）。有効期限は持たない
- **発行し直しはしない**（未回答のトークンがあれば発行できない。マイページ登録案内の発行と違い、既存のトークンを消して作り直す処理は持たない。requirements.md 6-16-8 備考）

**レスポンス**:
- 成功：`redirect()->route('clients.show', $trainee->client_id)` — **発行のあとは S-0305 会員詳細画面に戻る**（**2026-10-17 変更**：当初は S-0311 を開き、完了メッセージは載せなかった。理由は requirements.md 6-3-6 備考「発行のあとの戻り先」）。完了メッセージ「{表示名}の愛犬アンケートの案内を発行しました。」をフラッシュ `success` に載せる（表示名は `Trainee::display_name`。名前がなければ「名前未登録」。マイページ登録案内の発行と同じ出し方）。戻る位置はページの上（`#…` は付けない）
- 有効な案内がすでにある（状態が「回答待ち」。二重送信・古い画面から押し直したなど）：新しく発行せず、完了メッセージも載せずに `redirect()->route('clients.show', $trainee->client_id)`
- 回答済み：409（今までどおり。UI 側でボタンを出し分けているため通常は到達しない）


###### DELETE /trainees/{trainee}/intake-survey-tokens

**概要**: 愛犬アンケート案内を取り消す（requirements.md 6-16-9）。対象トレーニーの未回答のトークンを物理削除する。

**パス選定の理由**: 発行と同じリソース URL に対する DELETE とする（マイページ登録案内の `DELETE /clients/{client}/email-registration-tokens` と同じ考え方）。

**リクエスト**:

パラメータなし（**2026-10 変更**：当初は `return_to` を受け取っていた）

**処理**:
- 対象トレーニーの状態が「回答待ち」（回答がなく、`answered_at` が NULL のトークンがある）であることを確かめる。当てはまらない場合は 409
- `answered_at` が NULL のトークンを物理削除する。**回答済みのトークンと回答には触らない**

**レスポンス**:
- 成功：S-0305 へリダイレクトし、フラッシュ `success` に「愛犬アンケートの案内を取り消しました。」（発行と同じ。**2026-10 変更**：当初は `return_to` に従っていた）
- 状態が「回答待ち」でない：409
- 押下時に確認モーダルを挟む（screen-design.md「愛犬アンケート案内の取消モーダル」）


###### GET /trainees/{trainee}/intake-survey-tokens/print

**概要**: 対象トレーニーの有効な愛犬アンケート用 URL を、QR コード・URL・手順を載せた案内として表示する（S-0311）。

**処理**:
- 対象トレーニーの `trainee_intake_survey_tokens` のうち `answered_at` が NULL のトークン 1 件を取得する（有効期限は見ない）
- 有効なトークンがあれば、URL・QR コード用データを view に渡す。**愛犬の名前は使わない**（案内の文と文面は常に「愛犬」。screen-design.md S-0311。**2026-10 変更**：当初は愛犬の名前〔未登録なら NULL〕も渡していた）
- **メール／LINE 送付用の案内文面もコントローラで組み立て、view に渡す**（マイページ登録案内と同じ。文面と決め事は screen-design.md S-0311「案内文面のコピー」）。事業者名（`config('app.client_portal_company')`）が未設定なら、事業者名の行は文面に含めない
- **お客様の氏名は view に渡さない**（マイページ登録案内と同じ理由）

**レスポンス**:
- 有効なトークンあり：view（`layouts.print` を使う。S-0307 と同じ構え）
- 有効なトークンなし（回答済み・取消済み・未発行のいずれでも）：同じ view で「有効な愛犬アンケート案内がありません。」を出す。QR・URL・コピー文面は出さない

###### POST /trainees/{trainee}/personality-diagnosis-tokens

**概要**: 愛犬性格診断の案内を発行する（愛犬性格診断用 URL のトークンを 1 件作成する。requirements.md 6-16-11。**2026-10 追加**）。**愛犬アンケート案内の発行（`POST /trainees/{trainee}/intake-survey-tokens`）と同じ作り**で、違いは「回答があっても発行できる」こと。

**リクエスト**: パラメータなし

**処理**:
- 以下を 1 つのトランザクションで実行する
  - 対象トレーニーの行を**排他ロック**する（同じトレーニーへの同時の発行で、有効な案内が 2 つできないようにするため。db-schema.md DS-1100）
  - 有効な案内（`answered_at` が NULL のトークン）がないことを確かめる。あれば発行しない（下の「レスポンス」）
  - `Str::random(32)` でトークンを生成し、`trainee_personality_diagnosis_tokens` に 1 件作成する（`created_by` は操作したトレーナー）。有効期限は持たない
- **前の結果（`trainee_personality_diagnosis_results`）の有無は見ない**（何回でも受けられるため。愛犬アンケートの「回答済みなら 409」に当たる扱いはない）

**レスポンス**:
- 成功：`redirect()->route('clients.show', $trainee->client_id)`（S-0305。**2026-10-17 変更**：当初は S-0312 を開き、完了メッセージは載せなかった）。完了メッセージ「{表示名}の愛犬性格診断の案内を発行しました。」をフラッシュ `success` に載せる（愛犬アンケート案内と同じ）
- 有効な案内がすでにある（二重送信・古い画面から押し直したなど）：新しく発行せず、完了メッセージも載せずに S-0305 に戻る

###### DELETE /trainees/{trainee}/personality-diagnosis-tokens

**概要**: 愛犬性格診断の案内を取り消す（requirements.md 6-16-12。**2026-10 追加**）。対象トレーニーの未回答のトークンを物理削除する。

**リクエスト**: パラメータなし

**処理**:
- 対象トレーニーに有効な案内（`answered_at` が NULL のトークン）があることを確かめる。なければ 409
- `answered_at` が NULL のトークンを物理削除する。**回答済みのトークンと結果には触らない**

**レスポンス**:
- 成功：S-0305 へリダイレクトし、フラッシュ `success` に「愛犬性格診断の案内を取り消しました。」
- 有効な案内がない：409
- 押下時に確認モーダルを挟む（screen-design.md S-0305-M07）

###### DELETE /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}

**概要**: 愛犬性格診断の結果を 1 件削除する（requirements.md 6-16-14。**2026-10 追加**）。

**リクエスト**: パラメータなし

**処理**:
- `{personalityDiagnosisResult}` は `{trainee}` の結果に限る（スコープ付きのルートモデルバインディング。別のトレーニーの結果なら 404）。引数名は、スコープ付きのバインディングで結果をトレーニーの関連 `personalityDiagnosisResults()` から探すため、関連の名前に合わせる（**2026-10 変更**：当初は `{result}` と書いていた）
- 管理者でなければ 403（トレーニーの削除 `DELETE /trainees/{trainee}` と同じ確かめ方・同じ文言「管理者のみ削除できます。」。システム管理者は、トレーニー管理のルートの `practitioners` ミドルウェアで 403）
- その結果（`trainee_personality_diagnosis_results` の行）を物理削除する。結果に使った案内（回答済みのトークン）は消さない
- 公開済みの評価文も結果と一緒に消える（会員様の画面からも消える。**2026-10-18 変更**：その前〔段階1 の②〕は、結び付いたトレーニング記録を残し、結び付きだけを NULL にしていた）
- アクセスログに記録する（`delete_trainee_personality_diagnosis_result`。`target_type='Trainee'`、`target_id` はトレーニーの id）

**レスポンス**:
- 成功：`redirect()->route('trainees.show', $trainee)`（S-0309。フラグメントは付けない）。フラッシュ `success` に「愛犬性格診断の結果を削除しました。」
- 管理者でない：403
- 別のトレーニーの結果・存在しない結果：404
- 押下時に確認モーダルを挟む（screen-design.md S-0313-M02。**2026-10-18 変更**：当初は S-0309-M01。送信元は愛犬性格診断詳細 S-0313 の［削除］）

###### GET /trainees/{trainee}/personality-diagnosis-tokens/print

**概要**: 対象トレーニーの有効な愛犬性格診断用 URL を、QR コード・URL・手順を載せた案内として表示する（S-0312。**2026-10 追加**）。

**処理**:
- 対象トレーニーの `trainee_personality_diagnosis_tokens` のうち `answered_at` が NULL のトークン 1 件を取得する
- 有効なトークンがあれば、URL・QR コード用データと、メール／LINE 送付用の案内文面（コントローラで組み立てる。screen-design.md S-0312「案内文面のコピー」）を view に渡す。**お客様の氏名・愛犬の名前は view に渡さない**（愛犬アンケートと同じ）
- 表示はアクセスログに記録しない

**レスポンス**:
- 有効なトークンあり：view（`layouts.print` を使う。S-0311 と同じ構え）
- 有効なトークンなし（未発行・取消済み・前の案内に回答済みのいずれでも）：同じ view で「有効な愛犬性格診断の案内がありません。」を出す。QR・URL・コピー文面は出さない

---

##### S-0313 愛犬性格診断詳細画面

**2026-10-18 追加**（requirements.md 6-16-15・6-16-16）。愛犬性格診断の結果 1 件の詳細と、評価文の保存・公開・公開取消。**2026-10-18 変更**：2026-10-18 のユーザーレビューで、評価文と記録の本文の二重持ちをやめるため、公開でトレーニング記録を作らず、結果の行に公開した日時・トレーナーを持たせる形にした（その前〔段階1 の②〕は公開で記録を作っていた）。

**共通**（下の 4 つのルートと、上の DELETE）:
- `{personalityDiagnosisResult}` は `{trainee}` の結果に限る（`scopeBindings`。別のトレーニーの結果・存在しない結果は 404）。DELETE と同じ
- トレーニー管理のルートと同じく `practitioners` ミドルウェアの中に置く（一般・管理者。システム管理者は 403）

###### GET /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}

**概要**: 愛犬性格診断詳細画面を表示する（ルート名 `trainee-personality-diagnosis-results.show`）。

**処理**:
- 結果と、最後に更新したトレーナー・公開したトレーナーを取得する
- レーダーチャートの値：この結果を今回、同じトレーニーの結果のうちこの結果より前のもの（並べる基準は今と同じ `answered_at` の降順・同じ日時なら `id` の降順）の 1 つ目を前回、いちばん古いものを初回にする（S-0309 の今回・前回・初回の決まりを、この結果の時点にあてはめる）
- 評価文の状態（なし・下書き・公開済み）を決めて view に渡す
- 表示はアクセスログに記録しない

**レスポンス**:
- view `trainees.personality-diagnosis-results.show`

###### PUT /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}

**概要**: 評価文を保存する（ルート名 `trainee-personality-diagnosis-results.update`）。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| evaluation_text | string |  | nullable, string, max:10000 | 評価文。空なら NULL で保存する（「なし」に戻る）。改行は LF にそろえる（愛犬アンケートの自由記述と同じ） |

**処理**:
- 公開済み（`evaluation_published_at` が NULL でない）でも保存できる（そのまま会員様の画面に出る）。ただし**公開済みのときは空にできない**：空（空白だけを含む）なら入力エラー「公開中の評価文は空にできません。公開を取り消してから消してください。」（キー `evaluation_text`）（**2026-10-18 変更**：その前は公開済みなら 409）
- `evaluation_text`・`evaluation_updated_by`（ログイン中のトレーナー）・`evaluation_updated_at`（今）を保存する
- アクセスログに記録する（`edit_trainee_personality_diagnosis_evaluation`。`target_type='Trainee'`、`target_id` はトレーニーの id）

**レスポンス**:
- 成功：`redirect()->route('trainee-personality-diagnosis-results.show', [$trainee, $result])`。フラッシュ `success` に「評価文を更新しました。」（**2026-10-18 変更**：その前は「評価文を保存しました。」。画面のボタンも［保存］から［更新］にした）。公開済みのときは「評価文を更新しました。会員様のマイページにも反映されています。」（**2026-10-18 追加**）
- バリデーション失敗：`back()`（screen-design.md §2-7）

###### POST /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}/publish

**概要**: 評価文を保存して公開する（ルート名 `trainee-personality-diagnosis-results.publish`）。**トレーニング記録は作らない**（**2026-10-18 変更**：その前〔段階1 の②〕は、評価文を本文にしたトレーニング記録を作り、`training_records.trainee_personality_diagnosis_result_id` で結び付けていた）。

**リクエスト**:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| evaluation_text | string | ● | required, string, max:10000 | 評価文（画面の欄に書いてある文。保存していない直しも含めて公開する） |

**処理**（1 つのトランザクション）:
1. 結果の行を排他ロックして取り直す（`lockForUpdate`。同時に 2 回押されたときに困らないため）
2. すでに公開済み（`evaluation_published_at` が NULL でない）なら何もしない（下のレスポンス）。入力のチェックより先に見る
3. 評価文を保存する（PUT と同じ。`evaluation_updated_by`・`evaluation_updated_at` も）
4. `evaluation_published_at`＝今、`evaluation_published_by`＝ログイン中のトレーナー を入れる
- アクセスログに記録する（`publish_trainee_personality_diagnosis_evaluation`。`target_type='Trainee'`、`target_id` はトレーニーの id。**2026-10-18 変更**：その前は `create_training_record`）

**レスポンス**:
- 成功：`redirect()->route('trainee-personality-diagnosis-results.show', [$trainee, $result])`。フラッシュ `success` に「評価文を公開しました。」
- すでに公開済み：同じ S-0313 へ。フラッシュ `success` に「この評価文は、すでに公開しています。」（エラーにしない）
- バリデーション失敗（評価文が空など）：`back()`
- 押下時に確認モーダルを挟む（screen-design.md S-0313-M01）

###### POST /trainees/{trainee}/personality-diagnosis-results/{personalityDiagnosisResult}/unpublish

**概要**: 評価文の公開取消（下書きに戻す。ルート名 `trainee-personality-diagnosis-results.unpublish`。**2026-10-18 追加**）。

**リクエスト**: パラメータなし（欄に保存していない直しがあっても保存しない）

**処理**（1 つのトランザクション）:
1. 結果の行を排他ロックして取り直す
2. 公開済みでなければ何もしない（下のレスポンス）
3. `evaluation_published_at`・`evaluation_published_by` を NULL に戻す（評価文は残す）
- アクセスログに記録する（`unpublish_trainee_personality_diagnosis_evaluation`。`target_type='Trainee'`、`target_id` はトレーニーの id）

**レスポンス**:
- 成功：S-0313 へ。フラッシュ `success` に「愛犬性格診断の公開を取り消しました。」（名前は `personality_diagnosis_name`。**2026-10-18 変更**：2026-10-18 のユーザーレビューで、［公開をやめる］を［公開取消］にし、ほかの案内の取消とそろえた。その前は「評価文の公開をやめました。」）
- すでに公開を取り消している：同じ S-0313 へ。フラッシュ `success` に「この評価文は公開していません。」（エラーにしない）
- 押下時に確認モーダルを挟む（screen-design.md S-0313-M03）
- ルートを `DELETE …/publish` にしないのは、評価文を消す操作と取り違えないため（公開の印を外すだけ）

**段階2（予定。requirements.md 6-16-16 備考）**: 回答の保存のあと、キューで AI に評価文の下書きとスタッフ向けのメモを作らせ、評価文を `evaluation_text` に入れる（状態は下書き）。スタッフ向けのメモの置き場所・ジョブ・指示文の置き場所・失敗したときの扱いは、そのときに決める（音声の要約〔SummarizeJob〕と同じ作りにする想定。**2026-10-18 変更**：その前は「公開のときは、スタッフ向けのメモを記録の `impression` に入れる」としていたが、公開で記録を作らなくなったため）

---

### 4-2. 内部API（routes/api.php）

画面のJavaScriptがAjaxで呼び出すエンドポイント。`routes/api.php` に定義し、`/api` プレフィックスが付く。
レスポンスはJSON（データ）。
画面と同じセッション認証を共有するため `web` ミドルウェアグループを適用し、認証を必須とする。


#### 4-2-1. クライアント

##### GET /api/clients/search

**概要**: クライアントを検索する内部API。トレーニング記録の作成・編集フォームなどで、クライアントを検索して選択（Select2）するために使用する。

**リクエスト**（クエリパラメータ）:
| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| q | string | | — | 検索キーワード。管理番号、クライアントの氏名・かなで部分一致検索する |
| id | integer | | — | クライアントID。指定したクライアント1件を返す（入力エラー後に選択状態を復元するために使用する） |

**処理**:
- `q` での検索結果を、管理番号順に最大20件返す
- `id` が指定された場合は、そのクライアント1件を返す（`q` は無視される）

**レスポンス**（JSON）:
- `{ "results": [ ... ] }`

| フィールド | 型 | 説明 |
|-----------|-----|------|
| id | integer | クライアントID |
| text | string | 管理番号と氏名 |

---


#### 4-2-2. トレーニング記録

##### POST /api/training-records/auto-create

**概要**: 音声記録の要約からトレーニング記録を作成する内部API。

**2026-10**：録音実行の画面からは呼ばれなくなった（`POST /api/audio-records/{id}/auto-create-training-record` に置き換えた）。記録の中身を作る処理は `TrainingRecordAutoCreateService` に取り出し、この API と、ひとつながりの最後のジョブ（`CreateTrainingRecordFromAudioJob`）の両方から使う。API は今は残している。

**リクエスト**（JSON）:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| audio_record_id | integer | ● | required, exists:audio_records,id | 元の音声記録ID |
| client_id | integer | ● | required, exists:clients,id | クライアントID |
| training_date | date | ● | required, date | 日付 |
| training_time | time | | nullable, date_format:H:i | 時刻 |
| trainer1_id | integer | ● | required, exists:trainers,id | 担当1 |
| trainer2_id | integer | | nullable, exists:trainers,id, different:trainer1_id | 担当2（担当1と異なること） |

**処理**:
- 音声記録の要約テキストをトレーニング記録の記録内容に取り込み、トレーニング記録を作成する

**レスポンス**（JSON）:
- 成功：`{ "success": true, "training_record_id": <作成したトレーニング記録のID> }`
- 失敗：`{ "success": false }`

##### GET /api/training-records/available-media

**概要**: トレーニング記録に紐づけ可能な（まだ紐づいていない）メディアの一覧を取得する内部API。編集画面（S-0404）のメディア追加モーダル（S-0404-M01）および登録画面（S-0401）のメディア追加モーダル（S-0401-M02）で使用する。

**リクエスト**（クエリパラメータ）:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| training_record_id | integer | | nullable, exists:training_records,id | 指定時、その記録に紐づけ済みのメディアを候補から除外する。未指定（登録画面）時は除外しない |
| trainer_id | integer | | nullable, exists:trainers,id | 登録者で絞り込む。未指定時はログイン中のトレーナー、「全員」選択時は全件 |
| page | integer | | nullable, integer, min:1 | ページ番号 |

**処理**:
- training_record_id が指定された場合、その記録に**まだ紐づいていない**メディアのみを候補とする（紐づけ済みは除外する）。未指定（登録画面）の場合は除外しない
- 登録者で絞り込む（デフォルトはログイン中のトレーナー）
- 登録日時の新しい順に並べ、最大表示件数（24件）を超える場合はページネーションする
- **日付グループ見出しの挿入は表示側（モーダルの JS）で行い、レスポンスの JSON 形状（`data: [ ... ]`）は変えない**（既存フロントとの互換性を保つ。将来「サーバ側で `groupBy` すればよい」と誤解しないこと。`data` を `[{date, items[]}]` の入れ子にすると既存の `forEach` ループが壊れる）

**レスポンス**（JSON）:
- 成功：`{ "data": [ <メディア>, ... ], "meta": { <ページネーション情報> } }`
- training_record_id 指定時に該当記録が無い場合：エラーを返す（HTTP 404）

---


#### 4-2-3. 音声記録

##### POST /api/audio-records/{id}/transcribe

**概要**: 音声記録の文字起こしを実行する内部API。

**処理**:
- 状態を「文字起こし中」（transcribing）にしてから、文字起こしのジョブ（TranscribeAudioJob）を並び `audio` に渡す。ジョブはキューの設定（`QUEUE_CONNECTION`）に従って動く：本番は `database`（音声用のワーカーが後ろで動かす）、開発はふだん `sync`（その場で動く）（**2026-10 変更**。以前は `dispatchSync` でその場で動かしていた）
- ジョブは 1 回だけ試す（`$tries = 1`）。失敗したとき（ワーカーの時間切れを含む）は、状態を「エラー」にする
- ジョブを渡したら、**その時点の状態をそのまま返す**：sync なら transcribed（失敗は HTTP 500）、キューなら transcribing。transcribing のときは、呼び出し側が `GET /api/audio-records/{id}/status` を問い合わせて終わりを待つ

**レスポンス**（JSON）:
- 成功：`{ "data": { ... } }`（HTTP 200）
- 処理できない状態の場合：エラーを返す（HTTP 409）
- APIキーが設定されていない場合：エラーを返す（HTTP 400）
- 文字起こしに失敗した場合（sync のとき）：エラーを返す（HTTP 500）

成功時の `data`：`GET /api/audio-records/{id}/status` の `data` に、次を足したもの。

| フィールド | 型 | 説明 |
|-----------|-----|------|
| message | string | 「文字起こしを受け付けました。」（transcribing のとき）／「文字起こしが完了しました。」（終わっているとき） |


##### POST /api/audio-records/{id}/summarize

**概要**: 音声記録の要約を実行する内部API。

**処理**:
- 状態を「要約中」（summarizing）にしてから、要約のジョブ（SummarizeJob）を並び `audio` に渡す。動き方・応答の考え方は transcribe と同じ（**2026-10 変更**）：sync なら completed、キューなら summarizing を返す
- 要約の元は、DB に保存済みの文字起こしテキスト（リクエストで本文は受け取らない。画面で未保存の変更がある場合は、先に `PUT /audio-records/{id}` で保存してから呼ぶ）

**レスポンス**（JSON）:
- 成功：`{ "data": { ... } }`（HTTP 200）
- 要約できない状態の場合：エラーを返す（HTTP 409）
- APIキーが設定されていない場合：エラーを返す（HTTP 400）
- 要約に失敗した場合（sync のとき）：エラーを返す（HTTP 500）

成功時の `data`：`GET /api/audio-records/{id}/status` の `data` に、次を足したもの。

| フィールド | 型 | 説明 |
|-----------|-----|------|
| message | string | 「要約を受け付けました。」（summarizing のとき）／「要約が完了しました。」（終わっているとき） |


##### GET /api/audio-records/{id}/status

**概要**: 音声記録の文字起こし・要約の状態を返す内部API（**2026-10 追加**）。音声記録一覧（S-0505）が、transcribe・summarize の応答が処理中（キューで処理中）だったときに、3 秒ごとに問い合わせて終わりを待つために使う。

**処理**:
- 対象の音声記録の状態を読んで返す（何も変えない）
- 権限の確かめ方は、ほかの音声記録の内部API（transcribe・summarize）と同じ（認証・トレーナーの権限・IP 制限）

**レスポンス**（JSON）:
- 成功：`{ "data": { ... } }`（HTTP 200）
- 該当なし：エラーを返す（HTTP 404）

| フィールド | 型 | 説明 |
|-----------|-----|------|
| id | integer | 音声記録ID |
| status | string | 処理状態（unprocessed / transcribing / transcribed / summarizing / completed / error） |
| status_label | string | 処理状態の表示名 |
| is_processing | boolean | 処理中（文字起こし中・要約中）か |
| is_error | boolean | エラーか |
| is_stalled | boolean | 処理中のまま 30 分以上たって、止まったとみなすか（`AudioRecord::PROCESSING_STALL_MINUTES`） |


##### POST /api/audio-records/{id}/auto-create-training-record

**概要**: 録音実行（S-0502）のトレーニング記録作成モーダル（S-0502-M01）の「作成する」から呼ぶ内部API（**2026-10 追加**）。文字起こし → 要約 → トレーニング記録の作成を、ひとつながりで受け付けて、すぐ返す。

**リクエスト**（JSON）：

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| client_id | integer | ● | required, exists:clients,id | クライアントID |
| training_date | date | ● | required, date | 日付 |
| training_time | time | | nullable, date_format:H:i | 時刻 |
| trainer1_id | integer | ● | required, exists:trainers,id | 担当1 |
| trainer2_id | integer | | nullable, exists:trainers,id, different:trainer1_id | 担当2（担当1と異なること） |

（規則と文言は `POST /api/training-records/auto-create` と同じ。音声記録は URL の `{id}` で指定する）

**処理**:
- 入力を検証する（HTTP 422。文言は `lang/ja/validation.php` の `custom.trainer1_id.required`・`custom.trainer2_id.different` など。画面側の確認と同じ文言）
- 文字起こしを始められるか（音声ファイルがある・処理中でない。30 分以上たって止まったとみなす処理中は可）を確かめる。始められないときは受け付けない（HTTP 409）
- OpenAI・Anthropic の API キーが設定されているかを確かめる（HTTP 400）
- 状態を「文字起こし中」にし、次の 3 つのジョブをひとつながり（`Bus::chain`）で並び `audio` に渡す
  1. 文字起こし（TranscribeAudioJob）：終わったら「文字起こし済み」
  2. 要約（SummarizeJob。ひとつながりの 2 番目として、始めに「要約中」にする）：終わったら「要約完了」
  3. トレーニング記録の作成（CreateTrainingRecordFromAudioJob）：要約が完了しているときだけ、要約を記録内容にしてトレーニング記録を作る（中身は `TrainingRecordAutoCreateService`。`POST /api/training-records/auto-create` と同じ）
- 途中で失敗したら、次のジョブは動かない（`Bus::chain` の標準の動き）。文字起こし・要約の失敗では、状態を「エラー」にする。どのジョブも 1 回だけ試す
- sync のときは、3 つとも終わってから応答が返る

**レスポンス**（JSON）:
- 受け付け：`{ "success": true, "data": <状態> }`（HTTP 202。`<状態>` は `GET /api/audio-records/{id}/status` と同じ形。キューなら transcribing、sync なら終わった状態）
- 入力エラー：HTTP 422（Laravel 標準の `{message, errors}`）
- 始められない状態：`{ "error": { "message": <文言> } }`（HTTP 409）
- API キーが未設定：`{ "error": { "message": <文言> } }`（HTTP 400）
- 失敗（sync のとき）・ジョブを渡せなかったとき：`{ "success": false, "message": <文言> }`（HTTP 500）


##### GET /api/audio-records/{id}/summary

**概要**: 指定した音声記録の要約テキストを返す内部API。録音実行画面からトレーニング記録の作成画面に遷移する際、対象の音声記録の要約をトレーニング記録の記録内容に取り込むために使用する。

**レスポンス**（JSON）:
- `{ "summary_text": ... }`

| フィールド | 型 | 説明 |
|-----------|-----|------|
| summary_text | string | 要約テキスト |


##### GET /api/audio-records/summaries

**概要**: トレーニング記録の作成・編集フォームで、音声記録の要約を取り込むために、候補となる音声記録の一覧を返す内部API。

**リクエスト**（クエリパラメータ）:
| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| client_id | integer | ● | required, exists:clients,id | クライアントID。指定したクライアントに紐づく音声記録のみを返す |
| search | string | | — | タイトルで部分一致検索する |

**処理**:
- ログイン中のトレーナーが所有し、指定したクライアントに紐づき、処理が完了して要約済みの音声記録を、作成日の降順で返す

**レスポンス**（JSON）:
- `{ "success": true, "data": [ ... ] }`
- `data` の各要素：

| フィールド | 型 | 説明 |
|-----------|-----|------|
| id | integer | 音声記録ID |
| client_id | integer | クライアントID |
| title | string | タイトル |
| source | string | 音声ソース種別 |
| file_name | string\|null | ファイル名（テキスト貼り付けの場合はnull） |
| created_at | datetime | 作成日時 |
| summary_text | string | 要約テキスト |

---


#### 4-2-4. トレーナー

##### GET /api/trainers

**概要**: 担当トレーナーの選択候補を返す内部API。録音実行画面で担当トレーナーを選択するために使用する。

**処理**:
- システム管理者を除くトレーナーを表示順に返す

**レスポンス**（JSON）:
- `[ ... ]`

| フィールド | 型 | 説明 |
|-----------|-----|------|
| id | integer | トレーナーID |
| name | string | 氏名 |

---


#### 4-2-5. メディア

##### POST /api/media-records/upload-url

**概要**: メディアアップロード用の署名付きURL（presigned PUT URL）を発行する内部API。メディア登録モーダル（S-1302-M02）で登録ボタン押下後、実ファイルのアップロードに先立って呼び出す。

**リクエスト**（JSON）:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| original_filename | string | ● | required, string, max:255 | アップロードするファイルの元ファイル名 |
| mime_type | string |  | nullable, string | ファイルのMIMEタイプ。クライアントから受け取るが採用しない（ブラウザの file.type は heic 等で空文字や image/heif になるばらつきがあり信頼できないため、サーバは original_filename の拡張子から決定する） |
| file_size | integer | ● | required, integer, min:1 | ファイルサイズ（バイト） |

**処理**:
- original_filename の拡張子から正規のMIMEタイプを決定する。許可形式（写真 jpeg/png/heic/heif、動画 mp4/mov）以外は拒否する
- 決定したMIMEタイプから種別（photo / video）を判定する
- file_size を種別ごとの上限（写真20MB、動画1GB）と照合し、超過は拒否する
- 保存キー（オブジェクトストレージ上のパス）を採番し、そのキーに対する署名付きPUT URLを発行する
- ※申請値（original_filename・file_size）に基づく事前検証であり、実ファイルの検証は別途行う（直アップロードのためサーバを経由しない）

**レスポンス**（JSON）:
- 成功：`{ "data": { ... } }`

成功時の `data`：

| フィールド | 型 | 説明 |
|-----------|-----|------|
| upload_url | string | 署名付きPUT URL（このURLへブラウザから直接アップロードする） |
| storage_key | string | 採番された保存キー。レコード作成時に渡す |

- 入力エラー・形式／サイズの不適合：Laravel 標準の HTTP 422（`{message, errors: {キー: [文言…]}}`、キーは `original_filename` / `file_size` など。段階 5-3b で `{"error": ...}` の独自形式から揃えた）。文言は `lang/ja/validation.php` の `custom.original_filename.format`・`custom.file_size.max`（`:limit` プレースホルダ）など

##### POST /api/media-records

**概要**: アップロード完了後、メディアレコードを作成する内部API。ブラウザが署名付きURLへのアップロードを完了した後に呼び出す。

**リクエスト**（JSON）:

| パラメータ | 型 | 必須 | バリデーション | 説明 |
|-----------|-----|------|---------------|------|
| storage_key | string | ● | required, string | 署名付きURL発行時に採番された保存キー |
| original_filename | string | ● | required, string, max:255 | 元ファイル名 |
| mime_type | string |  | nullable, string | MIMEタイプ。クライアントから受け取るが採用しない（upload-url と同じ理由。サーバは original_filename の拡張子から決定する） |
| file_size | integer | ● | required, integer, min:1 | ファイルサイズ（バイト） |
| title | string | | nullable, string, max:255 | 表示名。未入力時は元ファイル名を表示 |

**処理**:
- storage_key の指すファイルを original_path として、media_records レコードを作成する
- original_filename の拡張子から正規のMIMEタイプを決定し、許可形式以外は拒否する
- 形式に応じて conversion_status と display_path を設定する：
  - 変換不要な形式（jpeg / png / mp4）：conversion_status を not_required とし、display_path に original_path と同じ値をセットする（原本がそのまま表示用）
  - 変換が必要な形式（heic / mov）：conversion_status を pending とし、display_path は NULL のままとする（変換は別途 convert API で起動する）
- 決定したMIMEタイプから種別（photo / video）を確定する
- 決定したMIMEタイプを mime_type カラムに保存する
- 登録者は、ログイン中のトレーナーを設定する
  
**レスポンス**（JSON）:
- 成功：`{ "data": <メディア> }`（201）
- 入力エラー・形式／保存キー不適合：Laravel 標準の HTTP 422（`{message, errors: {キー: [文言…]}}`、キーは `storage_key` / `original_filename` / `file_size` / `title` など。段階 5-3b で `{"error": ...}` の独自形式から揃えた）。文言は `lang/ja/validation.php` の `custom.original_filename.format`・`custom.storage_key.format` など

##### GET /api/media-records/{id}/play

**概要**: メディアを表示・再生する内部API（ストリーミング）。メディア一覧の詳細モーダルのほか、将来的にトレーニング記録への紐付け・共有ビューなど複数の画面から横断的に利用するため、画面に依存しない内部APIとして配置する。

**処理**:
- 対象メディアの表示用ファイル（display_path）に対する署名付きGET URL（presigned URL）を発行して返す
- display_path が NULL の場合（変換前・変換中・変換失敗）は、表示用ファイルが未生成のため表示・再生できない。この場合はエラー（または未生成を示すレスポンス）を返し、呼び出し側は conversion_status に応じて「変換中」「変換失敗」を表示する

**レスポンス**（JSON）:
- 成功：`{ "data": { "url": <署名付きURL>, "expires_at": <有効期限> } }`
- display_path が NULL（表示用未生成）：エラーを返す（HTTP 409 等）
- 該当なし：エラーを返す（HTTP 404）

##### POST /api/media-records/{id}/convert

**概要**: メディアの表示用変換（heic→jpeg / mov→mp4）を起動する内部API。メディア登録モーダル（S-1302-M02）で、レコード作成（store）後に conversion_status が pending（変換が必要）の場合に呼び出す。変換不要な形式では呼び出さない。

**処理**:
- 対象メディアの conversion_status が pending であることを確認する（既に処理中・完了・変換不要の場合は何もしないか、対象外として扱う）
- conversion_status を processing にしてから、変換ジョブ（ConvertMediaJob）を渡す。ジョブはキューの設定（`QUEUE_CONNECTION`）に従って動く：本番は `database`（supervisor で常駐させたワーカーが後ろで動かす。アプリ構築手順書 第10段階）、開発はふだん `sync`（その場で動く）（**2026-10 変更**）
- ジョブ内で、原本（original_path）をストレージから取得 → 変換（写真は ImageMagick で heic→jpeg、動画は FFmpeg で mov→mp4）→ 変換後ファイルをストレージに保存 → display_path にパスをセット → conversion_status を done に更新する
- 変換中は conversion_status を processing、失敗時は error とする
- ジョブは 1 回だけ試す（`$tries = 1`。変換の失敗はくり返しても直らないことが多いため）。時間の上限は 600 秒（`$timeout`）。ワーカーの時間切れなど、ジョブの中で例外を受けられない失敗でも、`failed()` で conversion_status を error にする（**2026-10 変更**）
- ジョブを渡したら、**その時点の状態をそのまま返す**（**2026-10 変更**。以前は同期実行が前提で、応答の時点で完了していた）：sync なら done（失敗は HTTP 500）、キューなら processing。processing のときは、呼び出し側が `GET /api/media-records/{id}/status` を問い合わせて終わりを待つ

**レスポンス**（JSON）:
- 成功（変換起動）：`{ "data": <状態> }`（HTTP 200。`<状態>` の形は `GET /api/media-records/{id}/status` と同じ）
- conversion_status が pending でない場合：エラーを返す（HTTP 409。`{ "error": <文言>, "conversion_status": <状態> }`）
- 変換に失敗した場合（sync のとき）・ジョブを渡せなかった場合：エラーを返す（HTTP 500。`{ "error": "表示用変換に失敗しました。", "media_record_id": <ID> }`）
- 該当なし：エラーを返す（HTTP 404）

##### POST /api/media-records/{id}/generate-thumbnail

**概要**: メディアのサムネイル（200×200）の生成を起動する内部API。メディア登録モーダル（S-1302-M02）で、レコード作成（store）と表示用変換（必要な場合）のあとに、thumbnail_status が pending の場合に呼び出す。

**処理**:
- 対象メディアの thumbnail_status が pending であることを確認する
- thumbnail_status を processing にしてから、サムネイル生成ジョブ（GenerateThumbnailJob）を渡す。ジョブの動き方（キューの設定に従う・1 回だけ試す・時間の上限 600 秒・`failed()` で error にする）は convert と同じ（**2026-10 変更**）
- ジョブ内で、原本からサムネイルを作ってストレージに保存 → thumbnail_path にパスをセット → thumbnail_status を done に更新する。失敗時は error とする
- ジョブを渡したら、その時点の状態をそのまま返す（convert と同じ。**2026-10 変更**）

**レスポンス**（JSON）:
- 成功（生成起動）：`{ "data": <状態> }`（HTTP 200。`<状態>` の形は `GET /api/media-records/{id}/status` と同じ。done なら `thumbnail_url` が入る）
- thumbnail_status が pending でない場合：エラーを返す（HTTP 409。`{ "error": <文言>, "thumbnail_status": <状態> }`）
- 生成に失敗した場合（sync のとき）・ジョブを渡せなかった場合：エラーを返す（HTTP 500。`{ "error": "サムネイル生成に失敗しました。", "media_record_id": <ID> }`）
- 該当なし：エラーを返す（HTTP 404）

##### GET /api/media-records/{id}/status

**概要**: メディアの表示用変換・サムネイル生成の状態を返す内部API（**2026-10 追加**）。メディア登録モーダル（S-1302-M02）が、convert・generate-thumbnail の応答が processing（キューで処理中）だったときに、一定の間隔で問い合わせて終わりを待つために使う。

**処理**:
- 対象メディアの状態を読んで返す（何も変えない）
- 権限の確かめ方は、ほかのメディアの内部API（convert・generate-thumbnail・play）と同じ（認証・トレーナーの権限・IP 制限）

**レスポンス**（JSON）:
- 成功：`{ "data": <状態> }`（HTTP 200）
- 該当なし：エラーを返す（HTTP 404）

`<状態>`（convert・generate-thumbnail の成功の応答と同じ形）：メディアのレコードの列（`id`・`type`・`conversion_status`・`thumbnail_status`・`display_path`・`thumbnail_path` など）に、次を足したもの。

| フィールド | 型 | 説明 |
|-----------|-----|------|
| conversion_status | string | 表示用変換の状態（not_required / pending / processing / done / error） |
| thumbnail_status | string | サムネイル生成の状態（pending / processing / done / error） |
| thumbnail_url | string \| null | サムネイルの表示用 URL（署名付き。thumbnail_status が done のときだけ。それ以外は null） |
| display_title | string | 表示名（title が空なら元ファイル名） |
| is_processing | boolean | 変換かサムネイルのどちらかが処理中（processing）か |
| has_error | boolean | 変換かサムネイルのどちらかがエラー（error）か |