<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Commands;

use Holyat\FilamentJpPostal\Exceptions\PostalCodeImportException;
use Holyat\FilamentJpPostal\Support\TownNameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use SplFileObject;
use Throwable;
use ZipArchive;

/**
 * Imports Japan Post's 「住所の郵便番号（1レコード1行、UTF-8形式）」 (utf_ken_all.csv).
 */
final class ImportPostalCodesCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'jp-postal:import
        {--file= : Path to a local utf_ken_all CSV or ZIP file}
        {--url= : URL to download the ZIP from (defaults to jp-postal.drivers.database.source_url)}
        {--force : Force the operation to run when in production}';

    protected $description = 'Import Japan Post postal code data into the database driver table';

    private const CHUNK_SIZE = 1000;

    private const COLUMN_COUNT = 15;

    private const DOWNLOAD_TIMEOUT = 120;

    public function handle(DatabaseManager $db, HttpFactory $http, Filesystem $files): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        /** @var Connection $connection */
        $connection = $db->connection(config('jp-postal.drivers.database.connection'));
        $schema = $connection->getSchemaBuilder();
        $table = (string) config('jp-postal.drivers.database.table', 'jp_postal_codes');
        $staging = "{$table}_importing";

        if (! $schema->hasTable($table)) {
            $this->components->error("The [{$table}] table does not exist. Publish and run the migration first:");
            $this->line('  php artisan vendor:publish --tag=filament-jp-postal-migrations');
            $this->line('  php artisan migrate');

            return self::FAILURE;
        }

        $workDirectory = sys_get_temp_dir().'/jp-postal-'.Str::random(16);
        $files->ensureDirectoryExists($workDirectory);

        try {
            $csv = $this->resolveCsv($http, $workDirectory);

            $this->components->info("Importing [{$csv}] into [{$table}].");

            $count = $this->importInto($connection, $schema, $staging, $csv);
            $this->swap($schema, $table, $staging);

            $this->components->info("Imported {$count} postal codes.");

            return self::SUCCESS;
        } catch (PostalCodeImportException $e) {
            $schema->dropIfExists($staging);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $schema->dropIfExists($staging);

            throw $e;
        } finally {
            $files->deleteDirectory($workDirectory);
        }
    }

    private function resolveCsv(HttpFactory $http, string $workDirectory): string
    {
        $file = $this->option('file');

        if (is_string($file) && $file !== '') {
            $file = $this->expandHomeDirectory($file);

            if (! is_file($file) || ! is_readable($file)) {
                throw new PostalCodeImportException("The file [{$file}] does not exist or is not readable.");
            }

            $path = $file;
        } else {
            $path = $this->download($http, $workDirectory);
        }

        return $this->isZip($path) ? $this->extractCsv($path, $workDirectory) : $path;
    }

    private function download(HttpFactory $http, string $workDirectory): string
    {
        $url = $this->option('url') ?: config('jp-postal.drivers.database.source_url');

        if (! is_string($url) || $url === '') {
            throw new PostalCodeImportException('No download URL is configured. Pass --file or --url.');
        }

        $url = $this->expandHomeDirectory($url);

        if (preg_match('#\Ahttps?://#i', $url) !== 1) {
            throw new PostalCodeImportException("The URL [{$url}] must start with http:// or https://. Use --file for a local file.");
        }

        $path = "{$workDirectory}/download.zip";

        $this->components->info("Downloading [{$url}].");

        try {
            $response = $http->timeout(self::DOWNLOAD_TIMEOUT)->sink($path)->get($url);
        } catch (ConnectionException $e) {
            throw new PostalCodeImportException("Could not download [{$url}]: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new PostalCodeImportException("Could not download [{$url}]: the server responded with status {$response->status()}.");
        }

        return $path;
    }

    /**
     * Shells do not expand a tilde right after `=` (e.g. `--file=~/utf_ken_all.zip`), so a leading `~/` is
     * expanded here. Only the current user's home (`~/`) is supported, not `~user/`.
     */
    private function expandHomeDirectory(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: getenv('USERPROFILE');

        if (! is_string($home) || $home === '') {
            return $path;
        }

        return rtrim($home, '/\\').substr($path, 1);
    }

    private function isZip(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $signature = fread($handle, 4);
        fclose($handle);

        return $signature === "PK\x03\x04";
    }

    private function extractCsv(string $zipPath, string $workDirectory): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new PostalCodeImportException('The zip PHP extension is required to read ZIP files. Extract the CSV and pass it with --file.');
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new PostalCodeImportException("The file [{$zipPath}] is not a valid ZIP archive.");
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (! str_ends_with(strtolower($name), '.csv')) {
                    continue;
                }

                // Copied through a stream instead of extractTo() so entry names cannot point outside the work directory.
                $source = $zip->getStream($name);
                $path = "{$workDirectory}/postal_codes.csv";
                $target = fopen($path, 'wb');

                if ($source === false || $target === false) {
                    throw new PostalCodeImportException("Could not extract [{$name}] from the ZIP archive.");
                }

                stream_copy_to_stream($source, $target);
                fclose($source);
                fclose($target);

                return $path;
            }
        } finally {
            $zip->close();
        }

        throw new PostalCodeImportException('The ZIP archive does not contain a CSV file.');
    }

    private function importInto(Connection $connection, Builder $schema, string $staging, string $csv): int
    {
        $schema->dropIfExists($staging);
        $schema->create($staging, function (Blueprint $table) use ($staging): void {
            $table->id();
            // An explicit, unique index name: the staging table is renamed later, and some databases
            // require index names to be unique per schema, so the default name would collide next time.
            $table->char('zipcode', 7)->index("{$staging}_zipcode_".Str::lower(Str::random(8)));
            $table->char('local_government_code', 5);
            $table->char('prefecture_code', 2);
            $table->string('prefecture');
            $table->string('city');
            $table->string('town');
            $table->string('prefecture_kana')->nullable();
            $table->string('city_kana')->nullable();
            $table->string('town_kana')->nullable();
        });

        $progress = $this->output->createProgressBar($this->countLines($csv));
        $count = 0;

        $connection->transaction(function () use ($connection, $staging, $csv, $progress, &$count): void {
            $chunk = [];

            foreach ($this->readRows($csv) as $row) {
                $chunk[] = $row;
                $count++;
                $progress->advance();

                if (count($chunk) === self::CHUNK_SIZE) {
                    $connection->table($staging)->insert($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $connection->table($staging)->insert($chunk);
            }
        });

        $progress->finish();
        $this->newLine(2);

        if ($count === 0) {
            throw new PostalCodeImportException('The CSV file does not contain any postal codes.');
        }

        return $count;
    }

    /**
     * @return iterable<int, array<string, string|null>>
     */
    private function readRows(string $csv): iterable
    {
        $file = new SplFileObject($csv);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',', '"', '');

        foreach ($file as $index => $columns) {
            if (! is_array($columns) || $columns === [null]) {
                continue;
            }

            yield $this->toRow(array_map(strval(...), $columns), (int) $index + 1);
        }
    }

    /**
     * Columns used: ①地方公共団体コード ③郵便番号 ④都道府県カナ ⑤市区町村カナ ⑥町域カナ ⑦都道府県 ⑧市区町村 ⑨町域
     *
     * @param  list<string>  $columns
     * @return array<string, string|null>
     */
    private function toRow(array $columns, int $line): array
    {
        if ($line === 1) {
            $columns[0] = (string) preg_replace('/\A\x{FEFF}/u', '', $columns[0]);
        }

        if (! mb_check_encoding(implode('', $columns), 'UTF-8')) {
            throw new PostalCodeImportException("Line {$line} is not UTF-8. Use the UTF-8 file (utf_ken_all), not the Shift_JIS one (ken_all).");
        }

        if (count($columns) !== self::COLUMN_COUNT) {
            throw new PostalCodeImportException(sprintf('Line %d has %d columns; expected %d.', $line, count($columns), self::COLUMN_COUNT));
        }

        [$localGovernmentCode, , $zipcode, $prefectureKana, $cityKana, $townKana, $prefecture, $city, $town] = array_map(trim(...), $columns);

        if (preg_match('/\A\d{5}\z/', $localGovernmentCode) !== 1 || preg_match('/\A\d{7}\z/', $zipcode) !== 1) {
            throw new PostalCodeImportException("Line {$line} does not contain a valid local government code and postal code.");
        }

        $normalizedTown = TownNameNormalizer::normalize($town);

        return [
            'zipcode' => $zipcode,
            'local_government_code' => $localGovernmentCode,
            'prefecture_code' => substr($localGovernmentCode, 0, 2),
            'prefecture' => $prefecture,
            'city' => $city,
            'town' => $normalizedTown,
            'prefecture_kana' => $this->kana($prefectureKana),
            'city_kana' => $this->kana($cityKana),
            'town_kana' => TownNameNormalizer::normalizeKana($townKana, $town),
        ];
    }

    private function kana(string $value): ?string
    {
        return $value === '' ? null : mb_convert_kana($value, 'KV');
    }

    private function countLines(string $csv): int
    {
        $lines = 0;
        $handle = fopen($csv, 'rb');

        if ($handle === false) {
            throw new PostalCodeImportException("The file [{$csv}] is not readable.");
        }

        while (fgets($handle) !== false) {
            $lines++;
        }

        fclose($handle);

        return $lines;
    }

    /**
     * The live table stays readable for the whole import and is only replaced at the end.
     */
    private function swap(Builder $schema, string $table, string $staging): void
    {
        $previous = "{$table}_previous";

        $schema->dropIfExists($previous);
        $schema->rename($table, $previous);
        $schema->rename($staging, $table);
        $schema->drop($previous);
    }
}
