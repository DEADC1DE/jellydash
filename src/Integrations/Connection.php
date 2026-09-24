<?php

declare(strict_types=1);

namespace Mk\Framework\Integrations;

/** A resolved connection. Credentials are loaded separately and never serialized here. */
final readonly class Connection
{
    /** @param list<string> $categories @param list<string> $tags */
    public function __construct(
        public string $id,
        public string $provider,
        public string $name,
        public string $url,
        public string $username = '',
        public bool $verifyTls = true,
        public bool $enabled = true,
        public string $filterMode = 'all',
        public array $categories = [],
        public array $tags = [],
        public int $revision = 0,
        public string $source = 'database',
        public string $credentialSource = 'stored',
        public bool $hasSecret = false,
    ) {
    }

    /** @param array<string,mixed> $item */
    public function matches(array $item): bool
    {
        if ($this->filterMode === 'all') {
            return true;
        }
        $category = is_string($item['category'] ?? null) ? $item['category'] : '';
        $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
        if ($this->provider === 'transmission') {
            return ($tags === [] && in_array('', $this->categories, true))
                || array_intersect($this->categories, $tags) !== [];
        }
        return in_array($category, $this->categories, true)
            || ($this->provider === 'qbittorrent' && array_intersect($this->tags, $tags) !== []);
    }

    /** @return array<string,mixed> */
    public function managementData(): array
    {
        return [
            'id' => $this->id, 'provider' => $this->provider, 'name' => $this->name,
            'url' => $this->url, 'username' => $this->username, 'verify_tls' => $this->verifyTls,
            'enabled' => $this->enabled, 'filter_mode' => $this->filterMode,
            'categories' => $this->categories, 'tags' => $this->tags,
            'revision' => $this->revision, 'source' => $this->source,
            'credential_source' => $this->credentialSource, 'has_secret' => $this->hasSecret,
        ];
    }
}
