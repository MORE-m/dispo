<?php

namespace App\Support\Calculation;

use App\Enums\CalculationKind;
use App\Enums\SpotCalculationMethod;

/**
 * ADV-001c2: unteilbarer Positions-Freeze-Vertrag.
 *
 * Vier Felder gemeinsam gesetzt oder gemeinsam NULL (Übergangsphase).
 */
final readonly class CalculationMethodFreezeDescriptor
{
    public function __construct(
        public string $engineProfileKey,
        public string $calculationMethodKey,
        public string $calculationMethodName,
        public string $algorithmVersion,
    ) {}

    /**
     * @return array{
     *     engine_profile_key: string,
     *     calculation_method_key: string,
     *     calculation_method_name: string,
     *     algorithm_version: string
     * }
     */
    public function toPersistenceArray(): array
    {
        return [
            'engine_profile_key' => $this->engineProfileKey,
            'calculation_method_key' => $this->calculationMethodKey,
            'calculation_method_name' => $this->calculationMethodName,
            'algorithm_version' => $this->algorithmVersion,
        ];
    }

    public function legacyKind(): CalculationKind
    {
        return match ($this->engineProfileKey) {
            EngineProfileRegistry::PROFILE_SPOT_CLASSIC => CalculationKind::SpotClassic,
            default => throw new \InvalidArgumentException(
                "Kein Legacy-kind für engine_profile_key „{$this->engineProfileKey}“.",
            ),
        };
    }

    public function legacySpotMethod(): SpotCalculationMethod
    {
        return SpotCalculationMethod::from($this->calculationMethodKey);
    }

    public function equals(self $other): bool
    {
        return $this->engineProfileKey === $other->engineProfileKey
            && $this->calculationMethodKey === $other->calculationMethodKey
            && $this->calculationMethodName === $other->calculationMethodName
            && $this->algorithmVersion === $other->algorithmVersion;
    }
}
