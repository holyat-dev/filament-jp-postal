# holyat/filament-jp-postal 기능 명세서

> 버전: 명세 v0.1 (2026-11-10)
> 대상: Filament v5 (5.8.3 이상) / Laravel 12·13 / PHP 8.2+

---

## 1. 개요

일본 우편번호(郵便番号)를 입력하면 都道府県·市区町村·町域을 자동으로 채워 주는 Filament v5 폼 필드 패키지.
데이터 소스는 **드라이버 방식**으로 교체할 수 있다.

| 항목 | 값 |
|---|---|
| Composer 이름 | `holyat/filament-jp-postal` |
| GitHub | `holyat-dev/filament-jp-postal` |
| 네임스페이스 | `Holyat\FilamentJpPostal` |
| 라이선스 | MIT |
| 설정 파일 | `config/jp-postal.php` |
| 번역 | `ja`(기본), `en`, `ko` |

### 로드맵

| 버전 | 범위 |
|---|---|
| **v1.0** | 드라이버 구조, `zipcloud` 드라이버(기본), `database` 드라이버, 가져오기 명령, 폼 필드, 都道府県 셀렉트 |
| **v1.1** | `japanpost` 드라이버(일본우편 공식 「郵便番号・デジタルアドレス API」) |

**이 명세서의 구현 범위는 v1.0이다.** v1.1은 확장 지점만 열어 둔다.

---

## 2. 사용 예 (목표 API)

```php
use Holyat\FilamentJpPostal\Forms\Components\PostalCodeInput;
use Holyat\FilamentJpPostal\Forms\Components\PrefectureSelect;

PostalCodeInput::make('zipcode')
    ->fillsAddress(
        prefecture: 'prefecture',   // 또는 prefectureCode: 'pref_code'
        city: 'city',
        town: 'town',
    )
    ->autoLookup()                  // 7자리 입력 시 자동 검색 (기본: true)
    ->lookupAction(),               // 오른쪽에 「住所検索」 버튼 표시 (기본: true)

PrefectureSelect::make('prefecture'),          // 47都道府県, 값 = 이름
PrefectureSelect::make('pref_code')->codes(),  // 값 = JIS 코드 '01'~'47'

TextInput::make('city'),
TextInput::make('town'),
```

서비스 단독 사용:

```php
use Holyat\FilamentJpPostal\Facades\JpPostal;

$addresses = JpPostal::lookup('100-0001');              // Collection<Address>
$addresses = JpPostal::driver('database')->lookup('1000001');
```

---

## 3. 구성 요소

### 3.1 `Address` DTO (readonly)

| 속성 | 타입 | 예 |
|---|---|---|
| `zipcode` | string (7자리, 하이픈 없음) | `1000001` |
| `prefectureCode` | string (2자리) | `13` |
| `prefecture` | string | `東京都` |
| `city` | string | `千代田区` |
| `town` | string | `千代田` |
| `prefectureKana` / `cityKana` / `townKana` | ?string (전각 가타카나) | `トウキョウト` |
| `localGovernmentCode` | ?string (JIS X0402, 5자리) | `13101` |

- `toArray()`, `fullAddress(): string` 제공.
- 드라이버가 제공하지 않는 값은 `null`.

### 3.2 드라이버 계약

```php
interface PostalCodeDriver
{
    /** @return Collection<int, Address> 결과 없음 = 빈 컬렉션 */
    public function lookup(string $zipcode): Collection;
}
```

- `JpPostalManager extends Illuminate\Support\Manager` — `createZipcloudDriver()`, `createDatabaseDriver()`.
- `JpPostal::extend('custom', fn () => ...)` 로 사용자 드라이버 추가 가능 (v1.1 확장 지점).
- 입력 정규화는 Manager 쪽에서 공통 처리: 전각 숫자→반각, 하이픈·공백·〒 제거, 7자리 숫자가 아니면 `InvalidPostalCodeException`.

### 3.3 `zipcloud` 드라이버 (기본값)

