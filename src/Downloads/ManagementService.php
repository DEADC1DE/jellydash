<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

use Mk\Framework\Integrations\Connection;
use Mk\Framework\Integrations\ConnectionInput;
use Mk\Framework\Integrations\ConnectionRepository;

final class ManagementService
{
    public function __construct(private readonly ConnectionRepository $connections)
    {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{connection:Connection,credentials:array{username:string,secret:string},secret:?string,existing:?Connection,test_required:bool}
     */
    public function prepare(array $input): array
    {
        $existing = isset($input['id']) && $input['id'] !== '' ? $this->existing($input) : null;
        $candidate = ConnectionInput::parse($input, $existing);
        $secret = ConnectionInput::secret($input);
        if ($existing === null && $secret === null) {
            throw new \InvalidArgumentException('Enter the client credential.');
        }
        if ($existing !== null && $secret === null && ($candidate->url !== $existing->url || $candidate->username !== $existing->username)) {
            throw new \InvalidArgumentException('Enter the credential again when changing the URL or username.');
        }
        $credentials = $secret !== null
            ? ['username' => $candidate->username, 'secret' => $secret]
            : $this->connections->credentials($existing);
        return [
            'connection' => $candidate, 'credentials' => $credentials, 'secret' => $secret,
            'existing' => $existing,
            'test_required' => $existing === null || $secret !== null || $candidate->url !== $existing->url
                || $candidate->username !== $existing->username || $candidate->verifyTls !== $existing->verifyTls,
        ];
    }

    /** @param array<string,mixed> $input */
    public function existing(array $input): Connection
    {
        $id = $input['id'] ?? null;
        $revision = $input['revision'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $id) || !is_int($revision) || $revision < 1) {
            throw new \InvalidArgumentException('Invalid client identity or revision. Reload the client list.');
        }
        $existing = $this->connections->find($id);
        if ($existing === null || $existing->revision !== $revision) {
            throw new \DomainException('This client changed in another session. Reload the client list.');
        }
        return $existing;
    }
}
