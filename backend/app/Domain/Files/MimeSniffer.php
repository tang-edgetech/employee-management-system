<?php

namespace App\Domain\Files;

use finfo;
use ZipArchive;

/**
 * Works out a file's real type from its bytes, never from its name or the
 * browser's claim (brief §4: "Validate MIME by magic bytes").
 */
final class MimeSniffer
{
    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function sniff(string $path): string
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);

        // A .docx is a zip; some magic databases only say "zip". Look inside to be sure.
        if (in_array($mime, ['application/zip', 'application/octet-stream'], true) && $this->isDocx($path)) {
            return self::DOCX;
        }

        return $mime;
    }

    private function isDocx(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $isDocx = $zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();

        return $isDocx;
    }
}
