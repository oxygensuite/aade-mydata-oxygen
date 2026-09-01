<?php

namespace OxygenSuite\AadeMyData\Enums;

/**
 * The Network Service Provider that ran the POS transaction. It selects how the provider
 * assembles the text it signs, so it has to name the network the payment actually went
 * through. myDATA has no equivalent field: the ERP supplies it when asking for a signature.
 */
enum NSP: int
{
    /** Viva */
    case VIVA = 1;

    /** WebEcr */
    case WEB_ECR = 2;

    /** WorldLine */
    case WORLDLINE = 3;

    /** Edps */
    case EDPS = 4;

    /** EpaySoftPos */
    case EPAY_SOFT_POS = 5;
}
