<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

final class MovieIdentityMatcher
{
    /** @param array<string, mixed> $source @param array<string, mixed> $target */
    public function match(array $source, array $target): ?string
    {
        if (($source['serverId'] ?? '') === '' || ($source['serverId'] ?? '') !== ($target['serverId'] ?? '')) {
            return null;
        }
        if ($this->conflicts($source, $target)) {
            return null;
        }
        foreach (['year', 'library'] as $field) {
            $left = $source[$field] ?? null;
            $right = $target[$field] ?? null;
            if ($left !== null && $left !== '' && $right !== null && $right !== '' && $left !== $right) {
                return null;
            }
        }
        $providers = is_array($source['providers'] ?? null) ? $source['providers'] : [];
        $targetProviders = is_array($target['providers'] ?? null) ? $target['providers'] : [];
        $shared = false;
        $hasTrustedProvider = false;
        foreach ($providers as $key => $value) {
            $trusted = ($key === 'tmdb' && is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1)
                || ($key === 'imdb' && is_string($value) && preg_match('/^tt[0-9]+$/D', $value) === 1);
            $hasTrustedProvider = $hasTrustedProvider || $trusted;
            if (isset($targetProviders[$key])) {
                if ($targetProviders[$key] !== $value) {
                    return null;
                }
                $shared = $shared || $trusted;
            }
        }
        if ($shared) {
            return empty($source['conflicted']) && empty($target['conflicted']) ? 'automatic' : 'suggestion';
        }
        if ($hasTrustedProvider && empty($source['conflicted'])) {
            return null;
        }
        $title = $this->title((string) ($source['title'] ?? ''));
        if ($title !== '' && ($title === $this->title((string) ($target['title'] ?? ''))
            || $title === $this->title((string) ($target['originalTitle'] ?? '')))) {
            return 'suggestion';
        }

        return null;
    }

    private function title(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $target */
    public function conflicts(array $source, array $target): bool
    {
        foreach (['serverId', 'year', 'library'] as $field) {
            $left = $source[$field] ?? null;
            $right = $target[$field] ?? null;
            if ($left !== null && $left !== '' && $right !== null && $right !== '' && $left !== $right) {
                return true;
            }
        }
        foreach (($source['providers'] ?? []) as $provider => $value) {
            if (isset($target['providers'][$provider]) && $target['providers'][$provider] !== $value) {
                return true;
            }
        }

        return false;
    }
}
