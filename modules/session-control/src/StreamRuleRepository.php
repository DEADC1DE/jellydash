<?php

declare(strict_types=1);

namespace Mk\Modules\SessionControl;

use Mk\Framework\Container;
use Mk\Framework\Database;
use Mk\Framework\DatabasePlatform;

/**
 * Persists stream rules (conditions JSON + logic string + action) and keeps
 * a short-lived kill guard so a session that survives its stop command for a
 * poll cycle or two is not killed and notified over and over.
 */
final class StreamRuleRepository
{
    private const KILL_GUARD_SECONDS = 900;
    private const IP_MEMORY_SECONDS = 7 * 86400;

    private static ?\WeakMap $schemaConnections = null;

    private \Dibi\Connection $dibi;
    private DatabasePlatform $platform;

    public function __construct(?Database $database = null)
    {
        $database ??= Container::db();
        $this->dibi = $database->getDibi();
        $this->platform = new DatabasePlatform($this->dibi);
        self::ensureSchema($database);
    }

    public static function ensureSchema(Database $database): void
    {
        self::$schemaConnections ??= new \WeakMap();
        $db = $database->getDibi();
        if (isset(self::$schemaConnections[$db])) {
            return;
        }

        (new DatabasePlatform($db))->createTable(
            'CREATE TABLE IF NOT EXISTS `stream_rules` (
                `id` int NOT NULL AUTO_INCREMENT,
                `name` varchar(120) NOT NULL,
                `conditions` text NOT NULL,
                `logic` varchar(255) NOT NULL,
                `action` varchar(10) NOT NULL,
                `enabled` tinyint(1) NOT NULL DEFAULT 1,
                `kill_count` int NOT NULL DEFAULT 0,
                `last_matched_at` int DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `stream_rules` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `name` TEXT NOT NULL,
                `conditions` TEXT NOT NULL,
                `logic` TEXT NOT NULL,
                `action` TEXT NOT NULL,
                `enabled` INTEGER NOT NULL DEFAULT 1,
                `kill_count` INTEGER NOT NULL DEFAULT 0,
                `last_matched_at` INTEGER DEFAULT NULL
            )'
        );

        $db->query('CREATE TABLE IF NOT EXISTS `stream_rule_kills` (
            `session_id` varchar(190) NOT NULL,
            `rule_id` int NOT NULL,
            `at` int NOT NULL,
            PRIMARY KEY (`session_id`, `rule_id`)
        )');

        $db->query('CREATE TABLE IF NOT EXISTS `stream_rule_ips` (
            `username` varchar(190) NOT NULL,
            `ip` varchar(64) NOT NULL,
            `first_seen` int NOT NULL,
            `last_seen` int NOT NULL,
            PRIMARY KEY (`username`, `ip`)
        )');

