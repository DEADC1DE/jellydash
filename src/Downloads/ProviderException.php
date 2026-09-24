<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

final class ProviderException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'request_failed')
    {
        parent::__construct(match ($reason) {
            'authentication_failed' => 'The client rejected the credentials.',
            'timeout' => 'The client did not respond in time.',
            'invalid_response' => 'The client returned an unreadable response.',
            'response_too_large' => 'The client response exceeded the size limit.',
            'unsupported_version' => 'This client API version is not supported.',
            'ambiguous_host' => 'Deluge Web has multiple saved daemons. Use a Web instance with one saved daemon.',
            'labels_unavailable' => 'Enable the Label plugin in Deluge to use the selected labels.',
            default => 'Could not connect to the download client.',
        });
    }
}
