<?php

use Illuminate\Support\Facades\Blade;

/*
 * The production image installs only pdo, pdo_pgsql, mbstring and zip, while the
 * local PHP (Herd) also has intl. Anything that needs an extension the image
 * lacks passes every test here and returns a 500 in production, so these tests
 * guard the known cases.
 */

test('the app does not use the intl-dependent Number helper', function () {
    $offenders = collect(['app', 'resources/views'])
        ->flatMap(fn (string $directory) => File::allFiles(base_path($directory)))
        ->filter(fn (SplFileInfo $file) => str_ends_with($file->getFilename(), '.php'))
        ->filter(fn (SplFileInfo $file) => preg_match('/Support\\\\Number\b|\bNumber::|NumberFormatter/', $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('column chart axis ticks abbreviate large amounts without the intl extension', function (int $peak, string $ceilingTick, string $middleTick) {
    $html = Blade::render(
        '<x-charts.column-chart title="Rent" prefix="₱" period="Month" :points="$points" />',
        ['points' => [
            ['label' => 'Aug', 'hint' => 'August 2026', 'value' => 0],
            ['label' => 'Sep', 'hint' => 'September 2026', 'value' => $peak],
        ]],
    );

    expect(preg_match('/'.preg_quote($ceilingTick, '/').'<\/span>\s*<span>'.preg_quote($middleTick, '/').'<\/span>\s*<span>₱0<\/span>/', $html))->toBe(1);
})->with([
    'small' => [3, '₱4', '₱2'],
    'hundreds' => [730, '₱1K', '₱500'],
    'thousands' => [2500, '₱4K', '₱2K'],
    'tens of thousands' => [13000, '₱20K', '₱10K'],
    'millions' => [1800000, '₱2M', '₱1M'],
]);