        self::$schemaConnections[$db] = true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_map($this->decode(...), $this->dibi->select('*')->from('stream_rules')->orderBy('id')->asc()->fetchAll());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enabled(): array
    {
        return array_map($this->decode(...), $this->dibi->select('*')->from('stream_rules')->where('enabled = 1')->orderBy('id')->asc()->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $id): ?array
    {
        $row = $this->dibi->select('*')->from('stream_rules')->where('id = %i', $id)->fetch();

        return $row === null ? null : $this->decode($row);
    }

    /**
     * @param array<int, array{parameter: string, operator: string, value: string, type: string}> $conditions
     */
    public function save(string $name, array $conditions, string $logic, string $action, bool $enabled, int $id = 0): int
    {
        $row = [
            'name' => $name,
            'conditions' => json_encode(array_values($conditions), JSON_UNESCAPED_UNICODE),
            'logic' => trim($logic),
            'action' => $action === 'kick' ? 'kick' : 'stop',
            'enabled' => $enabled ? 1 : 0,
        ];

        if ($id > 0) {
            $this->dibi->update('stream_rules', $row)->where('id = %i', $id)->execute();

            return $id;
        }

        $this->dibi->insert('stream_rules', $row)->execute();

        return (int) $this->dibi->getInsertId();
    }

    public function delete(int $id): void
    {
        $this->dibi->delete('stream_rules')->where('id = %i', $id)->execute();
    }

    public function toggle(int $id, bool $enabled): void
    {
        $this->dibi->update('stream_rules', ['enabled' => $enabled ? 1 : 0])->where('id = %i', $id)->execute();
    }

    /** True when this session was already killed by this rule recently. */
    public function wasKilledRecently(string $sessionId, int $ruleId, int $now): bool
    {
        if ($sessionId === '') {
            return false;
        }

        $count = $this->dibi->select('COUNT(*)')
            ->from('stream_rule_kills')
            ->where('session_id = %s', $sessionId)
            ->where('rule_id = %i', $ruleId)
            ->where('at > %i', $now - self::KILL_GUARD_SECONDS)
            ->fetchSingle();

        return (int) $count > 0;
    }

    public function recordKill(string $sessionId, int $ruleId, int $now): void
    {
        if ($sessionId === '') {
            return;
        }

        if ($this->platform->isSqlite()) {
            $this->dibi->query('INSERT OR REPLACE INTO `stream_rule_kills` (`session_id`, `rule_id`, `at`) VALUES (%s, %i, %i)', $sessionId, $ruleId, $now);
        } else {
            $this->dibi->query('INSERT INTO `stream_rule_kills` (`session_id`, `rule_id`, `at`) VALUES (%s, %i, %i)
                ON DUPLICATE KEY UPDATE `at` = VALUES(`at`)', $sessionId, $ruleId, $now);
        }

        $this->dibi->update('stream_rules', ['kill_count' => new \Dibi\Literal('`kill_count` + 1'), 'last_matched_at' => $now])
            ->where('id = %i', $ruleId)->execute();
    }

    /**
     * Registers the (user, ip) pairs of the current session snapshot and
     * returns each pair's 1-based rank by first-seen order — the slot the IP
     * occupies for that user. Slot 1-2 = established, 3+ = newer additions.
     * IPs unseen for a week lose their slot, so departed viewers do not
     * block the limit forever.
     *
     * @param array<int, array{user: string, ip: string}> $pairs
     * @return array<string, int> "user\0ip" => rank (1-based)
     */
    public function refreshIpRanks(array $pairs, int $now): array
    {
        $seen = [];
        foreach ($pairs as $pair) {
            $user = trim($pair['user']);
            $ip = trim($pair['ip']);
            if ($user === '' || $ip === '') {
                continue;
            }

            $key = $user . "\0" . $ip;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $row = $this->dibi->select('first_seen')
                ->from('stream_rule_ips')
                ->where('username = %s', $user)
                ->where('ip = %s', $ip)
                ->fetch();

            if ($row === null) {
                $this->dibi->insert('stream_rule_ips', ['username' => $user, 'ip' => $ip, 'first_seen' => $now, 'last_seen' => $now])->execute();
            } else {
                $this->dibi->update('stream_rule_ips', ['last_seen' => $now])
                    ->where('username = %s', $user)
                    ->where('ip = %s', $ip)
                    ->execute();
            }
        }

        $this->dibi->delete('stream_rule_ips')->where('last_seen < %i', $now - self::IP_MEMORY_SECONDS)->execute();

        $ranks = [];
        $counts = [];
        foreach ($this->dibi->select('username, ip')->from('stream_rule_ips')->orderBy('first_seen')->asc()->orderBy('ip')->asc()->fetchAll() as $row) {
            $user = (string) $row['username'];
            $counts[$user] ??= 0;
            $ranks[$user . "\0" . (string) $row['ip']] = ++$counts[$user];
        }

        return $ranks;
    }

    private function decode(\Dibi\Row|array $row): array
    {
        $rule = (array) $row;
        $conditions = json_decode((string) ($rule['conditions'] ?? '[]'), true);

        $rule['conditions'] = is_array($conditions) ? $conditions : [];
        $rule['enabled'] = (bool) ($rule['enabled'] ?? false);
        $rule['kill_count'] = (int) ($rule['kill_count'] ?? 0);
        $rule['last_matched_at'] = isset($rule['last_matched_at']) ? (int) $rule['last_matched_at'] : null;

        return $rule;
    }
}
