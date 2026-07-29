# English Diary Calendar

日本語の日記を書いて、OpenAI APIで英訳・学習メモ・単語メモを作る自分用Webアプリです。

## セットアップ

1. `.env.example`を参考に環境変数を設定します。

PowerShell:

```powershell
$env:OPENAI_API_KEY="sk-your-api-key"
$env:OPENAI_MODEL="gpt-5.2"
```

2. サーバーを起動します。

```powershell
npm start
```

3. ブラウザで開きます。

```text
http://localhost:3000
```

## 機能

- 月間カレンダー
- 日付ごとの日本語日記保存
- OpenAI APIによる自然な英訳、カジュアル表現、直訳寄り表現
- 学習メモと単語・表現の自動生成
- データは`data/entries.json`に保存

## Webサービス化するとき

- APIキーは必ずサーバー側の環境変数に置きます。
- 自分だけで使う場合も、公開するならログイン機能を追加してください。
- VercelやRenderに載せる場合は、`OPENAI_API_KEY`をホスティング側の環境変数に設定します。

公開版では日記データをブラウザの`localStorage`に保存します。別端末でも同じデータを見たい場合は、Supabaseなどのデータベースを追加してください。
