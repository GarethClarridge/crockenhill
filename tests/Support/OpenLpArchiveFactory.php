<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use JsonException;
use RuntimeException;
use ZipArchive;

class OpenLpArchiveFactory
{
    /**
     * @param  array<int, mixed>|null  $payload
     */
    public static function makeUpload(
        string $archiveName = '2024-11-17 AM.osz',
        string $osjName = '2024-11-17 AM.osj',
        ?array $payload = null
    ): UploadedFile {
        $payload ??= self::payload();

        $path = tempnam(sys_get_temp_dir(), 'openlp_');
        if ($path === false) {
            throw new RuntimeException('Unable to create temporary archive path.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create OpenLP archive.');
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode OpenLP payload JSON.', previous: $exception);
        }

        $zip->addFromString($osjName, $json);
        $zip->close();

        return new UploadedFile(
            path: $path,
            originalName: $archiveName,
            mimeType: 'application/zip',
            test: true,
        );
    }

    public static function makeUploadWithoutOsj(string $archiveName = '2024-11-17 AM.osz'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'openlp_');
        if ($path === false) {
            throw new RuntimeException('Unable to create temporary archive path.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create OpenLP archive.');
        }

        $zip->addFromString('placeholder.txt', 'No OSJ in this archive');
        $zip->close();

        return new UploadedFile(
            path: $path,
            originalName: $archiveName,
            mimeType: 'application/zip',
            test: true,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $serviceItems
     * @return array<int, mixed>
     */
    public static function payload(array $serviceItems = []): array
    {
        return [
            [
                'openlp_core' => [
                    'lite-service' => false,
                    'service-theme' => '',
                ],
            ],
            ...$serviceItems,
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    public static function serviceItem(array $header, array $data = []): array
    {
        return [
            'serviceitem' => [
                'header' => $header,
                'data' => $data,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function songHeader(
        string $title,
        string $searchTitle,
        string $authors = 'Test Author'
    ): array {
        return [
            'name' => 'songs',
            'plugin' => 'songs',
            'title' => $title,
            'search' => '',
            'data' => [
                'title' => $searchTitle,
                'authors' => $authors,
            ],
            'footer' => [$title],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function bibleHeader(string $verboseTitle, string $reference): array
    {
        return [
            'name' => 'bibles',
            'plugin' => 'bibles',
            'title' => $verboseTitle,
            'search' => '',
            'data' => '',
            'footer' => [$reference, 'Copyright line'],
            'theme' => 'SecretBible',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentationHeader(string $title): array
    {
        return [
            'name' => 'presentations',
            'plugin' => 'presentations',
            'title' => $title,
            'search' => '',
            'data' => '',
            'footer' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customHeader(string $title, string $theme = 'Reading'): array
    {
        return [
            'name' => 'custom',
            'plugin' => 'custom',
            'title' => $title,
            'search' => '',
            'data' => '',
            'footer' => [''],
            'theme' => $theme,
        ];
    }
}
