<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\DispoOrderUploadCategory;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Exceptions\DispoOrderConflictException;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\DispoOrderUpload;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DynamicField\FileFieldValueContract;
use App\Support\PrivateFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Privates Dispo-Upload-Fundament (BL-P9-01a / BL-P9-01b).
 * Produktiv: Kundenbestätigung + feste Materialkategorien (PO-BLP901B-1).
 * Kein Hard-Delete (UPL-005). Keine Freigabeinvalidierung. Kein Status-Automatismus.
 */
final class DispoOrderUploadService
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    public const STORAGE_PREFIX = 'dispo-orders/';

    /**
     * Status, in denen Materialuploads erlaubt sind (PO-BLP901B-1).
     *
     * @var list<DispoOrderStatus>
     */
    public const MATERIAL_UPLOAD_STATUSES = [
        DispoOrderStatus::Draft,
        DispoOrderStatus::AtDisposition,
        DispoOrderStatus::InProgress,
        DispoOrderStatus::SalesInquiry,
        DispoOrderStatus::MaterialMissing,
        DispoOrderStatus::MaterialReceived,
    ];

    /**
     * Zulässige reale MIME-Typen für audio_motif (UPL-007).
     *
     * @var list<string>
     */
    public const AUDIO_MIME_TYPES = [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/vnd.wave',
    ];

    /**
     * MIME-Baseline: keine erfundene Whitelist für allgemeine Materialkategorien.
     * Ablehnen bekannter ausführbarer/gefährlicher Typen.
     *
     * @var list<string>
     */
    private const BLOCKED_MIME_PREFIXES = [
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-executable',
        'application/x-sharedlib',
        'application/x-httpd-php',
        'application/x-php',
        'text/x-php',
        'application/javascript',
        'text/javascript',
    ];

    public function __construct(
        private readonly PrivateFileStorage $files,
        private readonly AuditLogger $audit,
    ) {}

    public function uploadCustomerConfirmation(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        UploadedFile $file,
    ): DispoOrderUpload {
        if (! Gate::forUser($user)->allows('uploadCustomerConfirmation', $order)) {
            abort(403);
        }

        $this->assertUploadFile($file);

        return $this->persistUpload(
            $order,
            $user,
            $expectedLockVersion,
            $file,
            DispoOrderUploadCategory::CustomerConfirmation,
            function (DispoOrder $locked) use ($user): void {
                if ($locked->status !== DispoOrderStatus::Draft) {
                    throw ValidationException::withMessages([
                        'order' => 'Kundenbestätigungs-Uploads sind nur im Entwurf möglich.',
                    ]);
                }

                if (! Gate::forUser($user)->allows('uploadCustomerConfirmation', $locked)) {
                    abort(403);
                }
            },
        );
    }

    public function uploadMaterial(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        DispoOrderUploadCategory $category,
        UploadedFile $file,
    ): DispoOrderUpload {
        if (! $category->isMaterialCategory()) {
            throw ValidationException::withMessages([
                'category' => 'Diese Upload-Kategorie ist über den Material-Endpoint nicht zulässig.',
            ]);
        }

        if (! Gate::forUser($user)->allows('uploadMaterial', $order)) {
            abort(403);
        }

        $this->assertUploadFile($file, $category);

        return $this->persistUpload(
            $order,
            $user,
            $expectedLockVersion,
            $file,
            $category,
            function (DispoOrder $locked) use ($user): void {
                if (! $this->statusAllowsMaterialUpload($locked->status)) {
                    throw ValidationException::withMessages([
                        'order' => 'Materialuploads sind in diesem Status nicht erlaubt.',
                    ]);
                }

                if (! Gate::forUser($user)->allows('uploadMaterial', $locked)) {
                    abort(403);
                }
            },
        );
    }

    public function uploadDynamicFieldFile(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        UploadedFile $file,
        string $fieldKey,
        ?int $positionId,
    ): DispoOrderUpload {
        if (! Gate::forUser($user)->allows('uploadMaterial', $order)) {
            abort(403);
        }

        $resolved = $this->resolveDynamicFieldUploadTarget($order, $fieldKey, $positionId);
        /** @var SnapshotFieldDefinition $def */
        $def = $resolved['definition'];
        /** @var DispoOrderPosition|null $position */
        $position = $resolved['position'];

        $allowedMimeTypes = $this->allowedMimeTypesFromValidation($def->validation_json);
        $this->assertUploadFile($file, null, $allowedMimeTypes);

        $orderId = (int) $order->id;
        $uuid = Str::uuid()->toString();
        $storagePath = self::STORAGE_PREFIX.$orderId.'/uploads/'.$uuid;
        $this->assertSafeStoragePath($storagePath);

        $prepared = $this->prepareUploadedFilePayload($file, $storagePath);

        try {
            return DB::transaction(function () use (
                $order,
                $user,
                $expectedLockVersion,
                $fieldKey,
                $def,
                $position,
                $prepared,
            ): DispoOrderUpload {
                $locked = $this->lockOrder($order);
                $this->assertLockVersion($locked, $expectedLockVersion);

                if (! $this->statusAllowsMaterialUpload($locked->status)) {
                    throw ValidationException::withMessages([
                        'order' => 'Datei-Uploads für dynamische Felder sind in diesem Status nicht erlaubt.',
                    ]);
                }

                if (! Gate::forUser($user)->allows('uploadMaterial', $locked)) {
                    abort(403);
                }

                $resolved = $this->resolveDynamicFieldUploadTarget($locked, $fieldKey, $position?->id);
                $def = $resolved['definition'];
                $position = $resolved['position'];

                $previousUploadId = $this->readCurrentDynamicFieldUploadId($locked, $def, $position);
                if ($previousUploadId !== null) {
                    $this->historizeDynamicFieldUpload($locked, $previousUploadId, $user, $fieldKey);
                }

                $positionLabel = $position === null
                    ? null
                    : $this->positionLabelSnapshot($position);

                $upload = DispoOrderUpload::query()->create([
                    'dispo_order_id' => $locked->id,
                    'category' => DispoOrderUploadCategory::DynamicField,
                    'field_key' => $def->key,
                    'field_label_snapshot' => $def->label,
                    'snapshot_field_definition_id' => $def->id,
                    'dispo_order_position_id' => $position?->id,
                    'position_label_snapshot' => $positionLabel,
                    'original_filename' => $prepared['original_filename'],
                    'storage_path' => $prepared['storage_path'],
                    'mime_type' => $prepared['mime'],
                    'size_bytes' => $prepared['size_bytes'],
                    'sha256' => $prepared['sha256'],
                    'uploaded_by_user_id' => $user->id,
                    'uploaded_by_name_snapshot' => $user->name,
                    'uploaded_at' => Carbon::now(),
                ]);

                $this->writeDynamicFieldUploadReference($locked, $def, $position, (int) $upload->id);

                $locked->lock_version = $locked->lock_version + 1;
                $locked->save();

                $this->audit->record(
                    $locked,
                    'dispo_order.upload.created',
                    $user,
                    null,
                    [
                        'upload_id' => $upload->id,
                        'category' => $upload->category->value,
                        'field_key' => $def->key,
                        'original_filename' => $upload->original_filename,
                        'mime_type' => $upload->mime_type,
                        'size_bytes' => $upload->size_bytes,
                        'sha256' => $upload->sha256,
                        'lock_version' => $locked->lock_version,
                    ],
                );

                return $upload->fresh() ?? $upload;
            });
        } catch (Throwable $exception) {
            if ($this->files->exists($prepared['storage_path'])) {
                try {
                    $this->files->disk()->delete($prepared['storage_path']);
                } catch (Throwable) {
                }
            }

            throw $exception;
        }
    }

    public function archive(
        DispoOrder $order,
        DispoOrderUpload $upload,
        User $user,
        int $expectedLockVersion,
    ): DispoOrderUpload {
        if (! Gate::forUser($user)->allows('archiveUpload', $order)) {
            abort(403);
        }

        $this->assertUploadBelongsToOrder($order, $upload);

        return DB::transaction(function () use ($order, $upload, $user, $expectedLockVersion): DispoOrderUpload {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);

            if (! Gate::forUser($user)->allows('archiveUpload', $locked)) {
                abort(403);
            }

            $lockedUpload = DispoOrderUpload::query()
                ->whereKey($upload->id)
                ->where('dispo_order_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUpload->isArchived()) {
                throw ValidationException::withMessages([
                    'upload' => 'Die Datei ist bereits archiviert.',
                ]);
            }

            $lockedUpload->archived_at = Carbon::now();
            $lockedUpload->archived_by_user_id = $user->id;
            $lockedUpload->archived_by_name_snapshot = $user->name;
            $lockedUpload->save();

            // Draft-Archiv mutiert den Order-Zustand (UPL-001-Grundlage).
            // Nach Submit: Snapshot unverändert; lock_version trotzdem +1 für Race-Schutz.
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->clearDynamicFieldReferenceIfCurrent($locked, $lockedUpload);

            $auditAfter = [
                'upload_id' => $lockedUpload->id,
                'category' => $lockedUpload->category->value,
                'archived' => true,
                'archived_at' => $lockedUpload->archived_at->toIso8601String(),
                'lock_version' => $locked->lock_version,
            ];
            if ($lockedUpload->field_key !== null) {
                $auditAfter['field_key'] = $lockedUpload->field_key;
            }

            $this->audit->record(
                $locked,
                'dispo_order.upload.archived',
                $user,
                [
                    'upload_id' => $lockedUpload->id,
                    'category' => $lockedUpload->category->value,
                    'archived' => false,
                ],
                $auditAfter,
            );

            return $lockedUpload->fresh() ?? $lockedUpload;
        });
    }

    public function download(
        DispoOrder $order,
        DispoOrderUpload $upload,
        User $user,
    ): StreamedResponse {
        if (! Gate::forUser($user)->allows('downloadUpload', $order)) {
            abort(403);
        }

        $this->assertUploadBelongsToOrder($order, $upload);
        $this->assertSafeStoragePath($upload->storage_path);

        if (! $this->files->exists($upload->storage_path)) {
            abort(404, 'Die Datei ist nicht verfügbar.');
        }

        $this->audit->record(
            $order,
            'dispo_order.upload.downloaded',
            $user,
            null,
            [
                'upload_id' => $upload->id,
                'category' => $upload->category->value,
                'original_filename' => $upload->original_filename,
            ],
        );

        $filename = $this->sanitizeOriginalFilename($upload->original_filename);
        $mime = $upload->mime_type ?: 'application/octet-stream';

        return response()->streamDownload(function () use ($upload): void {
            $stream = $this->files->disk()->readStream($upload->storage_path);
            if ($stream === null) {
                return;
            }
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Autorisierte Inline-Wiedergabe für audio_motif (UPL-007).
     * Kein Audit (kein View-Logging / Range-Spam).
     */
    public function stream(
        DispoOrder $order,
        DispoOrderUpload $upload,
        User $user,
    ): BinaryFileResponse {
        if (! Gate::forUser($user)->allows('streamUpload', $order)) {
            abort(403);
        }

        $this->assertUploadBelongsToOrder($order, $upload);

        if (! $upload->category->isAudioMotif()) {
            abort(404);
        }

        $this->assertSafeStoragePath($upload->storage_path);

        if (! $this->files->exists($upload->storage_path)) {
            abort(404, 'Die Datei ist nicht verfügbar.');
        }

        $diskName = (string) config('dispo.files_disk');
        $absolute = Storage::disk($diskName)->path($upload->storage_path);
        if (! is_file($absolute)) {
            abort(404, 'Die Datei ist nicht verfügbar.');
        }

        $filename = $this->sanitizeOriginalFilename($upload->original_filename);
        $mime = $upload->mime_type ?: 'application/octet-stream';

        $response = response()->file($absolute, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);

        $response->setContentDisposition('inline', $filename);

        return $response;
    }

    public function activeCustomerConfirmation(DispoOrder $order): ?DispoOrderUpload
    {
        return DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::CustomerConfirmation->value)
            ->whereNull('archived_at')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listProp(DispoOrder $order): array
    {
        $activeId = $this->activeCustomerConfirmation($order)?->id;

        return array_values(DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DispoOrderUpload $upload): array => $this->serializeUpload($upload, $order, $activeId))
            ->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function materialCategoryOptionsProp(): array
    {
        return array_map(
            static fn (DispoOrderUploadCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
            ],
            DispoOrderUploadCategory::materialCategories(),
        );
    }

    public function canUploadMaterialFor(DispoOrder $order, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->allows('uploadMaterial', $order)
            && $this->statusAllowsMaterialUpload($order->status);
    }

    public function statusAllowsMaterialUpload(DispoOrderStatus $status): bool
    {
        return in_array($status, self::MATERIAL_UPLOAD_STATUSES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeUpload(
        DispoOrderUpload $upload,
        DispoOrder $order,
        ?int $activeCustomerConfirmationId = null,
    ): array {
        if ($activeCustomerConfirmationId === null
            && $upload->category === DispoOrderUploadCategory::CustomerConfirmation
            && $upload->isActive()
        ) {
            $activeCustomerConfirmationId = $this->activeCustomerConfirmation($order)?->id;
        }

        $activeConfirmation = $upload->category === DispoOrderUploadCategory::CustomerConfirmation
            && $upload->isActive()
            && $activeCustomerConfirmationId !== null
            && $activeCustomerConfirmationId === $upload->id;

        $payload = [
            'id' => $upload->id,
            'category' => $upload->category->value,
            'category_label' => $upload->category->label(),
            'original_filename' => $upload->original_filename,
            'mime_type' => $upload->mime_type,
            'size_bytes' => $upload->size_bytes,
            'sha256' => $upload->sha256,
            'uploaded_by_name' => $upload->uploaded_by_name_snapshot,
            'uploaded_at' => $upload->uploaded_at->toIso8601String(),
            'archived' => $upload->isArchived(),
            'archived_at' => $upload->archived_at?->toIso8601String(),
            'archived_by_name' => $upload->archived_by_name_snapshot,
            'is_active_customer_confirmation' => $activeConfirmation,
            'download_url' => route('dispo-orders.uploads.download', [
                'dispoOrder' => $order->id,
                'upload' => $upload->id,
            ]),
            'stream_url' => null,
        ];

        if ($upload->category->isAudioMotif()) {
            $payload['stream_url'] = route('dispo-orders.uploads.stream', [
                'dispoOrder' => $order->id,
                'upload' => $upload->id,
            ]);
        }

        $payload['field_key'] = $upload->field_key;
        $payload['field_label'] = $upload->field_label_snapshot;
        $payload['position_id'] = $upload->dispo_order_position_id;
        $payload['position_label'] = $upload->position_label_snapshot;

        return $payload;
    }

    public function hasActiveCustomerConfirmation(DispoOrder $order): bool
    {
        return $this->activeCustomerConfirmation($order) !== null;
    }

    /**
     * @param  callable(DispoOrder): void  $assertWithinTransaction
     */
    private function persistUpload(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        UploadedFile $file,
        DispoOrderUploadCategory $category,
        callable $assertWithinTransaction,
    ): DispoOrderUpload {
        $orderId = (int) $order->id;
        $uuid = Str::uuid()->toString();
        $storagePath = self::STORAGE_PREFIX.$orderId.'/uploads/'.$uuid;
        $this->assertSafeStoragePath($storagePath);

        $absolute = $file->getRealPath();
        if ($absolute === false || $absolute === '' || ! is_file($absolute)) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gelesen werden.',
            ]);
        }

        $sha256 = hash_file('sha256', $absolute);
        if ($sha256 === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gehasht werden.',
            ]);
        }

        $sizeBytes = (int) $file->getSize();
        $mime = $this->normalizeMime($file);
        $originalFilename = $this->sanitizeOriginalFilename($file->getClientOriginalName());

        $stream = fopen($absolute, 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gelesen werden.',
            ]);
        }

        try {
            $written = $this->files->disk()->writeStream($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written !== true) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gespeichert werden.',
            ]);
        }

        try {
            return DB::transaction(function () use (
                $order,
                $user,
                $expectedLockVersion,
                $storagePath,
                $originalFilename,
                $mime,
                $sizeBytes,
                $sha256,
                $category,
                $assertWithinTransaction,
            ): DispoOrderUpload {
                $locked = $this->lockOrder($order);
                $this->assertLockVersion($locked, $expectedLockVersion);
                $assertWithinTransaction($locked);

                $upload = DispoOrderUpload::query()->create([
                    'dispo_order_id' => $locked->id,
                    'category' => $category,
                    'original_filename' => $originalFilename,
                    'storage_path' => $storagePath,
                    'mime_type' => $mime,
                    'size_bytes' => $sizeBytes,
                    'sha256' => $sha256,
                    'uploaded_by_user_id' => $user->id,
                    'uploaded_by_name_snapshot' => $user->name,
                    'uploaded_at' => Carbon::now(),
                ]);

                $locked->lock_version = $locked->lock_version + 1;
                $locked->save();

                $this->audit->record(
                    $locked,
                    'dispo_order.upload.created',
                    $user,
                    null,
                    [
                        'upload_id' => $upload->id,
                        'category' => $upload->category->value,
                        'original_filename' => $upload->original_filename,
                        'mime_type' => $upload->mime_type,
                        'size_bytes' => $upload->size_bytes,
                        'sha256' => $upload->sha256,
                        'lock_version' => $locked->lock_version,
                    ],
                );

                return $upload->fresh() ?? $upload;
            });
        } catch (Throwable $exception) {
            if ($this->files->exists($storagePath)) {
                try {
                    $this->files->disk()->delete($storagePath);
                } catch (Throwable) {
                    // Best effort: orphan cleanup failure must not mask original error.
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  list<string>|null  $allowedMimeTypes
     */
    private function assertUploadFile(
        UploadedFile $file,
        ?DispoOrderUploadCategory $category = null,
        ?array $allowedMimeTypes = null,
    ): void {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei ist ungültig oder der Upload ist fehlgeschlagen.',
            ]);
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw ValidationException::withMessages([
                'file' => 'Eine Datei ist erforderlich.',
            ]);
        }

        if ($size > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei überschreitet die maximale Größe von 50 MB.',
            ]);
        }

        $mime = $this->normalizeMime($file);

        if ($category?->isAudioMotif()) {
            if ($mime === null || ! in_array($mime, self::AUDIO_MIME_TYPES, true)) {
                throw ValidationException::withMessages([
                    'file' => 'Audio-Motive sind nur als MP3 oder WAV zulässig.',
                ]);
            }

            return;
        }

        foreach (self::BLOCKED_MIME_PREFIXES as $blocked) {
            if ($mime !== null && str_starts_with($mime, $blocked)) {
                throw ValidationException::withMessages([
                    'file' => 'Dieser Dateityp ist aus Sicherheitsgründen nicht zulässig.',
                ]);
            }
        }

        if ($allowedMimeTypes !== null && $allowedMimeTypes !== []) {
            if ($mime === null || ! in_array($mime, $allowedMimeTypes, true)) {
                throw ValidationException::withMessages([
                    'file' => 'Dieser Dateityp ist für dieses Feld nicht zulässig.',
                ]);
            }
        }
    }

    private function normalizeMime(UploadedFile $file): ?string
    {
        $mime = $file->getMimeType();
        if (! is_string($mime) || trim($mime) === '') {
            return null;
        }

        return strtolower(trim($mime));
    }

    private function sanitizeOriginalFilename(?string $name): string
    {
        $name = $name ?? 'upload.bin';
        $name = str_replace(["\0", "\r", "\n"], '', $name);
        $name = basename(str_replace(['\\', '/'], '-', $name));
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'upload.bin';
        }

        return mb_substr($name, 0, 255);
    }

    private function assertSafeStoragePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            throw ValidationException::withMessages([
                'file' => 'Ungültiger Speicherpfad.',
            ]);
        }

        if (! str_starts_with($path, self::STORAGE_PREFIX)) {
            throw ValidationException::withMessages([
                'file' => 'Ungültiger Speicherpfad.',
            ]);
        }
    }

    private function assertUploadBelongsToOrder(DispoOrder $order, DispoOrderUpload $upload): void
    {
        if ((int) $upload->dispo_order_id !== (int) $order->id) {
            abort(404);
        }
    }

    private function lockOrder(DispoOrder $order): DispoOrder
    {
        return DispoOrder::query()
            ->whereKey($order->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertLockVersion(DispoOrder $order, int $expectedLockVersion): void
    {
        if ($order->lock_version !== $expectedLockVersion) {
            throw new DispoOrderConflictException(
                'Der Dispoauftrag wurde parallel geändert. Bitte die Seite neu laden.',
            );
        }
    }

    /**
     * @return array{
     *     definition: SnapshotFieldDefinition,
     *     owner_snapshot: ConfigurationSnapshot,
     *     position: DispoOrderPosition|null
     * }
     */
    private function resolveDynamicFieldUploadTarget(
        DispoOrder $order,
        string $fieldKey,
        ?int $positionId,
    ): array {
        $fieldKey = trim($fieldKey);
        if ($fieldKey === '') {
            throw ValidationException::withMessages([
                'field_key' => 'field_key ist erforderlich.',
            ]);
        }

        $order->loadMissing(['configurationSnapshot', 'positions']);
        $baseSnapshot = $order->configurationSnapshot;
        $baseSnapshot->loadMissing('fieldDefinitions');

        $position = null;
        $ownerSnapshot = $baseSnapshot;

        if ($positionId !== null) {
            $position = $order->positions->firstWhere('id', $positionId);
            if ($position === null) {
                throw ValidationException::withMessages([
                    'position_id' => 'Die Position gehört nicht zu diesem Dispoauftrag.',
                ]);
            }
            $ownerSnapshot = $this->positionEffectiveSnapshot($baseSnapshot, $position);
        }

        $def = $ownerSnapshot->fieldDefinitions->firstWhere('key', $fieldKey);
        if ($def === null) {
            throw ValidationException::withMessages([
                'field_key' => 'Unbekanntes dynamisches Feld.',
            ]);
        }

        if ($def->field_type !== FieldType::File) {
            throw ValidationException::withMessages([
                'field_key' => 'Das Feld ist kein Datei-Feld.',
            ]);
        }

        if ($def->applies_to !== FieldAppliesTo::DispoOrder) {
            throw ValidationException::withMessages([
                'field_key' => 'Datei-Felder sind nur für Dispoauftrag-Felder (applies_to=dispo_order) zulässig.',
            ]);
        }

        if ($def->scope === FieldScope::Header && $positionId !== null) {
            throw ValidationException::withMessages([
                'position_id' => 'Header-Datei-Felder dürfen keine position_id tragen.',
            ]);
        }

        if ($def->scope === FieldScope::Position && $positionId === null) {
            throw ValidationException::withMessages([
                'position_id' => 'Für Positions-Datei-Felder ist position_id erforderlich.',
            ]);
        }

        return [
            'definition' => $def,
            'owner_snapshot' => $ownerSnapshot,
            'position' => $position,
        ];
    }

    private function positionEffectiveSnapshot(
        ConfigurationSnapshot $baseSnapshot,
        DispoOrderPosition $position,
    ): ConfigurationSnapshot {
        if ((int) $baseSnapshot->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            return $baseSnapshot;
        }

        $position->loadMissing('effectiveConfigurationSnapshot.fieldDefinitions');
        $effective = $position->effectiveConfigurationSnapshot;
        if ($effective === null) {
            throw ValidationException::withMessages([
                'position_id' => 'Die Position hat keinen Effektiv-Snapshot.',
            ]);
        }

        $effective->loadMissing('fieldDefinitions');

        return $effective;
    }

    private function positionLabelSnapshot(DispoOrderPosition $position): string
    {
        $inventory = trim((string) $position->inventory_name);
        $medium = trim((string) $position->advertising_medium_name);
        if ($inventory === '' && $medium === '') {
            return 'Position #'.$position->id;
        }
        if ($inventory === '') {
            return $medium;
        }
        if ($medium === '') {
            return $inventory;
        }

        return $inventory.' · '.$medium;
    }

    /**
     * @param  array<string, mixed>|null  $validationJson
     * @return list<string>|null
     */
    private function allowedMimeTypesFromValidation(?array $validationJson): ?array
    {
        if (! is_array($validationJson)) {
            return null;
        }

        $raw = $validationJson['allowed_mime_types'] ?? null;
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $list = [];
        foreach ($raw as $mime) {
            if (is_string($mime) && trim($mime) !== '') {
                $list[] = strtolower(trim($mime));
            }
        }

        return $list === [] ? null : $list;
    }

    /**
     * @return array{
     *     storage_path: string,
     *     original_filename: string,
     *     mime: ?string,
     *     size_bytes: int,
     *     sha256: string
     * }
     */
    private function prepareUploadedFilePayload(UploadedFile $file, string $storagePath): array
    {
        $absolute = $file->getRealPath();
        if ($absolute === false || $absolute === '' || ! is_file($absolute)) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gelesen werden.',
            ]);
        }

        $sha256 = hash_file('sha256', $absolute);
        if ($sha256 === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gehasht werden.',
            ]);
        }

        $sizeBytes = (int) $file->getSize();
        $mime = $this->normalizeMime($file);
        $originalFilename = $this->sanitizeOriginalFilename($file->getClientOriginalName());

        $stream = fopen($absolute, 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gelesen werden.',
            ]);
        }

        try {
            $written = $this->files->disk()->writeStream($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written !== true) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei konnte nicht gespeichert werden.',
            ]);
        }

        return [
            'storage_path' => $storagePath,
            'original_filename' => $originalFilename,
            'mime' => $mime,
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
        ];
    }

    private function readCurrentDynamicFieldUploadId(
        DispoOrder $order,
        SnapshotFieldDefinition $def,
        ?DispoOrderPosition $position,
    ): ?int {
        if ($def->scope === FieldScope::Header) {
            $row = DispoOrderFieldValue::query()
                ->where('dispo_order_id', $order->id)
                ->where('snapshot_field_definition_id', $def->id)
                ->first();

            return $row === null ? null : FileFieldValueContract::readUploadId($row);
        }

        if ($position === null) {
            return null;
        }

        $row = DispoOrderPositionFieldValue::query()
            ->where('dispo_order_position_id', $position->id)
            ->where('snapshot_field_definition_id', $def->id)
            ->first();

        return $row === null ? null : FileFieldValueContract::readUploadId($row);
    }

    private function writeDynamicFieldUploadReference(
        DispoOrder $order,
        SnapshotFieldDefinition $def,
        ?DispoOrderPosition $position,
        int $uploadId,
    ): void {
        if ($def->scope === FieldScope::Header) {
            $row = DispoOrderFieldValue::query()->firstOrNew([
                'dispo_order_id' => $order->id,
                'snapshot_field_definition_id' => $def->id,
            ]);
            FileFieldValueContract::writeStored($def, $row, $uploadId);
            $row->save();

            return;
        }

        if ($position === null) {
            throw ValidationException::withMessages([
                'position_id' => 'Positions-Datei-Felder benötigen eine gültige Position.',
            ]);
        }

        $row = DispoOrderPositionFieldValue::query()->firstOrNew([
            'dispo_order_position_id' => $position->id,
            'snapshot_field_definition_id' => $def->id,
        ]);
        FileFieldValueContract::writeStored($def, $row, $uploadId);
        $row->save();
    }

    private function historizeDynamicFieldUpload(
        DispoOrder $order,
        int $uploadId,
        User $user,
        string $fieldKey,
    ): void {
        $previous = DispoOrderUpload::query()
            ->whereKey($uploadId)
            ->where('dispo_order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if ($previous === null || $previous->isArchived()) {
            return;
        }

        $previous->archived_at = Carbon::now();
        $previous->archived_by_user_id = $user->id;
        $previous->archived_by_name_snapshot = $user->name;
        $previous->save();

        $this->audit->record(
            $order,
            'dispo_order.upload.archived',
            $user,
            [
                'upload_id' => $previous->id,
                'category' => $previous->category->value,
                'field_key' => $fieldKey,
                'archived' => false,
                'reason' => 'replace',
            ],
            [
                'upload_id' => $previous->id,
                'category' => $previous->category->value,
                'field_key' => $fieldKey,
                'archived' => true,
                'archived_at' => $previous->archived_at->toIso8601String(),
                'reason' => 'replace',
            ],
        );
    }

    private function clearDynamicFieldReferenceIfCurrent(DispoOrder $order, DispoOrderUpload $upload): void
    {
        if (! $upload->category->isDynamicField() || $upload->field_key === null) {
            return;
        }

        $snapshotDefId = $upload->snapshot_field_definition_id;
        if ($snapshotDefId === null) {
            return;
        }

        if ($upload->dispo_order_position_id === null) {
            $row = DispoOrderFieldValue::query()
                ->where('dispo_order_id', $order->id)
                ->where('snapshot_field_definition_id', $snapshotDefId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return;
            }
            if (FileFieldValueContract::readUploadId($row) !== (int) $upload->id) {
                return;
            }
            $def = SnapshotFieldDefinition::query()->whereKey($snapshotDefId)->firstOrFail();
            FileFieldValueContract::writeStored($def, $row, null);
            $row->save();

            return;
        }

        $row = DispoOrderPositionFieldValue::query()
            ->where('dispo_order_position_id', $upload->dispo_order_position_id)
            ->where('snapshot_field_definition_id', $snapshotDefId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            return;
        }
        if (FileFieldValueContract::readUploadId($row) !== (int) $upload->id) {
            return;
        }
        $def = SnapshotFieldDefinition::query()->whereKey($snapshotDefId)->firstOrFail();
        FileFieldValueContract::writeStored($def, $row, null);
        $row->save();
    }
}
