<?php

namespace App\Services\Scraping;

use App\Models\SourceArchiveBlob;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/** Immutable private bytes; source observations belong to ArchiveCapture, outside the adapter. */
class SourceArchive
{
    public function enabled(): bool
    {
        return config('source_archive.enabled') === true && config('source_archive.retention_confirmed') === true;
    }

    public function sourceUrl(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'lex.uz'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || strlen($url) > 2048 || str_contains(rawurldecode($parts['path'] ?? ''), "\0")) {
            throw new ArchiveException('unsafe_source_url');
        }
        parse_str($parts['query'] ?? '', $query);
        if (array_diff(array_keys($query), ['actnum', 'regnummj']) !== []) {
            throw new ArchiveException('source_url_contains_unretained_query');
        }

        return $url;
    }

    public function privacySafe(string $html): bool
    {
        if (! mb_check_encoding($html, 'UTF-8') || str_contains($html, "\0")) {
            return false;
        }
        // Preserve original bytes or refuse them. Stripping a token would create a different file.
        $crawler = new Crawler($html);
        foreach ($crawler->filter('input') as $input) {
            $name = $input->getAttribute('name').' '.$input->getAttribute('id');
            if (strtolower($input->getAttribute('type')) === 'hidden'
                || strtolower($input->getAttribute('type')) === 'password'
                || preg_match('/viewstate|eventvalidation|csrf|auth|session|token|password|credential/i', $name)) {
                return false;
            }
        }

        return ! preg_match('/__VIEWSTATE|__EVENTVALIDATION|document\s*\.\s*cookie|(?:access_token|session_token|csrf_token)\s*[=:]/i', $html);
    }

    public function retain(string $bytes, string $format, string $contentType): SourceArchiveBlob
    {
        if (! $this->enabled()) {
            throw new ArchiveException('retention_not_enabled_or_confirmed');
        }
        $this->validateBytes($bytes, $format);
        $hash = hash('sha256', $bytes);
        $diskName = (string) config('source_archive.disk');
        $disk = $this->privateDisk($diskName);
        $path = $this->path($hash, $format);

        // Covers quota inventory, duplicate detection, atomic rename and the immutable blob row.
        // A crash after rename but before commit leaves a detectable orphan, never a false DB reference.
        return DB::transaction(function () use ($bytes, $format, $contentType, $hash, $diskName, $disk, $path) {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('lex:source-archive-write'))");
            $existing = SourceArchiveBlob::where('sha256', $hash)->first();
            if ($existing !== null) {
                $this->read($existing);

                return $existing;
            }
            if ($disk->exists($path)) {
                $this->verify($disk, $path, $hash, strlen($bytes));
            } else {
                $quota = (int) config('source_archive.quota_bytes');
                if ($quota < 1 || $this->inventory($disk)['bytes'] + strlen($bytes) > $quota) {
                    throw new ArchiveException('archive_quota_exhausted');
                }
                $temporary = $path.'.'.Str::uuid().'.part';
                try {
                    if (! $disk->put($temporary, $bytes, ['visibility' => 'private'])) {
                        throw new ArchiveException('archive_write_failed');
                    }
                    $this->verify($disk, $temporary, $hash, strlen($bytes));
                    if (! $disk->move($temporary, $path)) {
                        throw new ArchiveException('archive_atomic_move_failed');
                    }
                    $this->verify($disk, $path, $hash, strlen($bytes));
                } catch (ArchiveException $e) {
                    throw $e;
                } catch (Throwable) {
                    throw new ArchiveException('archive_write_failed');
                } finally {
                    if ($disk->exists($temporary)) {
                        $disk->delete($temporary);
                    }
                }
            }

            return SourceArchiveBlob::create(['sha256' => $hash, 'storage_disk' => $diskName, 'storage_path' => $path,
                'format' => $format, 'content_type' => mb_substr($contentType, 0, 150), 'file_size' => strlen($bytes), 'archived_at' => now()]);
        });
    }

    public function read(SourceArchiveBlob $blob): string
    {
        if ($blob->storage_path !== $this->path($blob->sha256, $blob->format)
            || $blob->file_size < 1 || $blob->file_size > (int) config('source_archive.max_file_bytes')) {
            throw new ArchiveException('invalid_archive_reference');
        }
        $bytes = $this->verify($this->privateDisk($blob->storage_disk), $blob->storage_path, $blob->sha256, $blob->file_size);
        $this->validateBytes($bytes, $blob->format);

        return $bytes;
    }

    public function audit(): array
    {
        $result = ['blobs' => 0, 'registered_bytes' => 0, 'physical_files' => 0, 'physical_bytes' => 0,
            'missing' => [], 'mismatched' => [], 'orphans' => [], 'partial_files' => []];
        $registered = [];
        foreach (SourceArchiveBlob::orderBy('id')->cursor() as $blob) {
            $result['blobs']++;
            $result['registered_bytes'] += $blob->file_size;
            $registered[$blob->storage_disk][$blob->storage_path] = true;
            try {
                $this->read($blob);
            } catch (ArchiveException $e) {
                $result[$e->reason === 'archive_blob_missing' ? 'missing' : 'mismatched'][] = ['blob_id' => $blob->id, 'reason' => $e->reason];
            }
        }
        $disks = array_unique([config('source_archive.disk'), ...array_keys($registered)]);
        foreach ($disks as $name) {
            $inventory = $this->inventory($this->privateDisk($name));
            $result['physical_files'] += count($inventory['files']);
            $result['physical_bytes'] += $inventory['bytes'];
            foreach ($inventory['files'] as $path) {
                if (str_ends_with($path, '.part')) {
                    $result['partial_files'][] = ['disk' => $name, 'path' => $path];
                } elseif (! isset($registered[$name][$path])) {
                    $result['orphans'][] = ['disk' => $name, 'path' => $path];
                }
            }
        }
        $result['ok'] = $result['missing'] === [] && $result['mismatched'] === [] && $result['orphans'] === [] && $result['partial_files'] === [];

        return $result;
    }

    private function path(string $hash, string $format): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash) || ! in_array($format, ['html', 'pdf'], true)) {
            throw new ArchiveException('invalid_archive_reference');
        }

        return 'lex/'.$format.'/'.substr($hash, 0, 2).'/'.$hash.'.'.$format;
    }

    private function validateBytes(string $bytes, string $format): void
    {
        if ($bytes === '' || strlen($bytes) > (int) config('source_archive.max_file_bytes')) {
            throw new ArchiveException('archive_file_size_invalid');
        }
        if ($format === 'pdf') {
            if (! str_starts_with($bytes, '%PDF-') || ! preg_match('/%%EOF\s*$/D', $bytes)) {
                throw new ArchiveException('incomplete_or_invalid_pdf');
            }
        } elseif ($format === 'html') {
            if (! preg_match('/<(?:!doctype\s+html|html|div|body)\b/i', $bytes)) {
                throw new ArchiveException('invalid_html');
            }
            if (! $this->privacySafe($bytes)) {
                throw new ArchiveException('html_contains_unretained_private_state');
            }
        } else {
            throw new ArchiveException('unsupported_archive_format');
        }
    }

    private function privateDisk(string $name): FilesystemAdapter
    {
        $config = config('filesystems.disks.'.$name);
        if (! is_array($config) || ($config['driver'] ?? null) !== 'local' || ($config['visibility'] ?? 'private') !== 'private'
            || ($config['serve'] ?? false) === true || empty($config['root'])) {
            throw new ArchiveException('archive_disk_must_be_private_local');
        }
        $root = rtrim($config['root'], '/');
        if ($root === public_path() || str_starts_with($root, public_path().'/') || str_contains($root, '/../')) {
            throw new ArchiveException('archive_disk_must_be_private_local');
        }
        for ($directory = $root; $directory !== '/' && $directory !== '.'; $directory = dirname($directory)) {
            if (is_link($directory)) {
                throw new ArchiveException('archive_disk_symlink_forbidden');
            }
        }

        return Storage::disk($name);
    }

    private function verify(FilesystemAdapter $disk, string $path, string $hash, int $size): string
    {
        if (! $disk->exists($path)) {
            throw new ArchiveException('archive_blob_missing');
        }
        if ($disk->size($path) !== $size) {
            throw new ArchiveException('archive_size_mismatch');
        }
        $bytes = $disk->get($path);
        if (! is_string($bytes) || ! hash_equals($hash, hash('sha256', $bytes))) {
            throw new ArchiveException('archive_hash_mismatch');
        }

        return $bytes;
    }

    private function inventory(FilesystemAdapter $disk): array
    {
        $files = $disk->allFiles('lex');
        if (count($files) > 50000) {
            throw new ArchiveException('archive_inventory_limit');
        }
        $bytes = 0;
        foreach ($files as $path) {
            $bytes += $disk->size($path);
        }

        return compact('files', 'bytes');
    }
}
