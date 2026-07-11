# 1G1A アーキテクチャ

## 採用技術

- Backend: PHP 8.3 系
- Frontend: HTML / CSS / JavaScript
- Database: PostgreSQL
- Hosting: Back4App Containers
- Auth: Google OAuth
- QR: サーバー側で生成し、将来的に PWA 対応

## Back4App の判断

2026-07-11 時点の Back4App 公式情報では、Back4App の主要なデータベース製品は MongoDB ベースで、Web デプロイは GitHub 連携で提供されています。

一方で、PHP 系アプリを動かすには Containers の利用が現実的です。1G1A では、Back4App の BaaS 機能に寄せすぎず、Containers 上で PHP を動かし、PostgreSQL は外部の管理 DB を使う方針を採ります。

## ディレクトリ構成案

```text
1G1A/
├── public/
├── app/
│   ├── Controllers/
│   ├── Services/
│   └── Models/
├── config/
├── database/
│   ├── migrations/
│   └── seeders/
├── docs/
├── resources/
│   ├── css/
│   ├── js/
│   └── views/
├── storage/
└── tests/
```

## 画面構成

- `/` 共有ページの入口
- `/login` ログイン画面
- `/auth/google` Google 認証開始
- `/auth/google/callback` Google 認証戻り先
- `/dashboard` 自分の管理画面
- `/profiles` プロフィール一覧
- `/profiles/new` プロフィール作成
- `/profiles/{id}` プロフィール詳細
- `/profiles/{id}/edit` プロフィール編集
- `/profiles/{id}/qr` QR コード表示
- `/p/{slug}` 公開共有ページ

## 実装の考え方

- まずは 1 サービス 1 画面ではなく、管理画面と公開画面を分ける
- 認証済みユーザーだけが編集できる
- 公開ページは最低限の情報だけ出す
- QR の中身は長寿命 URL にする

