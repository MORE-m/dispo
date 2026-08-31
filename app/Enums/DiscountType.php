<?php

namespace App\Enums;

enum DiscountType: string
{
    case Quantity = 'quantity';
    case Special = 'special';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Quantity => 'Mengenrabatt',
            self::Special => 'Sonderrabatt',
            self::Other => 'Sonstiger Rabatt',
        };
    }

    public function requiresCustomLabel(): bool
    {
        return $this === self::Other;
    }

    /**
     * @return list<array{value: string, label: string, requires_custom_label: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'requires_custom_label' => $type->requiresCustomLabel(),
            ],
            self::cases(),
        );
    }
}
