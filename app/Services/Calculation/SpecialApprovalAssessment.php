<?php

namespace App\Services\Calculation;

final readonly class SpecialApprovalAssessment
{
    /**
     * @param  list<array<string, mixed>>  $reasons
     */
    public function __construct(
        public bool $requiresSpecialApproval,
        public array $reasons,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $reasons
     */
    public static function fromReasons(array $reasons): self
    {
        $unique = [];
        $seen = [];

        foreach ($reasons as $reason) {
            $key = implode('|', [
                (string) ($reason['code'] ?? ''),
                (string) ($reason['position_id'] ?? ''),
                (string) ($reason['position_key'] ?? ''),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $reason;
        }

        return new self($unique !== [], $unique);
    }
}
