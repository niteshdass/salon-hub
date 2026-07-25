<?php

namespace App\Enums;

/**
 * A payment is verified the moment it is taken at the counter, but a deposit
 * a customer submits online (a transaction reference for a manual transfer)
 * sits pending until an owner confirms the money actually arrived. Only
 * verified payments count toward a booking's balance. A refunded payment was
 * captured but has since been returned, so it no longer counts either.
 */
enum PaymentStatus: string
{
    case VERIFIED = 'verified';
    case PENDING = 'pending';
    case REFUNDED = 'refunded';
}
