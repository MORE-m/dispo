<?php

namespace App\Enums;

/**
 * Kanonische Dispo-Upload-Kategorien (BL-P9-01 / UPL-004).
 * BL-P9-01a: {@see self::CustomerConfirmation} produktiv.
 * BL-P9-01b: feste Materialkategorien produktiv (PO-BLP901B-1).
 */
enum DispoOrderUploadCategory: string
{
    case CustomerConfirmation = 'customer_confirmation';
    case AudioMotif = 'audio_motif';
    case Briefing = 'briefing';
    case ScriptText = 'script_text';
    case LayoutGraphics = 'layout_graphics';
    case EventDocuments = 'event_documents';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerConfirmation => 'Kundenbestätigung',
            self::AudioMotif => 'Audio-Motiv',
            self::Briefing => 'Briefing',
            self::ScriptText => 'Skript/Text',
            self::LayoutGraphics => 'Layout/Grafik',
            self::EventDocuments => 'Event-Unterlagen',
            self::Other => 'Sonstiges',
        };
    }

    /**
     * In BL-P9-01a produktiv nutzbare Upload-Kategorien.
     *
     * @return list<self>
     */
    public static function productiveInBlP901a(): array
    {
        return [self::CustomerConfirmation];
    }

    public function isProductiveInBlP901a(): bool
    {
        return in_array($this, self::productiveInBlP901a(), true);
    }

    /**
     * Feste Materialkategorien (BL-P9-01b / PO-BLP901B-1).
     * Ohne customer_confirmation (eigener Sonderpfad).
     *
     * @return list<self>
     */
    public static function materialCategories(): array
    {
        return [
            self::AudioMotif,
            self::Briefing,
            self::ScriptText,
            self::LayoutGraphics,
            self::EventDocuments,
            self::Other,
        ];
    }

    public function isMaterialCategory(): bool
    {
        return in_array($this, self::materialCategories(), true);
    }

    /**
     * @return list<string>
     */
    public static function materialCategoryValues(): array
    {
        return array_map(
            static fn (self $category): string => $category->value,
            self::materialCategories(),
        );
    }

    public function isAudioMotif(): bool
    {
        return $this === self::AudioMotif;
    }
}
