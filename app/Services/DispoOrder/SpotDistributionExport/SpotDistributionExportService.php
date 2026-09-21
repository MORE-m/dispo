<?php

namespace App\Services\DispoOrder\SpotDistributionExport;

use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Spreadsheet\SafeDownloadFilename;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * SPT-008: orchestriert Snapshot-Export, XLSX-Rendering und Erfolgs-Audit.
 */
final class SpotDistributionExportService
{
    public const string AUDIT_ACTION = 'dispo_order.spot_distribution.exported';

    public const string MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly SpotDistributionExportBuilder $builder = new SpotDistributionExportBuilder,
        private readonly SpotDistributionXlsxRenderer $renderer = new SpotDistributionXlsxRenderer,
        private readonly AuditLogger $auditLogger = new AuditLogger,
    ) {}

    /**
     * UI-/Capability-Hinweis ohne Exportausführung.
     *
     * @return array{
     *     enabled: bool,
     *     has_calendar_positions: bool,
     *     has_average_positions: bool,
     *     mixed_order: bool,
     *     exportable_row_count: int,
     *     disabled_reason: string|null
     * }
     */
    public function capability(DispoOrder $order): array
    {
        return $this->builder->capability($order);
    }

    public function download(DispoOrder $order, User $user): StreamedResponse
    {
        $document = $this->builder->build($order);

        try {
            $binary = $this->renderer->render($document);
        } catch (Throwable $exception) {
            report($exception);

            throw $exception;
        }

        $filename = SafeDownloadFilename::make(
            $document->dispoOrderNumber.'_Spotverteilung',
            'xlsx',
        );

        $this->auditLogger->record(
            $order,
            self::AUDIT_ACTION,
            $user,
            null,
            [
                'dispo_order_id' => $document->dispoOrderId,
                'exported_position_count' => $document->exportedPositionCount,
                'exported_row_count' => $document->rowCount(),
                'format' => 'xlsx',
            ],
        );

        return response()->streamDownload(
            static function () use ($binary): void {
                echo $binary;
            },
            $filename,
            [
                'Content-Type' => self::MIME_TYPE,
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
