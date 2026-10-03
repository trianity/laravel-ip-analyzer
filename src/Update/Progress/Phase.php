<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

enum Phase: string
{
    case Configuration = 'Konfiguráció és helyi állapot';
    case Lock = 'Lock várakozás';
    case LocalValidation = 'Helyi MMDB integritásvizsgálata';
    case Hash = 'SHA-256 számítása';
    case Head = 'Távoli kiadás ellenőrzése (HEAD)';
    case Download = 'Letöltés';
    case Extract = 'Kicsomagolás';
    case CandidateValidation = 'Letöltött MMDB validálása';
    case Install = 'Telepítés és state mentése';
    case Retry = 'Újrapróbálkozás előtti várakozás';
    case Done = 'Kész';
    case Unchanged = 'Változatlan';
    case Available = 'Ellenőrzés kész, letöltés szükséges';
    case Failed = 'Hiba';
    case Busy = 'Foglalt';
    case Interrupted = 'Megszakítva';
}
