<?php

namespace App\Enums;

enum PayrollRunStatus: string
{
    case DRAFT = 'draft';
    case FINALIZED = 'finalized';
}
