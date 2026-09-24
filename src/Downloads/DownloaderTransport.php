<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

interface DownloaderTransport
{
    public function request(HttpRequest $request): HttpResponse;

    /** @param list<HttpRequest> $requests @return list<HttpResponse> */
    public function requestMany(array $requests): array;
}
