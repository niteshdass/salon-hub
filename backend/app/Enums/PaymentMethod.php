<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case CARD = 'card';
    case ONLINE = 'online';
    case BANK_TRANSFER = 'bank_transfer';
    case OTHER = 'other';
}
