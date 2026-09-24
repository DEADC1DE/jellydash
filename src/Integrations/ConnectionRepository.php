<?php

declare(strict_types=1);

namespace Mk\Framework\Integrations;

use Mk\Framework\Config;
use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\Downloads\DownloadRepository;

final class ConnectionRepository
{
    public const LEGACY_ID = 'legacy-sabnzbd-env';
    private const MAX_CONNECTIONS = 10;

    private readonly Database $database;
    private readonly \Dibi\Connection $db;
    private readonly CredentialStore $store;

    public function __construct(?Database $database = null, ?CredentialStore $store = null)
    {
        $this->database = $database ?? Container::db();
        $this->db = $this->database->getDibi();
        $this->store = $store ?? new CredentialStore();
        self::ensureSchema($this->database);
    }

    public static function ensureSchema(Database $database): void
    {
        $database->getPlatform()->createTable(
            'CREATE TABLE IF NOT EXISTS `integration_connections` (
                `id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `provider` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `display_name` varchar(128) NOT NULL,
                `endpoint` varchar(2048) NOT NULL,
                `endpoint_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `username` varchar(256) NOT NULL DEFAULT \'\',
                `verify_tls` tinyint(1) NOT NULL DEFAULT 1,
                `enabled` tinyint(1) NOT NULL DEFAULT 1,
                `filter_mode` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'all\',
                `categories_json` text NOT NULL,
                `tags_json` text NOT NULL,
                `credential_envelope` mediumtext DEFAULT NULL,
                `credential_source` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `config_revision` bigint NOT NULL,
                `created_at_epoch` bigint NOT NULL,
                `updated_at_epoch` bigint NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_integration_provider_endpoint` (`provider`, `endpoint_hash`),
                KEY `idx_integration_connection_enabled` (`enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `integration_connections` (
                `id` TEXT NOT NULL PRIMARY KEY COLLATE BINARY,
                `provider` TEXT NOT NULL COLLATE BINARY,
                `display_name` TEXT NOT NULL,
                `endpoint` TEXT NOT NULL,
                `endpoint_hash` TEXT NOT NULL COLLATE BINARY,
                `username` TEXT NOT NULL DEFAULT \'\',
                `verify_tls` INTEGER NOT NULL DEFAULT 1,
                `enabled` INTEGER NOT NULL DEFAULT 1,
                `filter_mode` TEXT NOT NULL DEFAULT \'all\' COLLATE BINARY,
                `categories_json` TEXT NOT NULL,
                `tags_json` TEXT NOT NULL,
                `credential_envelope` TEXT DEFAULT NULL,
                `credential_source` TEXT NOT NULL COLLATE BINARY,
                `config_revision` INTEGER NOT NULL,
                `created_at_epoch` INTEGER NOT NULL,
                `updated_at_epoch` INTEGER NOT NULL,
                UNIQUE (`provider`, `endpoint_hash`)
            )'
        );
        $database->getPlatform()->createSqliteIndex(
            'idx_integration_connection_enabled',
            'integration_connections',
            ['enabled'],
        );
    }

    /** @return list<Connection> */
    public function all(): array
    {
        $rows = $this->db->select('*')->from('integration_connections')->orderBy('display_name, id')->fetchAll();
        foreach ($rows as $row) {
            $this->migrateLegacyCredential($row->toArray());
        }
        $connections = array_map(fn (\Dibi\Row $row): Connection => $this->hydrate($row->toArray()), $rows);

        if (!$this->hasLegacyOverride()) {
            $legacy = $this->environmentConnection();
            if ($legacy !== null) {
                $connections[] = $legacy;
            }
        }

        usort($connections, static fn (Connection $a, Connection $b): int =>
            strcasecmp($a->name, $b->name) ?: strcmp($a->id, $b->id));

        return $connections;
    }

    public function find(string $id): ?Connection
    {
        $row = $this->db->select('*')->from('integration_connections')->where('id = %s', $id)->fetch();
        if ($row !== null) {
            $this->migrateLegacyCredential($row->toArray());
            return $this->hydrate($row->toArray());
        }

        return $id === self::LEGACY_ID ? $this->environmentConnection() : null;
    }

