# かんたん不動産AI査定 — WordPressプラグイン

匿名の不動産価格査定フォーム。国交省「不動産情報ライブラリ」の実成約事例から
参考価格レンジを算出し、結果をメール送信＋リード保存する。ショートコード
`[fudosan_satei]` をページに貼るだけ。**GitHub経由の自動更新に対応**。

## リポジトリ構成

```
fudosan-satei-wp/
├── fudosan-satei/            ← プラグイン本体（この中身がzipになる）
│   ├── fudosan-satei.php     ← メイン（フォーム/査定/メール/AJAX/設定/リード）
│   └── includes/
│       └── plugin-updater.php ← 自動更新チェッカー
├── fudosan-satei.zip         ← 配布zip（download_url が指すファイル）
├── update.json               ← 最新バージョン情報（WPが参照）
└── build.py                  ← バージョン更新＋zip再生成
```

## 自動更新の仕組み

1. WPが定期的に `update.json` を見に来る
2. インストール済みバージョンより新しければ「更新可能」バッジを表示
3. ユーザーが「更新」をクリック → `download_url`（= このリポジトリの `fudosan-satei.zip`）を取得・展開

`download_url` と更新チェック先はプラグイン内の raw URL に固定済みなので、
**このリポジトリに push するだけで全サイトに更新が配られる**（リリース作成不要）。

## 更新を出す手順（これだけ）

```bash
# 1. バージョンを上げて zip を再生成
py build.py 1.0.1 "・査定精度を改善 ・○○を修正"

# 2. push（= 全サイトに更新通知）
git add -A
git commit -m "v1.0.1"
git push
```

各サイトでは「プラグイン」画面に更新バッジが出る（WPのチェック間隔により最大12時間ほど。
すぐ試すなら「更新」→「更新を確認」）。

## 初回セットアップ

1. このフォルダを GitHub の**公開**リポジトリ `fudosan-satei-plugin` として push
   （リポジトリ名/オーナーを変える場合は、`fudosan-satei/fudosan-satei.php` の
   `FS_UPDATE_URL` と `update.json` の `download_url` の URL を合わせて修正）
2. 各サイトに `fudosan-satei.zip` を一度だけ手動インストール＆有効化
3. 以降はコード側を直して `build.py` → `git push` で自動更新

## 注意

- 更新チェック先・zip は raw.githubusercontent.com（公開リポジトリ）を利用。
  プラグインのコードは公開されるが、APIキー等の秘密情報はコードに含めず
  各サイトのWP管理画面（DB）に保存するため問題ない。
- 詳しい導入・法的注意は `fudosan-satei/readme.txt` を参照。
