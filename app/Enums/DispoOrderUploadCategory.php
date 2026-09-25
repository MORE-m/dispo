<?php

namespace App\Enums;

/**
 * Kanonische Dispo-Upload-Kategorien (BL-P9-01 / UPL-004).
 * In BL-P9-01a ist nur {@see self::CustomerConfirmation} produktiv freigegeben.
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
     * In diesem Slice produktiv nutzbare Upload-Kategorien.
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
}
