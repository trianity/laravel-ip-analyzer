<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

interface Transport
{
    public function request(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] UpdateOptions $options, ?string $sink = null): RemoteResponse;
}
