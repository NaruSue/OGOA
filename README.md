# 1G1A

1G1A は、偶然出会った相手にその場で QR コードを渡し、あとから共有ページを閲覧してもらうための Web サービスです。

このリポジトリでは、まず最初の開発土台として以下を整えます。

- 目的と要件の整理
- 技術構成の方針
- ディレクトリ構成
- DB 設計のたたき台
- Google OAuth を使う認証方針
- Back4App への公開方針
- 開発ログ

## 現時点の方針

- バックエンド: PHP
- DB: PostgreSQL
- 実行環境: Back4App Containers
- フロントエンド: HTML / CSS / JavaScript
- 認証: Google OAuth
- 将来対応: PWA

## ドキュメント

- [要件](docs/requirements.md)
- [アーキテクチャ](docs/architecture.md)
- [DB 設計](docs/database.md)
- [ローカル開発](docs/setup-local.md)
- [Google OAuth](docs/google-oauth.md)
- [Back4App への公開](docs/back4app-deploy.md)
- [開発ログ](docs/development-log.md)

## 参考

- Back4App Home: https://www.back4app.com/
- Back4App Docs: https://www.back4app.com/docs

