# 1G1A Google OAuth

## 目的

Google アカウントを使って、1G1A の利用者を認証します。

## 方式

- OAuth 2.0 を使う
- 取得するのは主に `sub`、`email`、`name`、`picture`
- 認証完了後は自前のセッションでログイン状態を持つ

## フロー

1. `/auth/google` から Google に遷移する
2. Google 側で認証する
3. `/auth/google/callback` に戻る
4. `sub` を基準に `users` を検索する
5. 既存ユーザーならログイン、新規なら作成する

## 注意点

- `email` だけを主キーにしない
- `state` を必ず検証する
- トークンをログに出さない
- Cookie は `HttpOnly` と `SameSite` を設定する
