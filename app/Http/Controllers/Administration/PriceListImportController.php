<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\PriceListImport;
use App\Services\PriceList\Import\PriceListImportService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BL-P4-01b: Excel-Import für Spot-Stundenpreislisten (ohne Auto-Aktivierung).
 */
class PriceListImportController extends Controller
{
    public function create(): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/price-lists/import', [
            'currentYear' => PriceListCalendar::currentYear(),
            'urls' => [
                'upload' => route('administration.price-lists.import.upload'),
                'index' => route('administration.price-lists.index'),
            ],
            'limits' => [
                'max_bytes' => 50 * 1024 * 1024,
                'extensions' => ['xlsx', 'xls'],
            ],
            'contractNote' => 'Kanonischer V1-Vertrag: Spalten inventory_code (oder inventory), hour, day_group, second_price; optional year. '
                .'Alternativ Blattname = Inventarcode/-name/Alias und Spalten hour, day_group, second_price. '
                .'Aliase: „radio ffn“ → ffn Hamburg Plus, „BOLLERWAGEN“ → RADIO BOLLERWAGEN DAB+ Hamburg. '
                .'PO-PRI-HOURS-1: fehlende Tagesgruppen sind erlaubt. Keine Auto-Aktivierung.',
        ]);
    }

    public function upload(Request $request, PriceListImportService $imports): JsonResponse
    {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'file' => ['required', 'file'],
        ]);

        $import = $imports->upload(
            $request->file('file'),
            (int) $validated['year'],
            $request->user(),
        );

        $preview = $imports->validate($import, $request->user());

        return response()->json([
            'import' => $this->importPayload($import->fresh() ?? $import),
            'preview' => $this->publicPreview($preview),
            'urls' => [
                'confirm' => route('administration.price-lists.import.confirm', $import),
                'revalidate' => route('administration.price-lists.import.validate', $import),
            ],
        ]);
    }

    public function validateImport(
        Request $request,
        PriceListImport $priceListImport,
        PriceListImportService $imports,
    ): JsonResponse {
        $this->authorize('access-administration');

        $preview = $imports->validate($priceListImport, $request->user());

        return response()->json([
            'import' => $this->importPayload($priceListImport->fresh() ?? $priceListImport),
            'preview' => $this->publicPreview($preview),
            'urls' => [
                'confirm' => route('administration.price-lists.import.confirm', $priceListImport),
                'revalidate' => route('administration.price-lists.import.validate', $priceListImport),
            ],
        ]);
    }

    public function confirm(
        Request $request,
        PriceListImport $priceListImport,
        PriceListImportService $imports,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $lists = $imports->confirm(
            $priceListImport,
            $validated['fingerprint'],
            $request->user(),
        );

        return response()->json([
            'import' => $this->importPayload($priceListImport->fresh() ?? $priceListImport),
            'created' => array_map(fn ($list): array => [
                'id' => (int) $list->id,
                'name' => $list->name,
                'year' => (int) $list->year,
                'version' => $list->version,
                'inventory_id' => (int) $list->inventory_id,
                'inventory_name' => $list->inventory?->name,
                'status' => $list->status->value,
                'url' => route('administration.price-lists.show', $list),
            ], $lists),
            'message' => 'Import abgeschlossen. Es wurden Entwürfe angelegt – bitte über den bestehenden Lifecycle veröffentlichen.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function importPayload(PriceListImport $import): array
    {
        return [
            'id' => (int) $import->id,
            'year' => (int) $import->year,
            'status' => $import->status->value,
            'status_label' => $import->status->label(),
            'original_filename' => $import->original_filename,
            'checksum_sha256' => $import->checksum_sha256,
            'file_size' => (int) $import->file_size,
            'error_count' => (int) $import->error_count,
            'warning_count' => (int) $import->warning_count,
            'valid_row_count' => (int) $import->valid_row_count,
            'fingerprint' => $import->fingerprint,
            'created_price_list_ids' => $import->created_price_list_ids ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function publicPreview(array $preview): array
    {
        unset($preview['drafts']);

        return $preview;
    }
}
