# 1G1A ローカル開発

## 必要環境

- PHP 8.3 以上
- Composer
- PostgreSQL 16 以上
- Git
- Docker Desktop か、ローカル PostgreSQL

## 初期セットアップ

1. リポジトリをクローンする
2. `.env.example` を `.env` にコピーする
3. `APP_SECRET` と OAuth 設定を埋める
4. PostgreSQL に `1g1a` 用 DB を作る
5. マイグレーションを実行する
6. ローカルサーバーを起動する

## 開発時の注意

- `.env` はコミットしない
- テスト用データと本番用データを混ぜない
- 共有 URL の動作確認を必ず行う
- QR から開いた場合の導線を最初に確認する

