# 1G1A 開発ログ

## 2026-07-11

### 実施したこと

- 依頼文を読み取り、1G1A の立ち上げ指示として整理した
- 現在のディレクトリ、Git 状態、GitHub CLI の有無を確認した
- Back4App の公開情報を確認し、Containers 前提が妥当と判断した
- 初期ドキュメント群を作成した

### 判断したこと

- バックエンドは PHP
- DB は PostgreSQL
- 公開は Back4App Containers
- BaaS の主力 DB は使わず、PostgreSQL は外部管理にする

### 次にやること

- PHP プロジェクトの最小実装を作る
- Dockerfile を用意する
- PostgreSQL 接続を組む
- Google OAuth の実装を始める

