<?php

namespace App\Enums;

/**
 * How a staff member is paid. `none` means the salon settles with them
 * outside the app — those staff are skipped by payroll entirely.
 */
enum PayType: string
{
    case NONE = 'none';
    case COMMISSION = 'commission';
    case SALARY = 'salary';
    case HYBRID = 'hybrid';

    public function paysSalary(): bool
    {
        return in_array($this, [self::SALARY, self::HYBRID], true);
    }

    public function paysCommission(): bool
    {
        return in_array($this, [self::COMMISSION, self::HYBRID], true);
    }
}
