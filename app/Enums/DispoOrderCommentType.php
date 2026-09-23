<?php

namespace App\Enums;

enum DispoOrderCommentType: string
{
    case SalesInquiry = 'sales_inquiry';
    case SalesInquiryResponse = 'sales_inquiry_response';

    public function label(): string
    {
        return match ($this) {
            self::SalesInquiry => 'Rückfrage',
            self::SalesInquiryResponse => 'Antwort',
        };
    }
}