    public function hasStoredSecrets(): bool
    {
        if ((int) $this->db->select('COUNT(*)')->from('integration_connections')
            ->where('credential_envelope IS NOT NULL')->fetchSingle() > 0) {
            return true;
        }
        DownloadRepository::ensureSchema($this->database);

        return (int) $this->db->select('COUNT(*)')->from('download_connection_state')
            ->where('session_envelope IS NOT NULL')->fetchSingle() > 0;
    }

    public function save(Connection $candidate, ?string $secret, ?int $expectedRevision = null): Connection
    {
        $candidate = $this->validated($candidate);
        DownloadRepository::ensureSchema($this->database);

        $existingRow = $this->db->select('*')->from('integration_connections')
            ->where('id = %s', $candidate->id)->fetch();
        $existing = $existingRow?->toArray();
        if ($existing === null) {
            $environmentEdit = $candidate->id === self::LEGACY_ID
                && $expectedRevision !== null
                && $this->environmentConnection()?->revision === $expectedRevision;
            if ($expectedRevision !== null && !$environmentEdit) {
                throw new \DomainException('The connection changed. Reload it and try again.');
            }
            if (count($this->all()) - ($environmentEdit ? 1 : 0) >= self::MAX_CONNECTIONS) {
                throw new \DomainException('A maximum of 10 download clients is supported.');
            }
            $revision = 1;
            $createdAt = time();
        } else {
            if ($expectedRevision === null || (int) $existing['config_revision'] !== $expectedRevision) {
                throw new \DomainException('The connection changed. Reload it and try again.');
            }
            if ((string) $existing['provider'] !== $candidate->provider) {
                throw new \InvalidArgumentException('The connection provider cannot be changed.');
            }
            $revision = $expectedRevision + 1;
            $createdAt = (int) $existing['created_at_epoch'];
        }

        $this->assertEndpointAvailable($candidate);
        [$credentialSource, $envelope] = $this->resolveSavedCredential($candidate, $secret, $existing);
        $now = time();
        $data = [
            'id' => $candidate->id,
            'provider' => $candidate->provider,
            'display_name' => $candidate->name,
            'endpoint' => $candidate->url,
            'endpoint_hash' => $this->endpointHash($candidate->provider, $candidate->url),
            'username' => $candidate->username,
            'verify_tls' => $candidate->verifyTls ? 1 : 0,
            'enabled' => $candidate->enabled ? 1 : 0,
            'filter_mode' => $candidate->filterMode,
            'categories_json' => $this->encode($candidate->categories),
            'tags_json' => $this->encode($candidate->tags),
            'credential_envelope' => $envelope,
            'credential_source' => $credentialSource,
            'config_revision' => $revision,
            'created_at_epoch' => $createdAt,
            'updated_at_epoch' => $now,
        ];

        $this->beginWrite();
        try {
            if ($existing === null) {
                if ($this->effectiveConnectionCountForInsert() - ($environmentEdit ? 1 : 0) >= self::MAX_CONNECTIONS) {
                    throw new \DomainException('A maximum of 10 download clients is supported.');
                }
                $this->assertEndpointAvailable($candidate);
                try {
                    $this->db->insert('integration_connections', $data)->execute();
                } catch (\Dibi\UniqueConstraintViolationException $e) {
                    throw new \DomainException('A connection for this client already exists.', previous: $e);
                }
            } else {
                $this->lockConnectionRows();
                $this->assertEndpointAvailable($candidate);
                $this->db->update('integration_connections', $data)
                    ->where('id = %s AND config_revision = %i', $candidate->id, $expectedRevision)
                    ->execute();
                if ($this->db->getAffectedRows() !== 1) {
                    throw new \DomainException('The connection changed. Reload it and try again.');
                }
                $this->db->update('download_connection_state', [
                    'config_revision' => $revision,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'next_due_at' => 0,
                    'last_attempt_at' => null,
                    'last_success_at' => null,
                    'failure_code' => null,
                    'failure_count' => 0,
                    'cursor_json' => null,
                    'snapshot_json' => null,
                    'snapshot_at' => null,
                    'session_envelope' => null,
                ])->where('connection_id = %s', $candidate->id)->execute();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return new Connection(
            $candidate->id,
            $candidate->provider,
            $candidate->name,
            $candidate->url,
            $candidate->username,
            $candidate->verifyTls,
            $candidate->enabled,
            $candidate->filterMode,
            $candidate->categories,
            $candidate->tags,
            $revision,
            'database',
            $credentialSource,
            $envelope !== null || $credentialSource === 'environment',
        );
    }

    public function delete(string $id, int $expectedRevision): void
    {
        DownloadRepository::ensureSchema($this->database);
        $this->beginWrite();
        try {
            $this->lockConnectionRows();
            $this->assertLegacyFallbackCanBeRevealed($id);
            $this->db->delete('integration_connections')
                ->where('id = %s AND config_revision = %i', $id, $expectedRevision)->execute();
            if ($this->db->getAffectedRows() !== 1) {
                throw new \DomainException('The connection changed. Reload it and try again.');
            }
            $this->db->delete('download_completions')->where('connection_id = %s', $id)->execute();
            $this->db->delete('download_connection_state')->where('connection_id = %s', $id)->execute();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** @return array{username:string,secret:string} */
    public function credentials(Connection $connection): array
    {
        $current = $this->find($connection->id);
        if ($current === null || $current->revision !== $connection->revision
            || $current->provider !== $connection->provider || $current->url !== $connection->url) {
            throw new \DomainException('The connection changed. Reload it and try again.');
        }
        if ($current->credentialSource === 'environment') {
            $legacy = $this->environmentConnection();
            if ($legacy === null || $current->id !== self::LEGACY_ID || $current->url !== $legacy->url) {
                throw new \RuntimeException('The environment credential is unavailable.');
            }

            return ['username' => $current->username, 'secret' => (string) Config::get('SAB_API_KEY', '')];
        }

        $envelope = $this->db->select('credential_envelope')->from('integration_connections')
            ->where('id = %s AND config_revision = %i', $current->id, $current->revision)->fetchSingle();
        if (!is_string($envelope) || $envelope === '') {
            throw new \RuntimeException('The saved credential is unavailable.');
        }

        $secret = $this->store->decode($envelope, $this->credentialContext($current));
        if ($this->store->isLegacy($envelope)) {
            $this->replaceLegacyCredential($current->id, $current->revision, $envelope, $secret, $this->credentialContext($current));
        }

        return ['username' => $current->username, 'secret' => $secret];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Connection
    {
        return new Connection(
            (string) $row['id'],
            (string) $row['provider'],
            (string) $row['display_name'],
            (string) $row['endpoint'],
            (string) $row['username'],
            (bool) $row['verify_tls'],
            (bool) $row['enabled'],
            (string) $row['filter_mode'],
            $this->decodeStringList((string) $row['categories_json']),
            $this->decodeStringList((string) $row['tags_json']),
            (int) $row['config_revision'],
            'database',
            (string) $row['credential_source'],
            is_string($row['credential_envelope']) && $row['credential_envelope'] !== ''
                || (string) $row['credential_source'] === 'environment',
        );
    }

    private function environmentConnection(): ?Connection
    {
        $url = Config::get('SAB_API_URL');
        $secret = Config::get('SAB_API_KEY');
        if ($url === null || $secret === null) {
            return null;
        }
        try {
            $url = $this->normalizeEndpoint($url);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (str_ends_with($url, '/api')) {
            $url = substr($url, 0, -4);
        }

        return new Connection(
            self::LEGACY_ID,
            'sabnzbd',
            'SABnzbd',
            $url,
            '',
            Config::bool('SAB_VERIFY_SSL', true),
            true,
            'all',
            [],
            [],
            $this->environmentRevision($url, $secret, Config::bool('SAB_VERIFY_SSL', true)),
            'environment',
            'environment',
            true,
        );
    }

    private function hasLegacyOverride(): bool
    {
        return (int) $this->db->select('COUNT(*)')->from('integration_connections')
            ->where('id = %s', self::LEGACY_ID)->fetchSingle() > 0;
    }

    /** @param array<string,mixed>|null $existing @return array{string,?string} */
    private function resolveSavedCredential(Connection $candidate, ?string $secret, ?array $existing): array
    {
        if ($secret !== null) {
            if ($secret === '' || strlen($secret) > 4096) {
                throw new \InvalidArgumentException('A valid client credential is required.');
            }
            return ['stored', $this->store->encode($secret, $this->credentialContext($candidate))];
        }

        if ($existing !== null) {
            $sameEndpoint = (string) $existing['endpoint'] === $candidate->url;
            if ($sameEndpoint && (string) $existing['credential_source'] === 'stored'
                && is_string($existing['credential_envelope']) && $existing['credential_envelope'] !== '') {
                return ['stored', $existing['credential_envelope']];
            }
            if ($sameEndpoint && (string) $existing['credential_source'] === 'environment'
                && $this->canUseEnvironmentCredential($candidate)) {
                return ['environment', null];
            }
        }

        if ($this->canUseEnvironmentCredential($candidate)) {
            return ['environment', null];
        }

        throw new \InvalidArgumentException('Enter the client credential for this endpoint.');
    }

    /** @param array<string,mixed> $row */
    private function migrateLegacyCredential(array $row): void
    {
        $envelope = $row['credential_envelope'] ?? null;
        if (!is_string($envelope) || !$this->store->isLegacy($envelope)) {
            return;
        }
        $context = 'connection:' . (string) $row['id'] . ':' . (string) $row['provider'];
        try {
            $secret = $this->store->decode($envelope, $context);
        } catch (\RuntimeException|\InvalidArgumentException) {
            // A missing old key affects only this connection. It can be repaired by entering its credential again.
            return;
        }
        $this->replaceLegacyCredential((string) $row['id'], (int) $row['config_revision'], $envelope, $secret, $context);
    }

    private function replaceLegacyCredential(string $id, int $revision, string $oldEnvelope, string $secret, string $context): void
    {
        $this->db->update('integration_connections', [
            'credential_envelope' => $this->store->encode($secret, $context),
        ])->where('id = %s AND config_revision = %i AND credential_envelope = %s', $id, $revision, $oldEnvelope)->execute();
    }

    private function canUseEnvironmentCredential(Connection $candidate): bool
    {
        $legacy = $this->environmentConnection();

        return $legacy !== null && $candidate->id === self::LEGACY_ID
            && $candidate->provider === 'sabnzbd' && $candidate->url === $legacy->url;
    }

    private function assertEndpointAvailable(Connection $candidate): void
    {
        $hash = $this->endpointHash($candidate->provider, $candidate->url);
        $row = $this->db->select('id')->from('integration_connections')
            ->where('provider = %s AND endpoint_hash = %s', $candidate->provider, $hash)
            ->fetchSingle();
        if (is_string($row) && $row !== $candidate->id) {
            throw new \DomainException('A connection for this client already exists.');
        }

        if ($candidate->id !== self::LEGACY_ID && !$this->hasLegacyOverride()) {
            $legacy = $this->environmentConnection();
            if ($legacy !== null && $legacy->provider === $candidate->provider && $legacy->url === $candidate->url) {
                throw new \DomainException('A connection for this client already exists.');
            }
        }
    }

    private function assertLegacyFallbackCanBeRevealed(string $id): void
    {
        if ($id !== self::LEGACY_ID) {
            return;
        }
        $legacy = $this->environmentConnection();
        if ($legacy === null) {
            return;
        }
        $duplicate = $this->db->select('id')->from('integration_connections')
            ->where(
                'id <> %s AND provider = %s AND endpoint_hash = %s',
                self::LEGACY_ID,
                $legacy->provider,
                $this->endpointHash($legacy->provider, $legacy->url)
            )
            ->fetchSingle();
        if (is_string($duplicate)) {
            throw new \DomainException(
                'Another saved connection already uses the environment client. Change or remove it before using environment settings.'
            );
        }
    }

    private function validated(Connection $candidate): Connection
    {
        $id = trim($candidate->id);
        if ($id === '' || strlen($id) > 64 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $id) !== 1) {
            throw new \InvalidArgumentException('The connection identity is invalid.');
        }
        if (!isset(\Mk\Framework\Downloads\Providers::NAMES[$candidate->provider])) {
            throw new \InvalidArgumentException('The download client provider is invalid.');
        }
        $name = trim($candidate->name);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 128) {
            throw new \InvalidArgumentException('The connection name is invalid.');
        }
        $url = $this->normalizeEndpoint($candidate->url);
        if ($url === '' || strlen($url) > 2048) {
            throw new \InvalidArgumentException('The connection URL is invalid.');
        }
        if (!mb_check_encoding($candidate->username, 'UTF-8') || mb_strlen($candidate->username, 'UTF-8') > 256) {
            throw new \InvalidArgumentException('The client username is too long.');
        }
        if (!in_array($candidate->filterMode, ['all', 'selected'], true)) {
            throw new \InvalidArgumentException('The download filter mode is invalid.');
        }
        $categories = $this->validatedList($candidate->categories, true);
        $tags = $this->validatedList($candidate->tags, false);
        if ($candidate->provider !== 'qbittorrent' && $tags !== []) {
            throw new \InvalidArgumentException('This client does not support tag filters.');
        }
        if ($candidate->filterMode === 'selected' && $categories === [] && $tags === []) {
            throw new \InvalidArgumentException('Select at least one category or tag.');
        }

        return new Connection(
            $id,
            $candidate->provider,
            $name,
            $url,
            trim($candidate->username),
            $candidate->verifyTls,
            $candidate->enabled,
            $candidate->filterMode,
            $categories,
            $tags,
            $candidate->revision,
            $candidate->source,
            $candidate->credentialSource,
            $candidate->hasSecret,
        );
    }

    /** @param list<string> $values @return list<string> */
    private function validatedList(array $values, bool $allowEmpty): array
    {
        if (count($values) > 100) {
            throw new \InvalidArgumentException('Too many download filter values were supplied.');
        }
        $result = [];
        foreach ($values as $value) {
            if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 256
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('A download filter value is invalid.');
            }
            if ($value === '' && !$allowEmpty) {
                continue;
            }
            if (!in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function normalizeEndpoint(string $url): string
    {
        return ConnectionInput::normalizeUrl($url);
    }

    private function endpointHash(string $provider, string $url): string
    {
        return hash('sha256', $provider . "\0" . $url);
    }

    private function environmentRevision(string $url, string $secret, bool $verifyTls): int
    {
        // Stay within exact JavaScript integers when revisions round-trip through JSON.
        $hex = substr(hash('sha256', $url . "\0" . hash('sha256', $secret) . "\0" . (int) $verifyTls), 0, 12);

        return max(1, (int) hexdec($hex));
    }

    private function beginWrite(): void
    {
        if ($this->database->getPlatform()->isSqlite()) {
            $this->db->query('BEGIN IMMEDIATE');

            return;
        }
        $this->db->begin();
    }

    private function effectiveConnectionCountForInsert(): int
    {
        $ids = $this->lockConnectionRows();
        $count = count($ids);
        if (!in_array(self::LEGACY_ID, $ids, true) && $this->environmentConnection() !== null) {
            ++$count;
        }

        return $count;
    }

    /** @return list<string> */
    private function lockConnectionRows(): array
    {
        if ($this->database->getPlatform()->isSqlite()) {
            return array_map('strval', $this->db->select('id')->from('integration_connections')->fetchPairs());
        }

        return array_map('strval', $this->db->query(
            'SELECT `id` FROM `integration_connections` ORDER BY `id` FOR UPDATE'
        )->fetchPairs());
    }

    private function credentialContext(Connection $connection): string
    {
        return 'connection:' . $connection->id . ':' . $connection->provider;
    }

    /** @param list<string> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private function decodeStringList(string $encoded): array
    {
        $value = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('The saved connection filters are invalid.');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \RuntimeException('The saved connection filters are invalid.');
            }
        }

        return $value;
    }
}
