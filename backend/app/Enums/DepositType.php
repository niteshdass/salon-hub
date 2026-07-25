<?php

namespace App\Enums;

enum DepositType: string
{
    /** No up-front payment required to book. */
    case NONE = 'none';

    /** A percentage of the service price. */
    case PERCENT = 'percent';

    /** A flat amount, capped at the service price. */
    case FIXED = 'fixed';
}
