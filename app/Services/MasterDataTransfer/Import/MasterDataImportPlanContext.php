<?php

namespace App\Services\MasterDataTransfer\Import;

/**
 * Virtuelle Planung: Schlüssel, die in derselben Phase-A-Transaktion angelegt werden.
 *
 * @internal
 */
final class MasterDataImportPlanContext
{
    /** @var array<string, true> */
    private array $methods = [];

    /** @var array<string, true> */
    private array $categories = [];

    /** @var array<string, true> */
    private array $media = [];

    /** @var array<string, true> */
    private array $inventories = [];

    /** @var array<string, true> */
    private array $fieldDefinitions = [];

    /** @var array<string, true> */
    private array $fieldDefinitionRevisions = [];

    /** @var array<string, true> */
    private array $fieldSets = [];

    /** @var array<string, true> */
    private array $fieldSetVersions = [];

    /** @var array<string, true> */
    private array $platforms = [];

    /**
     * @param  array<string, mixed>  $packageData
     */
    public function __construct(
        private readonly array $packageData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function packageData(): array
    {
        return $this->packageData;
    }

    public function markMethod(string $key): void
    {
        $this->methods[$key] = true;
    }

    public function willHaveMethod(string $key): bool
    {
        return isset($this->methods[$key]);
    }

    public function markCategory(string $key): void
    {
        $this->categories[$key] = true;
    }

    public function willHaveCategory(string $key): bool
    {
        return isset($this->categories[$key]);
    }

    public function markMedium(string $code): void
    {
        $this->media[$code] = true;
    }

    public function willHaveMedium(string $code): bool
    {
        return isset($this->media[$code]);
    }

    public function markInventory(string $code): void
    {
        $this->inventories[$code] = true;
    }

    public function willHaveInventory(string $code): bool
    {
        return isset($this->inventories[$code]);
    }

    public function markFieldDefinition(string $key): void
    {
        $this->fieldDefinitions[$key] = true;
    }

    public function willHaveFieldDefinition(string $key): bool
    {
        return isset($this->fieldDefinitions[$key]);
    }

    public function markFieldDefinitionRevision(string $definitionKey, int $revision): void
    {
        $this->fieldDefinitionRevisions[$definitionKey.'|'.$revision] = true;
    }

    public function willHaveFieldDefinitionRevision(string $definitionKey, int $revision): bool
    {
        return isset($this->fieldDefinitionRevisions[$definitionKey.'|'.$revision]);
    }

    public function markFieldSet(string $key): void
    {
        $this->fieldSets[$key] = true;
    }

    public function willHaveFieldSet(string $key): bool
    {
        return isset($this->fieldSets[$key]);
    }

    public function markFieldSetVersion(string $setKey, int $version): void
    {
        $this->fieldSetVersions[$setKey.'|'.$version] = true;
    }

    public function willHaveFieldSetVersion(string $setKey, int $version): bool
    {
        return isset($this->fieldSetVersions[$setKey.'|'.$version]);
    }

    public function markPlatform(string $code): void
    {
        $this->platforms[$code] = true;
    }

    public function willHavePlatform(string $code): bool
    {
        return isset($this->platforms[$code]);
    }

    public function packageHasFieldDefinitionRevision(string $definitionKey, int $revision): bool
    {
        foreach ($this->packageData['data/field_definition_revisions.json'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['field_definition_key'] ?? '') === $definitionKey
                && (int) ($row['revision'] ?? -1) === $revision) {
                return true;
            }
        }

        return false;
    }
}
