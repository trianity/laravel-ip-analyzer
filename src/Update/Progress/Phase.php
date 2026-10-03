<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

/** @internal Machine phase identifiers; human labels belong to the Console layer. */
enum Phase: string
{
    case Configuration = 'configuration';
    case Lock = 'lock';
    case LocalValidation = 'local_validation';
    case Hash = 'hash';
    case Head = 'head';
    case Download = 'download';
    case Extract = 'extract';
    case CandidateValidation = 'candidate_validation';
    case Install = 'install';
    case Retry = 'retry';
    case Done = 'done';
    case Unchanged = 'unchanged';
    case Available = 'available';
    case Failed = 'failed';
    case Busy = 'busy';
    case Interrupted = 'interrupted';
}
