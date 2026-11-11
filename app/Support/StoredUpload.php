<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Stores an uploaded file and captures its metadata.
 *
 * Livewire's TemporaryUploadedFile *moves* (not copies) the file when the target
 * disk is the same as the livewire-tmp disk, so getMimeType()/getSize() must be
 * read before storing — afterwards the file no longer exists at its old path.
 */
final class StoredUpload
{
    private function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
    ) {}

    /**
     * Store at "$directory/$filename", or under a generated hash name when $filename is null.
     */
    public static function store(UploadedFile $file, string $disk, string $directory, ?string $filename = null): self
    {
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $sizeBytes = (int) $file->getSize();

        $path = $filename !== null
            ? $file->storeAs($directory, $filename, $disk)
            : $file->store($directory, $disk);

        if ($path === false) {
            throw new RuntimeException("Could not store upload {$originalName} on disk {$disk}.");
        }

        return new self($disk, $path, $originalName, $mimeType, $sizeBytes);
    }

    /**
     * Run $callback (typically creating the DB row that points at the file) and delete
     * the stored file if it throws, so a failed save never leaves an orphaned file.
     *
     * @template T
     *
     * @param  callable(self): T  $callback
     * @return T
     */
    public function persist(callable $callback): mixed
    {
        try {
            return $callback($this);
        } catch (Throwable $e) {
            Storage::disk($this->disk)->delete($this->path);

            throw $e;
        }
    }
}
