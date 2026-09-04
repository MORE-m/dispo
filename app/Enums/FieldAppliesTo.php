<?php

namespace App\Enums;

enum FieldAppliesTo: string
{
    case Calculation = 'calculation';
    case DispoOrder = 'dispo_order';
    case Both = 'both';
}
