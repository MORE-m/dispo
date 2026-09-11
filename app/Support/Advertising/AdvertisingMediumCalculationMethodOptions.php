<?php

namespace App\Support\Advertising;

/**
 * ADV-001c4a: Client-sichere Methodenoptionen eines Werbemittels.
 *
 * Enthält keine Engine-Profile, Algorithmusversionen oder Registry-Internals.
 */
final readonly class AdvertisingMediumCalculationMethodOptions
{
    public const SOURCE_CATEGORY = 'category';

    public const SOURCE_MEDIUM_OVERRIDE = 'medium_override';

    /**
     * @param  list<array{
     *     key: string,
     *     name: string,
     *     help_text: string|null,
     *     is_default: bool
     * }>  $methods
     */
    public function __construct(
        public int $mediumId,
        public string $source,
        public ?string $defaultCalculationMethodKey,
        public array $methods,
    ) {}

    /**
     * @return array{
     *     medium_id: int,
     *     source: string,
     *     default_calculation_method_key: string|null,
     *     methods: list<array{
     *         key: string,
     *         name: string,
     *         help_text: string|null,
     *         is_default: bool
     *     }>
     * }
     */
    public function toPayload(): array
    {
        return [
            'medium_id' => $this->mediumId,
            'source' => $this->source,
            'default_calculation_method_key' => $this->defaultCalculationMethodKey,
            'methods' => $this->methods,
        ];
    }
}
