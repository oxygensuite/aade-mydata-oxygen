<?php

namespace OxygenSuite\AadeMyData\Enums;

/**
 * How long a POS signature stays usable. An invoice referencing an expired signature is
 * rejected, and a signature cannot be renewed — only replaced — so prefer the long window
 * unless the transaction is settled immediately.
 *
 * Note the backing values do not follow the durations: 1 is the *longer* window.
 */
enum SignatureDuration: int
{
    case HOURS_60 = 1;

    case HOURS_2 = 2;
}
