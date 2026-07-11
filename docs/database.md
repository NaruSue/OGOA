# 1G1A DB 設計

## 方針

- PostgreSQL を使用する
- SNS 情報は JSON ではなく別テーブルで管理する
- 個人情報は最小限にする
- 公開 URL と内部 ID を分ける

## テーブル案

### users

- id
- google_sub
- email
- name
- avatar_url
- role
- created_at
- updated_at

### profiles

- id
- user_id
- slug
- display_name
- bio
- headline
- is_public
- created_at
- updated_at

### sns_types

- id
- code
- name
- icon_url
- sort_order

### profile_sns

- id
- profile_id
- sns_type_id
- label
- url
- sort_order
- is_primary
- created_at
- updated_at

### posts

- id
- profile_id
- body
- created_at
- updated_at

### post_photos

- id
- post_id
- storage_path
- mime_type
- file_size
- width
- height
- created_at

### post_access_logs

- id
- profile_id
- viewer_token
- access_ip_hash
- user_agent
- accessed_at

### allowed_users

- id
- profile_id
- user_id
- created_at

### invitations

- id
- profile_id
- email
- token
- expires_at
- accepted_at
- created_at

## 補足

- `profiles.slug` は公開 URL に使う
- `google_sub` は Google の一意 ID として使う
- `viewer_token` は閲覧者の追跡用だが、個人を特定しすぎない
- `access_ip_hash` は生 IP を保存しない前提で設計する

