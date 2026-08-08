<?php

namespace App\Enums;

/**
 * A fixed list, not an owner-defined one: report grouping stays stable and
 * `salary` keeps a reserved meaning (payroll writes it).
 */
enum ExpenseCategory: string
{
    case RENT = 'rent';
    case UTILITIES = 'utilities';
    case SUPPLIES = 'supplies';
    case SALARY = 'salary';
    case MARKETING = 'marketing';
    case EQUIPMENT = 'equipment';
    case MAINTENANCE = 'maintenance';
    case OTHER = 'other';
}
