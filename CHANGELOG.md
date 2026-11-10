# Changelog

All notable changes to `holyat/filament-jp-postal` are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - Unreleased

First release.

### Added

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