- 엔드포인트: `https://zipcloud.ibsnet.co.jp/api/search?zipcode={7자리}`
- 응답: `{ status, message, results: [{ address1, address2, address3, kana1, kana2, kana3, prefcode, zipcode }] | null }`
  - 매핑: address1→prefecture, address2→city, address3→town, prefcode→prefectureCode(2자리로 패딩)
  - kana는 반각 가타카나일 수 있으므로 `mb_convert_kana($v, 'KV')` 로 전각 변환.
- `Http::timeout(config)` + 재시도 1회. 실패 시 `PostalLookupFailedException` (네트워크/상태≠200 구분).
- 결과 캐시: `cache.ttl` (기본 30일), 키 `jp-postal:zipcloud:{zipcode}`. 빈 결과도 짧게(1일) 캐시.
- zipcloud 이용 약관 (2026-11-10 확인, https://zipcloud.ibsnet.co.jp/rule/api):
  - 상업적 이용을 금지하는 조항은 없음. 단, **API 기능만 제공하는 것이 목적인 사이트에서의 이용은 금지**.
  - 운영사가 사전 통지 없이 서비스를 중단·종료하거나 호출 횟수를 제한할 수 있고, 어떤 보증도 하지 않음.
  - 최신 정보를 표시할 의무가 있음 → 캐시 TTL은 너무 길지 않게 (기본 30일 유지, 설정으로 조정).
  - README에 약관 링크와 "이용자가 직접 약관을 확인하고 동의할 것", "운영 환경에서는 database 드라이버 권장"을 명시한다.

### 3.4 `database` 드라이버

**마이그레이션** (테이블명은 설정 가능, 기본 `jp_postal_codes`)

| 컬럼 | 타입 | 비고 |
|---|---|---|
| `id` | bigIncrements | |
| `zipcode` | char(7) | index |
| `local_government_code` | char(5) | |
| `prefecture_code` | char(2) | |
| `prefecture` / `city` / `town` | string | |
| `prefecture_kana` / `city_kana` / `town_kana` | string nullable | |

**가져오기 명령**: `php artisan jp-postal:import {--file=} {--url=} {--force}`

- 데이터: 일본우편 「住所の郵便番号（1レコード1行、UTF-8形式）」 `utf_ken_all.zip`
  - 다운로드 URL (2026-11-10 확인): `https://www.post.japanpost.jp/service/search/zipcode/download/utf/zip/utf_ken_all.zip` — config(`database.source_url`)의 기본값으로 둔다.
  - 월간 차분 파일: 같은 경로의 `utf_add_YYMM.zip` / `utf_del_YYMM.zip` (v1.0에서는 전체 재가져오기만 지원, 차분은 향후 검토).
  - 공식 페이지에 라이선스 표기가 없으므로 원본 데이터는 패키지에 포함하지 않고 이용자 환경에서 내려받게 한다.
  - `--file` 지정 시 로컬 CSV/ZIP 사용 (오프라인·테스트용).
- CSV 열(15개, 헤더 없음): ①지방공공단체코드 ②구 우편번호 ③우편번호 ④都道府県カナ ⑤市区町村カナ ⑥町域カナ ⑦都道府県 ⑧市区町村 ⑨町域 ⑩~⑮ 플래그 → 사용하는 열: ①③④⑤⑥⑦⑧⑨. 都道府県 코드 = ①의 앞 2자리.
- 실제 데이터 형식 (utf_ken_all.csv 2026-10 갱신본, 124,527행으로 확인): 문자열 열은 따옴표로 감쌈(①지방공공단체코드와 ⑩~⑮ 플래그는 따옴표 없음), 카나는 전각, 첫 줄에 BOM 없음, 닫히지 않은 괄호·겹친 괄호 0건. 町域의 `〜`·`「」`는 카나에서 `−`·`＜＞`로 표기됨.
- 町域 정규화 (`TownNameNormalizer`, 위에서부터 순서대로 적용):
  1. `以下に掲載がない場合` → 빈 문자열
  2. `/の次に.+?がくる場合/` 를 포함하면 → 빈 문자열 (뒤에 괄호가 있어도). 예: `琴平町の次に１〜４２６番地がくる場合（川東）`
  3. `一円`으로 끝나고 길이 3 이상 → 빈 문자열 (`王滝村一円`). `一円` 단독은 실제 지명이므로 유지
  4. 첫 `（`부터 마지막 `）`까지 삭제 (안의 `「」`, `、` 포함). 예: `大江（１丁目、２丁目「…」以外、…番地）` → `大江`
  5. `地割` 포함 + (`〜` 또는 `、` 포함) → 첫 번째 숫자(전각·반각)나 `第` 앞까지만 남김. 예: `越中畑６４地割〜越中畑６６地割` → `越中畑`, `種市第１５地割〜第２１地割（…）` → `種市`. 단일 地割(`芦ケ沢７２地割`)은 유지
  6. 1~5 적용 후에도 `、`가 남으면 (여러 町域를 나열한 행) → 빈 문자열. 예: `井道、奥井道、内井道`, `甲、乙（大木戸）` (실제 데이터 12건)
- 町域カナ 정규화:
  - 町域가 빈 문자열이 되면 카나는 판정 없이 `null`.
  - 규칙 4는 카나에도 적용.
  - 규칙 5의 카나: 町域를 숫자에서 잘랐으면 카나도 첫 숫자에서, `第`에서 잘랐으면 카나의 `ダイ`+숫자에서 자름 (`エッチュウハタ６４チワリ−…` → `エッチュウハタ`, `タネイチダイ１５チワリ−…` → `タネイチ`). 잘라 낼 위치가 없거나 결과가 비면 `null`.
- 검증 결과 (2026-11-10, 전체 124,527행): 정규화 후 町域에 `（`, `「`, `場合`, `〜`, `、`가 남은 건 0건. 町域가 빈 문자열이 된 행 1,925건. 町域가 있는데 카나가 `null`인 행 0건. 지번이 町域에 포함된 `大泉町西井出８２４０−１`(407-0311)은 실제 주소이므로 유지.
- 정규화 후 같은 우편번호에 같은 주소가 여러 행 생기는 경우(86개 우편번호, 예: `音江町（１〜２丁目）`·`音江町（音江）`)는 `database` 드라이버가 조회 시 중복을 제거한다.
- 처리: 임시 테이블에 1,000건 단위 chunk insert → 완료 후 원래 테이블과 교체 (가져오는 중에도 조회 가능하게). 진행률 바 표시.
- 스케줄러 예시를 README에 기재 (월 1회).

### 3.5 폼 필드 `PostalCodeInput` (extends `TextInput`)

- 기본: `label(__('filament-jp-postal::messages.postal_code'))`, `placeholder('100-0001')`, `autocomplete('postal-code')`, `inputMode('numeric')`. `->label()`로 덮어쓸 수 있음.
- **입력 서식 (2026-11-10 변경)**: `mask('999-9999')`와 `maxLength(8)`은 쓰지 않는다. mask는 일본어 IME로 입력한 전각 숫자(`１０００００１`)를 통째로 거부하고(Chrome에서 확인), HTML `maxlength`는 붙여넣은 `〒100-0001`(9자)을 서식 변환 전에 잘라 버리기 때문.
  - 대신 Alpine으로 `input`·`compositionend` 이벤트에서 전각 숫자를 반각으로 바꾸고, 숫자 이외(`－ ー − ‐` 등 하이픈류, `〒`, 공백)를 지운 뒤 최대 7자리를 `000-0000` 형식으로 표시. 커서 위치는 숫자 개수 기준으로 유지.
  - IME 입력 중(`$event.isComposing`)에는 값을 바꾸지 않음.
  - 서버 쪽 정규화(`PostalCodeNormalizer`)와 검증은 그대로 유지.
- **저장 형식 (2026-11-10 결정)**:
  - 저장: 기본은 하이픈 없는 7자리 숫자 `1000001`. `->storeWithHyphen()` 이면 `100-0001`. 빈 값은 `null`.
  - 표시: 항상 `100-0001`.
  - 불러올 때: 저장 형식과 상관없이 표시 형식으로 바꿈 (`1000001`, `100-0001`, 전각, `〒` 포함 모두 → `100-0001`. 정수로 저장돼 앞자리 0이 빠진 값도 7자리로 복원).
  - 이용자가 `formatStateUsing()`이나 `afterStateHydrated()`를 걸어도 이 변환은 사라지지 않음: 표시 형식 변환을 먼저 적용한 뒤 이용자 콜백을 이어서 실행 (Filament 기본 동작은 콜백 1개를 덮어씀).
  - 검증: 값이 있는데 정규화(`PostalCodeNormalizer::tryNormalize()`) 결과가 7자리가 아니면 검증 오류.
- `autoLookup(bool = true)`: `live(debounce: 400)` + `afterStateUpdated`. `tryNormalize()` 결과가 7자리일 때만 검색하고, 직전 값과 정규화 결과가 같으면 다시 검색하지 않음 (`100-0001` → `1000001` 같은 재입력·서식 변경).
- `lookupAction(bool = true)`: suffix action 「住所検索」 (이름 `lookupAddress`, 아이콘 `heroicon-m-magnifying-glass`). 버튼은 같은 값이어도 다시 검색. 값이 7자리가 아니면 Notification(warning).
- `fillsAddress(?prefecture, ?prefectureCode, ?city, ?town, ?cityTown)`: 이 필드가 속한 컨테이너 기준 상대 경로로 대상 필드에 채움. `cityTown`은 市区町村+町域을 한 필드에 합칠 때 사용.
- `driver(string)`: 필드 단위로 드라이버 지정.
- 결과 처리:
  - 0건 → 필드 에러가 아닌 Filament `Notification`(warning) 「該当する住所が見つかりません」. 직전 검색에서 자동으로 채운 필드 중 그 뒤 사용자가 고치지 않은 것(현재 값 = 채운 값)만 비우고, 사용자가 직접 입력하거나 고친 값은 유지. `overwrite(false)`이면 아무것도 바꾸지 않음.
    - 직전에 채운 값은 컴포넌트가 요청마다 다시 만들어지므로 Livewire 폼 데이터 안의 메타 경로 `{statePath}__jp_postal_filled`(대상 경로 → 채운 값)에 `Set`으로 기록. Repeater에서는 항목마다 따로 기록됨. 세션은 쓰지 않음 (쿠키 세션의 4KB 제한, 폼 밖에 상태가 남는 문제를 피하기 위해).
    - 메타 키는 저장 데이터에 들어가지 않음: 최상위는 Filament의 `getState()`가 컴포넌트 없는 키를 제외하고, Repeater 항목 안은 `PostalCodeInput::dehydrateState()`에서 제거 (Repeater는 항목 배열을 그대로 저장하므로 제거하지 않으면 JSON 컬럼 등에 들어감 — 테스트로 확인). 필드가 `hidden()`·`visible(false)`·`dehydrated(false)`이거나 숨겨진 Section 안에 있어도 Filament는 `dehydrateState()`를 호출하므로(부모 처리 후 조기 반환할 뿐) 메타 키는 항상 제거됨 — 테스트로 확인.
    - 검색 실패(예외) 시에는 바꾸지 않음.
  - 1건 → 모두 채움 (町域가 빈 문자열이면 `null`).
  - 2건 이상 → 모든 결과에서 같은 都道府県·市区町村만 채우고, 결과마다 다른 값과 町域는 비움 (`null`). `cityTown`에는 공통 市区町村만. 알림 「複数の町域が該当します」(warning).
  - `PostalLookupFailedException` → Notification(danger), 예외는 `report()`, 폼 제출은 막지 않음.
- `overwrite(bool = true)`: `false`이면 이미 값이 있는 대상 필드는 채우지도 비우지도 않음.
- 상대 경로 지원: Repeater 안에서는 같은 항목의 필드만 채움 (Livewire 테스트로 확인).

### 3.6 `PrefectureSelect` (extends `Select`)

- 47都道府県, JIS 순서. `codes()` 호출 시 값을 코드로.
- `searchable()` 기본 on. 데이터는 패키지 내부 상수 (외부 호출 없음).
- 기본 라벨: `__('filament-jp-postal::messages.prefecture')`.

### 3.6.1 번역 키 (`resources/lang/{ja,en,ko}/messages.php`)

- 라벨: `postal_code`, `prefecture`, `city`, `town`, `city_town` (`city`·`town`·`city_town`은 이용자가 자기 필드 라벨에 쓰도록 제공)
- `PostalCodeInput`: `lookup`, `invalid`, `not_found`, `multiple_towns`, `multiple_towns_body`, `lookup_failed`, `lookup_failed_body`
- 세 언어의 키가 같고 빈 값이 없는지, 소스에서 쓰는 키가 모두 정의돼 있는지 테스트로 확인.
- Artisan 명령 출력과 예외 메시지는 개발자용이므로 영어 고정 (번역 대상 아님).

### 3.7 설정 `config/jp-postal.php`

```php
return [
    'default' => env('JP_POSTAL_DRIVER', 'zipcloud'),
    'drivers' => [
        'zipcloud' => [
            'endpoint' => 'https://zipcloud.ibsnet.co.jp/api/search',
            'timeout' => 5,
        ],
        'database' => [
            'connection' => null,
            'table' => 'jp_postal_codes',
            'source_url' => 'https://www.post.japanpost.jp/service/search/zipcode/download/utf/zip/utf_ken_all.zip',
        ],
        // v1.1: 'japanpost' => [...]
    ],
    'cache' => [
        'store' => null,
        'ttl' => 60 * 60 * 24 * 30,
    ],
];
```

---

## 4. 테스트 (Pest + Orchestra Testbench)

| 대상 | 내용 |
|---|---|
| 정규화 | `100-0001`, `１００－０００１`, `〒1000001`, 잘못된 값 → 예외 |
| zipcloud | `Http::fake` 로 1건·복수·0건·500 에러·타임아웃, 반각 카나 변환, 캐시 적중 |
| database | 실제 데이터에서 뽑은 작은 CSV 픽스처(괄호 주석, 以下に掲載がない場合, の次に…がくる場合, 一円, 地割 범위, 町域 나열, 複数町域, 정규화 후 중복 포함)로 import → lookup |
| import 명령 | `--file` 로 실행, 테이블 교체 후 건수 확인, 잘못된 파일 처리 |
| 폼 필드 | Livewire 테스트 컴포넌트로 우편번호 입력 → 대상 필드 값 확인, Repeater 내부 동작 |
| PrefectureSelect | 47건, 코드 모드 |

- CI: GitHub Actions — PHP 8.2/8.3/8.4 × Laravel 12/13 (PHP 8.2 × Laravel 13 제외) × prefer-lowest/prefer-stable 매트릭스, MySQL·PostgreSQL에서 import(테이블 교체)·database 드라이버 테스트(`--group=database`), Pint, PHPStan(level 6, `src`·`config`).
- Filament 5.8.2 이하는 Packagist 보안 권고 때문에 Composer가 설치를 막으므로 최저 지원 버전은 5.8.3.

---

## 5. 문서·공개

- README: **일본어 먼저**, 영어 병기. 설치, 3개 사용 예, 드라이버 선택 가이드, zipcloud 약관 안내, 데이터 출처 표기(日本郵便).
- 스크린샷 1~2장.
- CHANGELOG, LICENSE(MIT).

---

## 6. v1.1 메모 (이번에는 구현하지 않음)

- **japanpost 드라이버**: ゆうID + 「郵便番号・デジタルアドレス for Biz」 등록, OAuth 2.0 client_credentials, 접속 IP 사전 등록(최대 10개). 토큰은 캐시. 엔드포인트와 응답 형식은 공식 문서를 보고 확정.
