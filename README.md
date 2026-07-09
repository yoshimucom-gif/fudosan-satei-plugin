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

## 自動更新の仕組み（非公開GitHub＋トークン）

1. 各サイトのプラグイン設定に、GitHubトークン（Contents読み取り専用）を1回入力
2. WPが定期的に GitHub Contents API 経由で `update.json` を認証取得
3. インストール済みバージョンより新しければ「更新可能」バッジを表示
4. 「更新」クリック → 同リポジトリの `fudosan-satei.zip` を認証付きで取得・展開

owner/repo はプラグイン内の定数（`FS_GH_OWNER` / `FS_GH_REPO`）で固定。トークンは
コードに含めず各サイトのDBに保存するため、**リポジトリは非公開のまま運用できる**。
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

1. `fudosan-satei/fudosan-satei.php` の `FS_GH_OWNER` を、ミカタのGitHub
   アカウント/組織名に変更（`update.json` の homepage も合わせる）
2. このフォルダを GitHub の**非公開**リポジトリ `fudosan-satei-plugin` として push
3. GitHubで **Fine-grained personal access token** を発行
   - リポジトリ: 対象の `fudosan-satei-plugin` のみ
   - 権限: `Contents: Read-only` のみ
4. 各サイトに `fudosan-satei.zip` を一度だけ手動インストール＆有効化
5. 各サイトの「設定 → 匿名不動産AI査定 → GitHubトークン」に 3 のトークンを入力
6. 以降はコードを直して `py build.py <ver> "変更点"` → `git push` で自動更新

## 注意

- リポジトリは**非公開**。更新取得は GitHub Contents API をトークン認証で叩く。
  トークンはコードに含めず各サイトのWP管理画面（DB）に保存する。
- APIキー等の秘密情報もコードに含めず各サイトのWP設定に保存。
- 詳しい導入・法的注意は `fudosan-satei/readme.txt` を参照。
