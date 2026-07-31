<?php

declare(strict_types=1);

namespace Mk\Framework;

/**
 * Image upload handler (dependency-free).
 *
 * Validates size, detects the MIME type from the file *contents* (not the
 * client-supplied type or filename), derives a safe extension from that MIME,
 * gives the file an unpredictable name, and stores it under IMAGES_DIR.
 */
class Upload
{
    private const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 MB

    // Allowed MIME types mapped to the canonical extension we store them with.
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/heic' => 'heic',
        'image/tiff' => 'tiff',
        'image/bmp'  => 'bmp',
    ];

    // File data
    private string $file_name = '';
    private string $file_name_final = '';
    private string $extension = '';
    private string $mime = '';
    private ?int $size = null;
    private ?string $md5 = null;
    private ?array $dimensions = null;

    // Result
    private bool $result = false;
    private ?\Exception $exception = null;

    public function uploadImage($file, ?string $prefix = null): bool
    {
        $this->result = false;
        $this->exception = null;

        try {
            if (!isset($_FILES[$file]) || ($_FILES[$file]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('No valid uploaded file for field: ' . $file);
            }

            $tmp = $_FILES[$file]['tmp_name'];
            if (!is_uploaded_file($tmp)) {
                throw new \RuntimeException('Not a valid uploaded file.');
            }

            $this->size = (int) $_FILES[$file]['size'];
            if ($this->size > self::MAX_SIZE_BYTES) {
                throw new \RuntimeException('File exceeds the maximum allowed size.');
            }

            // Detect MIME from content, never trust the client-supplied type.
            $this->mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
            if (!array_key_exists($this->mime, self::ALLOWED)) {
                throw new \RuntimeException('Disallowed file type: ' . $this->mime);
            }
            // Extension comes from the validated MIME, not the client filename.
            $this->extension = self::ALLOWED[$this->mime];

            // Confirm it is a real image and capture dimensions.
            $info = @getimagesize($tmp);
            if ($info === false) {
                throw new \RuntimeException('Uploaded file is not a valid image.');
            }
            $this->dimensions = ['width' => $info[0], 'height' => $info[1]];

            $this->md5 = md5_file($tmp) ?: null;
            $this->file_name = $this->makeName($prefix) . '.' . $this->extension;
            $this->file_name_final = $this->file_name;

            if (!is_dir(IMAGES_DIR) && !mkdir(IMAGES_DIR, 0775, true) && !is_dir(IMAGES_DIR)) {
                throw new \RuntimeException('Upload directory is not available.');
            }

            if (!move_uploaded_file($tmp, IMAGES_DIR . $this->file_name)) {
                throw new \RuntimeException('Failed to store the uploaded file.');
            }

            $this->result = true;
        } catch (\Throwable $e) {
            $this->exception = $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e);
            Log::logException($this->exception);
            $this->result = false;
        }

        return $this->result;

        /* HTML FOR FILE UPLOAD (requires login + CSRF token) ->
        <form method="POST" enctype="multipart/form-data" action="?req=upload">
        <input type="hidden" name="csrf_token" value="{{ csrf_token }}"/>
        <input type="file" name="photo" value=""/>
        <input type="submit" value="Upload File"/>
        </form>
        */
    }

    private function makeName($prefix = null): string
    {
        $prefix = $prefix ?: 'img';
        // Unpredictable filename; uniqid() is timestamp-based and guessable.
        return $prefix . '_' . bin2hex(random_bytes(16));
    }

    // -----------------------------------------------------------------------------------------------------------------
    // GETTERS
    // -----------------------------------------------------------------------------------------------------------------

    public function getResult(): bool
    {
        return $this->result;
    }

    public function getFinalFileName(): string
    {
        return $this->file_name_final;
    }

    public function getFileName(): string
    {
        return $this->file_name;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getMD5(): ?string
    {
        return $this->md5;
    }

    public function getDimensions(): ?array
    {
        return $this->dimensions;
    }

    public function getException(): ?\Exception
    {
        return $this->exception;
    }
}
