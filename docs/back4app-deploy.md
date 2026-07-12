# 1G1A Back4App への公開

## 現時点の方針

1G1A は Back4App Containers を使って公開します。

Back4App の公開ドキュメント上、データベースの主力は MongoDB 系ですが、1G1A は PostgreSQL を使いたいので、DB は別の管理サービスを用意する前提です。

## デプロイ方針

- GitHub の公開リポジトリを使う
- Dockerfile を用意する
- コンテナ内で PHP を起動する
- 環境変数は Back4App 側に設定する
- DB は外部 PostgreSQL の接続文字列を渡す

## 確認事項

- PHP が起動すること
- PostgreSQL に接続できること
- Google OAuth の redirect URI が一致していること
- 共有ページが HTTPS で閲覧できること

## 公開前チェック

- `.env` がコミットされていない
- テストデータが残っていない
- ログに秘密情報が出ていない
- 共有 URL が予測されにくい
