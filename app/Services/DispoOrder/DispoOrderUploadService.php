<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\DispoOrderUploadCategory;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Services\Audit\AuditLogger;
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

            $this->audit->record(
                $locked,
                'dispo_order.upload.archived',
                $user,
                [
                    'upload_id' => $lockedUpload->id,
                    'category' => $lockedUpload->category->value,
                    'archived' => false,
                ],
                [
                    'upload_id' => $lockedUpload->id,
                    'category' => $lockedUpload->category->value,
                    'archived' => true,
                    'archived_at' => $lockedUpload->archived_at->toIso8601String(),
                    'lock_version' => $locked->lock_version,
                ],
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

    private function assertUploadFile(
        UploadedFile $file,
        ?DispoOrderUploadCategory $category = null,
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
}
