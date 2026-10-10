<?php

namespace App\Enums;

enum InvoiceRecipient: string
{
    case Customer = 'customer';
    case Agency = 'agency';
}
