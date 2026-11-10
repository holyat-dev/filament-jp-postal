# CLAUDE.md — holyat/filament-jp-postal

일본 우편번호로 주소를 자동 입력하는 **Filament v5 플러그인(composer 패키지)** 이다.
명세는 `docs/SPEC.md`에 있다. 공개 API(클래스명, 메서드명, 설정 키)는 명세에 적힌 이름을 그대로 쓴다.

## 기술 스택

- PHP 8.2+, Laravel 12·13, Filament ^5.8.3 (그 이하는 보안 권고로 설치 불가), Livewire (Filament 의존성 버전)
- 패키지 기반: `spatie/laravel-package-tools`
- 테스트: Pest + `orchestra/testbench`
- 품질: Laravel Pint, PHPStan (level 6, `src`·`config`)

## 디렉터리 구조

```
src/
  FilamentJpPostalServiceProvider.php
  JpPostalManager.php                 # Illuminate\Support\Manager, 모든 드라이버를 ManagedDriver로 감쌈
  Facades/JpPostal.php
  Contracts/PostalCodeDriver.php
  Contracts/ShouldCacheLookups.php    # 결과를 캐시할 드라이버 표시
  Data/Address.php
  Drivers/ManagedDriver.php           # 입력 정규화 + 캐시
  Drivers/ZipcloudDriver.php
  Drivers/DatabaseDriver.php
  Support/PostalCodeNormalizer.php
  Support/Prefectures.php
  Support/TownNameNormalizer.php
  Commands/ImportPostalCodesCommand.php
  Exceptions/
  Forms/Components/PostalCodeInput.php
  Forms/Components/PrefectureSelect.php
  Forms/StateCasts/StringOptionStateCast.php
config/jp-postal.php
database/migrations/create_jp_postal_codes_table.php.stub
resources/lang/{ja,en,ko}/messages.php
tests/
  Fixtures/                           # 가짜 드라이버, Livewire 테스트 폼, utf_ken_all 샘플 CSV
  Unit/ Feature/
docs/SPEC.md
```

## 테스트

- `composer test` (Pest), `composer analyse` (PHPStan), `composer format` (Pint). 커밋 전에 셋 다 통과시킨다.
- HTTP는 반드시 `Http::fake()`를 쓰고 `Http::preventStrayRequests()`로 막는다. 테스트에서 실제 외부 API를 호출하지 않는다.
- 폼 필드는 `tests/Fixtures/Livewire/TestForm`으로 Livewire 테스트를 쓴다. Repeater 안의 동작도 함께 확인한다.
- `database` 그룹(`--group=database`)은 CI에서 MySQL·PostgreSQL로도 돈다. 테스트 사이에 테이블이 남을 수 있으므로 `migratePostalCodesTable()`로 다시 만든다.
- 일본우편 원본 데이터(전체 CSV)는 저장소에 커밋하지 않는다. 테스트용 작은 샘플만 허용.

## 코딩 규칙

- `declare(strict_types=1);`, 타입 선언 필수, `final` 클래스 기본 (확장 지점인 Manager·폼 컴포넌트는 예외)
- 사용자에게 보이는 문자열은 모두 번역 키 사용 (`__('filament-jp-postal::messages.not_found')`). Artisan 출력과 예외 메시지는 영어 고정.
- 일본어 문자열 처리 시 `mb_*` 함수 사용, 카나는 전각으로 통일
- 컴포넌트에서 직접 HTTP를 호출하지 말고 항상 `JpPostal` 매니저를 거친다
- 폼 컴포넌트의 클로저는 `$this` 대신 주입된 `$component`를 쓴다. Filament는 컴포넌트를 복제(Repeater 항목마다)할 때 클로저를 다시 바인딩하지 않는다.
- 외부 서비스 엔드포인트, CSV 형식 등 명세에 확인 날짜와 함께 적힌 것 외의 확인되지 않은 사실을 추측해서 하드코딩하지 않는다.
- 커밋 메시지는 영어, Conventional Commits.
