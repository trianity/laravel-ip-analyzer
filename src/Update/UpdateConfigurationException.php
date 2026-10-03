<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

/** Messages are fixed package diagnostics, never upstream responses or option values. */
final class UpdateConfigurationException extends \InvalidArgumentException {}
