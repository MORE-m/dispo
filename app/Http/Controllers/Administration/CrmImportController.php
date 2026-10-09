<?php

namespace App\Http\Controllers\Administration;

use App\Exceptions\CrmImportConflictException;
use App\Http\Controllers\Controller;
use App\Models\CrmImport;
use App\Services\Crm\CrmImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BL-P2-03a / PO-BLP203-1 B1+C1: Salesforce-CSV-Import.
 */
class CrmImportController extends Controller
{
    public function __construct(
        private readonly CrmImportService $imports,
    ) {}

    public function create(Request $request): Response
    {
        $this->authorize('import-crm-accounts');

        return Inertia::render('administration/crm/import', [
            'notes' => [
                'format' => 'UTF-8 CSV, Semikolon; Spalten Accountname, Meridian-ID, Account-ID, Rechnungs-E-Mail, Account-Datensatztyp',
                'types' => 'Account KUNDE / Account AGENTUR',
                'addresses' => 'Keine Adressspalten in diesem Export – Adressen werden nicht erfunden oder geleert.',
            ],
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->authorize('import-crm-accounts');
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $import = $this->imports->upload($request->file('file'), $request->user());

        return response()->json([
            'import' => $this->serialize($import),
        ]);
    }

    public function apply(Request $request, CrmImport $crmImport): JsonResponse
    {
        $this->authorize('import-crm-accounts');
        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'catalog_fingerprint' => ['required', 'string', 'size:64'],
        ]);

        try {
            $import = $this->imports->apply(
                $crmImport,
                $request->user(),
                $validated['fingerprint'],
                $validated['catalog_fingerprint'],
            );
        } catch (CrmImportConflictException $e) {
            $refreshed = $e->import ?? ($crmImport->fresh() ?? $crmImport);

            return response()->json([
                'message' => $e->getMessage(),
                'import' => $this->serialize($refreshed),
                'preview_refreshed' => true,
            ], 409);
        }

        return response()->json([
            'import' => $this->serialize($import),
        ]);
    }

    public function show(Request $request, CrmImport $crmImport): JsonResponse
    {
        $this->authorize('import-crm-accounts');

        return response()->json([
            'import' => $this->serialize($crmImport),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CrmImport $import): array
    {
        return [
            'id' => $import->id,
            'status' => $import->status->value,
            'original_filename' => $import->original_filename,
            'checksum_sha256' => $import->checksum_sha256,
            'row_count' => $import->row_count,
            'valid_row_count' => $import->valid_row_count,
            'error_count' => $import->error_count,
            'warning_count' => $import->warning_count,
            'preview' => $import->preview,
            'report' => $import->report,
            'status_label' => $import->status->value,
            'fingerprint' => $import->fingerprint,
            'catalog_fingerprint' => $import->catalog_fingerprint,
            'validated_at' => $import->validated_at?->toIso8601String(),
            'applied_at' => $import->applied_at?->toIso8601String(),
        ];
    }
}
