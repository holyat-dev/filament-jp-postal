<?php

declare(strict_types=1);

use Holyat\FilamentJpPostal\Support\TownNameNormalizer;

// Town names and kana below are taken verbatim from utf_ken_all.csv (updated 2026-10).

it('normalizes town names', function (string $town, string $expected): void {
    expect(TownNameNormalizer::normalize($town))->toBe($expected);
})->with([
    'plain' => ['千代田', '千代田'],
    'not listed' => ['以下に掲載がない場合', ''],
    'address number follows' => ['いの町の次に番地がくる場合', ''],
    'address number range follows, with annotation' => ['琴平町の次に１〜４２６番地がくる場合（川東）', ''],
    'address numbers onwards follow' => ['小菅村の次に６６４番地以降がくる場合', ''],
    'whole area' => ['王滝村一円', ''],
    'real town name 一円' => ['一円', '一円'],
    'excluding buildings' => ['西新宿（次のビルを除く）', '西新宿'],
    'annotation with brackets and commas' => ['大江（１丁目、２丁目「６５１、６６２、６６８番地」以外、３丁目５、１３−４、２０、６７８、６８７番地）', '大江'],
    '地割 range inside an annotation' => ['葛巻（第４０地割「５７番地１２５、１７６を除く」〜第４５地割）', '葛巻'],
    '地割 range' => ['越中畑６４地割〜越中畑６６地割', '越中畑'],
    '地割 list' => ['樺沢１６地割、樺沢１７地割', '樺沢'],
    '地割 range with 第 and annotation' => ['種市第１５地割〜第２１地割（鹿糠、小路合、緑町、大久保、高取）', '種市'],
    'single 地割' => ['芦ケ沢７２地割', '芦ケ沢７２地割'],
    'list of towns' => ['井道、奥井道、内井道', ''],
    'list of towns with annotation' => ['甲、乙（大木戸）', ''],
    'lot number with annotation' => ['大泉町西井出８２４０−１（美森、たかね荘、清泉寮、サンメドウズスキー場）', '大泉町西井出８２４０−１'],
]);

it('normalizes kana with the same rules', function (string $kana, string $town, ?string $expected): void {
    expect(TownNameNormalizer::normalizeKana($kana, $town))->toBe($expected);
})->with([
    'plain' => ['チヨダ', '千代田', 'チヨダ'],
    'not listed' => ['イカニケイサイガナイバアイ', '以下に掲載がない場合', null],
    'address number follows' => ['コトヒラチョウノツギニ１−４２６バンチガクルバアイ（カワヒガシ）', '琴平町の次に１〜４２６番地がくる場合（川東）', null],
    'whole area' => ['オウタキムライチエン', '王滝村一円', null],
    'real town name 一円' => ['イチエン', '一円', 'イチエン'],
    'annotation' => ['ニシシンジュク（ツギノビルヲノゾク）', '西新宿（次のビルを除く）', 'ニシシンジュク'],
    'annotation with brackets' => ['クズマキ（ダイ４０チワリ＜５７バンチ１２５、１７６ヲノゾク＞−ダイ４５チワリ）', '葛巻（第４０地割「５７番地１２５、１７６を除く」〜第４５地割）', 'クズマキ'],
    '地割 range cut at a digit' => ['エッチュウハタ６４チワリ−エッチュウハタ６６チワリ', '越中畑６４地割〜越中畑６６地割', 'エッチュウハタ'],
    '地割 list cut at a digit' => ['カバサワ１６チワリ、カバサワ１７チワリ', '樺沢１６地割、樺沢１７地割', 'カバサワ'],
    '地割 range cut at 第' => ['タネイチダイ１５チワリ−ダイ２１チワリ（カヌカ、ショウジアイ、ミドリチョウ、オオクボ、タカトリ）', '種市第１５地割〜第２１地割（鹿糠、小路合、緑町、大久保、高取）', 'タネイチ'],
    'single 地割' => ['ヨシガサワ７２チワリ', '芦ケ沢７２地割', 'ヨシガサワ７２チワリ'],
    'list of towns' => ['イミチ、オクイミチ、ウチイミチ', '井道、奥井道、内井道', null],
    '地割 range whose kana cannot be cut' => ['エッチュウハタチワリ', '越中畑６４地割〜越中畑６６地割', null],
    'half-width kana' => ['ﾁﾖﾀﾞ', '千代田', 'チヨダ'],
    'empty kana' => ['', '千代田', null],
]);
