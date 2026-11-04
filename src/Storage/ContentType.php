<?php

declare(strict_types=1);

namespace Elviweb\R2Storage\Storage;

/** @internal */
final class ContentType
{
    public static function detect(string $path): string
    {
        $type = (new \finfo(\FILEINFO_MIME_TYPE))->file($path);

        return \is_string($type) && '' !== $type ? $type : 'application/octet-stream';
    }
}
