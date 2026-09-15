<?php

/**
 * One-off generator for tests/fixtures/documents/sample.docx.
 *
 * The fixture is committed as a binary, so this script exists to explain
 * (and reproduce) exactly what is inside it: a Title, Heading1 "Scope",
 * Heading2 "Method", a paragraph carrying bold/italic/link runs, a bulleted
 * list of three, a numbered list of two, a 2x2 table whose first row is
 * marked `tblHeader`, and one 4x4 px PNG.
 *
 * Run with: php scripts/make-docx-fixture.php
 */

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;

$fixtureDir = __DIR__.'/../tests/fixtures/documents';
if (! is_dir($fixtureDir)) {
    mkdir($fixtureDir, 0755, true);
}

// A 4x4 px PNG, generated with GD so nothing binary has to be pasted here.
$image = imagecreatetruecolor(4, 4);
imagefill($image, 0, 0, imagecolorallocate($image, 31, 78, 121));
$pngPath = sys_get_temp_dir().'/dot-doc-fixture-4x4.png';
imagepng($image, $pngPath);

Style::resetStyles();

$word = new PhpWord;
$word->setDefaultFontName('Source Serif 4');
$word->setDefaultFontSize(11);

// addTitleStyle() MUST run before addTitle(): PhpWord\Element\Title only
// records the `Heading{n}` paragraph style when that style is already in the
// static Style registry, and without the style there is no pStyle in the XML
// for a reader to recognise the heading by.
$word->addTitleStyle(0, ['size' => 22, 'bold' => true, 'color' => '1F2023']);
$word->addTitleStyle(1, ['size' => 22, 'bold' => true, 'color' => '1F2023']);
$word->addTitleStyle(2, ['size' => 16, 'bold' => true, 'color' => '1F2023']);
$word->addNumberingStyle('FixtureBullet', ['type' => 'hybridMultilevel', 'levels' => [
    ['format' => 'bullet', 'text' => "\u{2022}", 'left' => 360, 'hanging' => 360, 'tabPos' => 360, 'font' => 'Symbol'],
]]);
$word->addNumberingStyle('FixtureNumber', ['type' => 'multilevel', 'levels' => [
    ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360],
]]);

$section = $word->addSection();
$section->addTitle('Sample Import Fixture', 0);
$section->addTitle('Scope', 1);
$section->addTitle('Method', 2);

$run = $section->addTextRun();
$run->addText('The survey covers ');
$run->addText('every region', ['bold' => true]);
$run->addText(' and was ');
$run->addText('reviewed twice', ['italic' => true]);
$run->addText(', see the ');
$run->addLink('https://example.com/method', 'method note', ['color' => '0563C1', 'underline' => 'single']);
$run->addText('.');

foreach (['Coverage', 'Sampling', 'Weighting'] as $bullet) {
    $section->addListItemRun(0, 'FixtureBullet')->addText($bullet);
}
foreach (['Collect responses', 'Publish the summary'] as $step) {
    $section->addListItemRun(0, 'FixtureNumber')->addText($step);
}

$table = $section->addTable(['borderSize' => 6, 'borderColor' => 'D5D1C7', 'cellMargin' => 80]);
$headerRow = $table->addRow(null, ['tblHeader' => true]);
$headerRow->addCell(4200, ['bgColor' => 'EFEDE7'])->addText('Region', ['bold' => true]);
$headerRow->addCell(4200, ['bgColor' => 'EFEDE7'])->addText('Yield', ['bold' => true]);
$bodyRow = $table->addRow();
$bodyRow->addCell(4200)->addText('North');
$bodyRow->addCell(4200)->addText('12.4');

$section->addImage($pngPath, ['width' => 48, 'height' => 48]);

$target = $fixtureDir.'/sample.docx';
IOFactory::createWriter($word, 'Word2007')->save($target);
unlink($pngPath);

printf('wrote %s (%d bytes)%s', $target, filesize($target), PHP_EOL);
