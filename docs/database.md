# 1G1A DB設計

## 方針

- PostgreSQL を使う
- SNS 情報は JSON ではなくテーブルで管理する
- アカウントプロフィールと共有プロフィールを分ける
- 1ユーザーは共有プロフィールを複数持てる
- QR 表示1回を `share_events` として保存する
- 通信できない場面に備えて、共有イベント用トークンを事前予約できるようにする
- ゲスト名は Cookie の `viewer_token` と DB の `guest_visitors` を対応させる
- IPアドレスは生値では保存せず、必要な場合はハッシュ化する

## テーブル

### users

ログインユーザーと、アカウントに1つだけある基本プロフィールを保存します。

- `id`
- `google_sub`
- `email`
- `name`
- `avatar_url`
- `account_display_name`
- `account_bio`
- `role`
- `created_at`
- `updated_at`

### profiles

ゲストへ公開する共有プロフィールです。1ユーザーが複数持てます。

- `id`
- `user_id`
- `public_token`
- `profile_name`
- `display_name`
- `avatar_url`
- `headline`
- `bio`
- `is_public`
- `created_at`
- `updated_at`

補足:

- `profile_name` は管理用の名前です。例: `仕事用`, `旅先用`
- `display_name` はゲスト画面に表示する名前です
- `public_token` は公開URLに使う推測困難な文字列です

### sns_types

SNS/連絡先種類のマスタです。

SNSだけでなく、Webサイト、メール、電話、技術プロフィール、コミュニティなど、ゲストに見せたい連絡先サービスを管理します。
固定のPHP配列ではなく、このマスタを元にプロフィール編集画面とゲスト表示を組み立てます。

既存カラム:

- `id`
- `code`
- `name`
- `icon_url`
- `sort_order`

追加予定カラム:

- `display_name`: ゲスト画面や追加モーダルで表示するサービス名。例: `Instagram`, `メール`
- `category`: 表示カテゴリ。例: `standard`, `activity`, `work`, `developer`, `community`, `contact`
- `input_kind`: 入力値の種類。例: `handle`, `url`, `email`, `phone`, `text`
- `url_template`: リンク先URLを生成するテンプレート。例: `https://instagram.com/{value}`, `mailto:{value}`
- `copy_template`: コピー用文字列のテンプレート。例: `@{value}`, `{value}`
- `placeholder`: 編集画面の入力例
- `help_text`: 入力補足
- `is_active`: 新規追加候補として表示するか
- `created_at`
- `updated_at`

初期マスタ候補:

- 標準: LINE, Instagram, X, note, Webサイト, メール
- 活動・発信: YouTube, TikTok, Threads, Bluesky, Facebook, Pinterest
- 仕事・技術: LinkedIn, Wantedly, Qiita, Zenn, GitHub, Speaker Deck, connpass
- コミュニティ: Discord, Twitch, Reddit, Mastodon
- その他: 電話, Other

中国圏だけで主に使われるサービスは初期マスタには含めません。
例: WeChat, Weibo, QQ, Douyin, Kuaishou, Xiaohongshu など。

### profile_sns

共有プロフィールごとの SNS・Webサイト・連絡先リンクです。

- `id`
- `profile_id`
- `sns_type_id`
- `label`
- `url`
- `raw_value`: ユーザーが入力した元の値。コピー表示と編集フォームの復元に使う。
- `sort_order`
- `is_primary`
- `created_at`
- `updated_at`

`url` には、ユーザーが入力した値から生成した最終リンクURLを保存します。
`raw_value` には、コピー表示や編集フォーム復元に使う入力元の値を保存します。

ゲスト表示では、`sns_types` の `icon_url`, `display_name`, `copy_template`, `url_template` と組み合わせて、アイコングリッドとアクションメニューを表示します。

### share_events

ユーザーが QR を表示した1回分の共有イベントです。今回のコメント、位置情報、QR表示日時を保存します。

- `id`
- `profile_id`
- `public_token`
- `body`
- `latitude`
- `longitude`
- `location_accuracy_m`
- `location_captured_at`
- `created_at`
- `updated_at`

補足:

- 公開URLは将来的に `/s/{public_token}` を基本にする
- 既存の `/p/{profiles.public_token}` はプロフィール単体のプレビューまたは互換用として扱える
- 位置情報が拒否された場合、位置情報カラムは `NULL` のままでよい
- オフライン作成後に同期されたイベントは、端末側で先に使った `public_token` をそのまま登録する

### reserved_share_tokens

通信しにくい場所でも QR を表示できるよう、オンライン時に事前予約しておく共有イベント用トークンです。

- `id`
- `user_id`
- `profile_id`
- `public_token`
- `status`
- `reserved_at`
- `used_at`
- `expires_at`

`status`:

- `available`: 端末でまだ使っていない
- `used`: 共有イベントとして使った
- `expired`: 期限切れ

補足:

- QR 作成時は、端末に保存済みの `available` トークンを使う
- 同期時に `share_events.public_token` として登録する
- 未同期の状態でゲストが `/s/{public_token}` にアクセスした場合、準備中ページを表示する

### share_event_photos

共有イベントに添付された写真です。

- `id`
- `share_event_id`
- `storage_path`
- `mime_type`
- `file_size`
- `width`
- `height`
- `created_at`

### guest_visitors

QR からアクセスしたゲストの名前と Cookie トークンを保存します。

- `id`
- `viewer_token`
- `display_name`
- `first_seen_at`
- `last_seen_at`

### share_access_logs

公開ページの閲覧ログです。

- `id`
- `share_event_id`
- `profile_id`
- `viewer_token`
- `access_ip_hash`
- `user_agent`
- `accessed_at`

### client_sync_items

端末内の未同期データをサーバ側でも追跡する場合の補助テーブルです。MVPではサーバ側に持たず、端末の IndexedDB のみでもよいです。

- `id`
- `user_id`
- `client_item_id`
- `item_type`
- `status`
- `last_error`
- `created_at`
- `synced_at`

### allowed_users

将来の限定公開用です。MVPでは必須ではありません。

- `id`
- `profile_id`
- `user_id`
- `created_at`

### invitations

将来の招待制共有用です。MVPでは必須ではありません。

- `id`
- `profile_id`
- `email`
- `token`
- `expires_at`
- `accepted_at`
- `created_at`

## 既存テーブルからの移行方針

現在の実装にある `posts` / `post_photos` / `post_access_logs` は、意味としては共有イベントに近いです。下位モデルで実装する場合は、次のどちらかを選びます。

1. `posts` を残し、`share_events` として扱うためにカラムを追加する
2. `share_events` / `share_event_photos` / `share_access_logs` を新設し、画面側を新テーブルへ移す

推奨は 2 です。理由は「プロフィールの投稿」ではなく「QR表示1回の出会い」を表すため、名前を分けた方が今後の設計が読みやすいからです。

## Cookie

ゲスト向け Cookie:

- `1g1a_guest_token`
- `1g1a_guest_name`

保存期間:

- 1年程度

セキュリティ:

- `HttpOnly`
- `SameSite=Lax`
- HTTPS環境では `Secure`

## 端末内保存

オフライン・低通信環境では、サーバに送信できないデータを端末内に一時保存します。

推奨:

- IndexedDB に `pending_share_events` を持つ
- コメント、位置情報、写真 Blob、予約済み `public_token`、作成日時を保存する
- 通信復帰時に順番にアップロードする
- アップロード完了後に端末内データを削除、または同期済みとして保持する
