<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_sideline\local\adapter;

/**
 * Discovers slcmod_* adapters and calls them safely (P1-06).
 *
 * @package    mod_sideline
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registry {
    /** @var base[]|null Adapters keyed by module name, per request. */
    private static ?array $adapters = null;

    /** @var base[]|null Adapters injected by tests. */
    private static ?array $override = null;

    /**
     * All installed adapters, keyed by the module they describe.
     *
     * @return base[]
     */
    public static function adapters(): array {
        if (self::$override !== null) {
            return self::$override;
        }
        if (self::$adapters === null) {
            self::$adapters = [];
            foreach (\core_component::get_plugin_list_with_class('slcmod', 'adapter') as $classname) {
                try {
                    $adapter = new $classname();
                } catch (\Throwable $e) {
                    continue;
                }
                if ($adapter instanceof base) {
                    self::$adapters[$adapter->modname()] = $adapter;
                }
            }
        }
        return self::$adapters;
    }

    /**
     * Collect adapter facts for all course modules. Never throws.
     *
     * @param \cm_info[] $cms Course modules the user can see.
     * @param int $userid User id.
     * @param int $now Current time.
     * @return array [facts keyed by cmid, failed module names]
     */
    public static function collect(array $cms, int $userid, int $now): array {
        $bymod = [];
        foreach ($cms as $cm) {
            $bymod[$cm->modname][$cm->id] = $cm;
        }
        $result = [];
        $failed = [];
        foreach (self::adapters() as $modname => $adapter) {
            if (empty($bymod[$modname])) {
                continue;
            }
            try {
                $facts = $adapter->collect($bymod[$modname], $userid, $now);
            } catch (\Throwable $e) {
                // A broken adapter must never break the commentary: fall back to core facts.
                debugging('mod_sideline: adapter ' . $modname . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $failed[] = $modname;
                continue;
            }
            foreach ($facts as $cmid => $fact) {
                // Only facts for course modules that were asked for, and only of the right type.
                if (isset($bymod[$modname][$cmid]) && $fact instanceof facts) {
                    $result[(int) $cmid] = $fact->sanitised();
                }
            }
        }
        return [$result, $failed];
    }

    /**
     * Replace the installed adapters, for tests only.
     *
     * @param base[]|null $adapters Adapters keyed by module name, null to restore discovery.
     */
    public static function set_override_for_testing(?array $adapters): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('set_override_for_testing() is only available in unit tests');
        }
        self::$override = $adapters;
        self::$adapters = null;
    }
}
