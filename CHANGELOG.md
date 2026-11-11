# Changelog

All notable changes to `holyat/filament-jp-postal` are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-11-11

### 日本語

最初のリリースです。

#### 追加

- `PostalCodeInput` フォームフィールド
  - 7 桁そろった時点での自動検索（同じ郵便番号では再検索しない）と「住所検索」ボタン。
  - `fillsAddress()` で都道府県・都道府県コード・市区町村・町域、または市区町村＋町域を 1 項目に入力。フィールドからの相対パスなので Repeater の中でも動作。
  - 1 件ならすべて入力、複数件なら共通の都道府県・市区町村だけを入力、0 件なら直前の検索で自動入力したフィールドを空にする（利用者が書き換えたものは残す）。
  - `overwrite()`、`storeWithHyphen()`、`autoLookup()`、`lookupAction()`、フィールドごとの `driver()`。
  - マスクを使わないクライアント側の整形で全角数字・IME 入力に対応し、表示は常に `100-0001`。
- `PrefectureSelect` フォームフィールド：JIS 順の 47 都道府県、値は名前または JIS コード（`codes()`）、常に文字列で保存。
- `JpPostal` ファサードと `JpPostalManager`：`PostalCodeDriver` 契約、入力の正規化、結果のキャッシュ、`extend()` による独自ドライバ。
- `zipcloud` ドライバ（初期値）：タイムアウト、1 回の再試行、カナの全角変換。
- `database` ドライバと `jp-postal:import` コマンド：日本郵便の `utf_ken_all` データを取り込み（ダウンロード、`--file`、`--url`、`--force`）。一時テーブルに書き込んでから入れ替え。
- 町域の正規化規則：2026 年 10 月更新のデータ全 124,527 行で検証。
- 翻訳：日本語・英語・韓国語。

### English

First release.

#### Added

- `PostalCodeInput` form field
  - Automatic lookup once 7 digits are entered (not repeated for the same postal code) and a 「住所検索」 lookup action.
  - `fillsAddress()` for prefecture, prefecture code, city, town, or city and town in one field, using paths relative to the field so it works inside a Repeater.
  - One result fills every field; several results fill only the shared prefecture and city; no result clears the fields filled by the previous lookup unless the user changed them.
  - `overwrite()`, `storeWithHyphen()`, `autoLookup()`, `lookupAction()` and a per-field `driver()`.
  - Client-side formatting that accepts full-width digits and IME input without a mask; always displayed as `100-0001`.
- `PrefectureSelect` form field with the 47 prefectures in JIS order, by name or by JIS code (`codes()`), always stored as strings.
- `JpPostal` facade and `JpPostalManager` with a `PostalCodeDriver` contract, input normalization and result caching; custom drivers through `extend()`.
- `zipcloud` driver (default) with timeout, one retry and full-width kana conversion.
- `database` driver and the `jp-postal:import` command for Japan Post's `utf_ken_all` data (download, `--file`, `--url`, `--force`), importing into a staging table that replaces the live one.
- Town name normalization rules verified against all 124,527 rows of the October 2026 data.
- Translations: Japanese, English and Korean.

[Unreleased]: https://github.com/holyat-dev/filament-jp-postal/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/holyat-dev/filament-jp-postal/releases/tag/v1.0.0
