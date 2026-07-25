<?php

namespace App\Enums;

/**
 * Where a payment came from: recorded by staff at the counter, submitted by a
 * customer as a manual transfer through the public booking flow, or captured
 * by an online gateway.
 */
enum PaymentSource: string
{
    case STAFF = 'staff';
    case PUBLIC_MANUAL = 'public_manual';
    case GATEWAY = 'gateway';
}
