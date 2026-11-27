<?php

declare(strict_types=1);

namespace App\Service\Shared\Storage;

use Aws\S3\S3Client;

/**
 * Builds the client of the `documents.s3` storage (config/packages/flysystem.yaml) from the
 * S3_* variables. Only built when PEYRA_STORAGE=s3: development and test never construct it.
 *
 * Empty keys leave the SDK to its own credential chain (an instance role, say) rather than send
 * empty ones; an endpoint names an S3-compatible service and switches to path-style addresses,
 * which those services expect.
 */
final class S3ClientFactory
{
    public static function create(string $region, string $accessKeyId, string $secretAccessKey, string $endpoint): S3Client
    {
        $options = [
            'version' => '2006-03-01',
            'region' => '' !== $region ? $region : 'eu-west-3',
        ];
        if ('' !== $accessKeyId && '' !== $secretAccessKey) {
            $options['credentials'] = ['key' => $accessKeyId, 'secret' => $secretAccessKey];
        }
        if ('' !== $endpoint) {
            $options['endpoint'] = $endpoint;
            $options['use_path_style_endpoint'] = true;
        }

        return new S3Client($options);
    }
}
