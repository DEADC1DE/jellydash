<?php

declare(strict_types=1);

namespace Mk\Modules\SessionControl;

use Mk\Framework\Authorization;
use Mk\Framework\Controller;
use Mk\Framework\Csrf;
use Mk\Framework\Log;
use Mk\Framework\Main;

/**
 * Admin editor for the automatic stream rules: condition rows + logic string
 * per rule, applied by StreamRuleEnforcer on every poll cycle. Plain GET/POST
 * forms so the editor works without JavaScript.
 */
final class StreamRulesController extends Controller
{
    private const MAX_CONDITIONS = 4;

    /** parameter => [label, type] — the session fields rules may match on. */
    private const PARAMETERS = [
        'user' => ['User', 'str'],
        'client' => ['Client', 'str'],
        'device' => ['Device', 'str'],
        'title' => ['Title', 'str'],
        'seriesName' => ['Series', 'str'],
        'library' => ['Library', 'str'],
        'playMethod' => ['Play method', 'str'],
        'quality' => ['Quality', 'str'],
        'bitrate' => ['Bitrate (kbps)', 'int'],
        'progressPct' => ['Progress (%)', 'float'],
        'watchedMin' => ['Watched (minutes)', 'int'],
        'ip' => ['IP address', 'str'],
        'ipIndex' => ['IP slot of user (1 = oldest IP)', 'int'],
        'userIpCount' => ['Active IPs of user', 'int'],
        'userStreams' => ['Active streams of user', 'int'],
    ];

    public function handle(): void
    {
        if (!(new Authorization())->hasRole(Authorization::ROLE_ADMIN)) {
            $this->render('@session-control/rules-denied', [
                'layout' => $this->layout(['title' => 'Stream Rules', 'page' => 'session-rules']),
            ]);

            return;
        }

        $repository = new StreamRuleRepository();
        $message = null;
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string) ($_POST['do'] ?? '');
            try {
                Csrf::check();
                switch ($action) {
                    case 'save':
                        $id = $this->save($repository);
                        $message = $id > 0 ? 'Rule saved.' : null;
                        break;
                    case 'toggle':
                        $rule = $repository->get((int) ($_POST['id'] ?? 0));
                        if ($rule !== null) {
                            $repository->toggle((int) $rule['id'], !$rule['enabled']);
                        }
                        break;
                    case 'delete':
                        $repository->delete((int) ($_POST['id'] ?? 0));
                        $message = 'Rule deleted.';
                        break;
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : 'Could not save the rule.';
                Log::logException($e);
            }
        }

        $this->render('@session-control/rules', [
            'layout' => $this->layout(['title' => 'Stream Rules', 'page' => 'session-rules']),
            'rules' => $repository->all(),
            'edit' => $this->editRule($repository),
            'parameters' => self::parameterOptions(),
            'operators' => StreamRuleEngine::operators(),
            'actions' => ['stop' => 'Stop playback', 'kick' => 'Kick device'],
            'maxConditions' => self::MAX_CONDITIONS,
            'message' => $message,
            'error' => $error,
            'csrf_token' => Csrf::token(),
        ]);
    }

    /** @return array<string, mixed>|null the rule requested via ?edit=, null otherwise */
    private function editRule(StreamRuleRepository $repository): ?array
    {
        $editId = (int) (Main::captureGetString('edit') ?? '0');
        if ($editId < 1) {
            return null;
        }

        // Pad the stored conditions so the editor slots line up with them.
        $rule = $repository->get($editId);
        if ($rule !== null) {
            $rule['conditions'] = array_pad($rule['conditions'], self::MAX_CONDITIONS, []);
        }

        return $rule;
    }

    /** @return int the saved rule id, 0 when the input was rejected */
    private function save(StreamRuleRepository $repository): int
    {
        $name = trim((string) Main::capturePostString('name'));
        $logic = (string) Main::capturePostString('logic');
        $action = (string) Main::capturePostString('action');
        $id = (int) Main::capturePostString('id');

        if ($action !== 'kick') {
            $action = 'stop';
        }
        if ($id < 1) {
            $id = 0;
        }
        if ($name === '') {
            return 0;
        }

        $conditions = [];
        for ($slot = 1; $slot <= self::MAX_CONDITIONS; $slot++) {
            $parameter = trim((string) Main::capturePostString('parameter_' . $slot));
            $operator = trim((string) Main::capturePostString('operator_' . $slot));
            $value = trim((string) Main::capturePostString('value_' . $slot));

            // Slots are kept positionally so the logic string's {n} refers to
            // the row the admin sees; fully blank slots are dropped, partial
            // ones persist as skip-conditions (the engine treats blanks as
            // "always true").
            if ($parameter === '' && $operator === '' && $value === '') {
                continue;
            }

            $conditions[] = [
                'parameter' => self::PARAMETERS[$parameter][0] ?? null ? $parameter : '',
                'operator' => $operator !== '' ? $operator : 'is',
                'value' => $value,
                'type' => self::PARAMETERS[$parameter][1] ?? 'str',
            ];
        }

        if ($conditions === []) {
            return 0;
        }

        return $repository->save($name, $conditions, $logic, $action, true, $id);
    }

    /** @return array<string, string> parameter => editor label */
    public static function parameterOptions(): array
    {
        $options = [];
        foreach (self::PARAMETERS as $parameter => [$label, $type]) {
            $options[$parameter] = $label . ($type === 'str' ? '' : ' (' . $type . ')');
        }

        return $options;
    }

    /** @return array<string, string> parameter => type, for the enforcer and tests */
    public static function parameterTypes(): array
    {
        $types = [];
        foreach (self::PARAMETERS as $parameter => [, $type]) {
            $types[$parameter] = $type;
        }

        return $types;
    }
}
