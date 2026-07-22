<?php

namespace App\Enums;

enum SubscriptionPlan: string
{
    case FREE = 'free';
    case STARTER = 'starter';
    case BUSINESS = 'business';
}
