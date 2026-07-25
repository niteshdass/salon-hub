<?php

namespace App\Enums;

/**
 * A payment is verified the moment it is taken at the counter, but a deposit
 * a customer submits online (a transaction reference for a manual transfer)
 * sits pending until an owner confirms the money actually arrived. Only
 * verified payments count toward a booking's balance.
 */
enum PaymentStatus: string
{
    case VERIFIED = 'verified';
    case PENDING = 'pending';
}
