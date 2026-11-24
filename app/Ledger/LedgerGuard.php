<?php

declare(strict_types=1);

namespace App\Ledger;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Posted books can't be changed in the database itself (Phase 10, D148). Triggers refuse any
 * UPDATE or DELETE on `journal_lines`, and on `journal_entries` anything but filling in the
 * reversal links (`reverses_id`, `reversed_by_id`, once each) and `updated_at`. That stops a
 * stray SQL console, a buggy migration or a compromised admin tool from editing history; the
 * app itself only ever inserts (LedgerPoster). Erasing a whole company (D98) is the one
 * legitimate delete, and goes through `unlocked()`, which sets a session flag the triggers
 * read. MySQL / MariaDB and PostgreSQL variants; other drivers (SQLite) have no guard.
 *
 * The MySQL entry trigger lists the columns it protects (ENTRY_COLUMNS_FIXED), so a migration
 * that adds a column to `journal_entries` adds it there and calls `install()` again (it drops and
 * recreates); LedgerGuardTest fails until it does. PostgreSQL compares the whole row.
 */
final class LedgerGuard
{
    public const string PREFIX = 'coffer_journal_';

    /**
     * Every `journal_entries` column, split into those the MySQL trigger keeps fixed and those that
     * may change after posting (the reversal links, null → set once, and the timestamp). A test
     * checks this against the schema, so a new column can't slip through unguarded.
     */
    public const array ENTRY_COLUMNS_FIXED = ['id', 'company_id', 'date', 'type', 'source_type', 'source_id', 'memo', 'posted_by', 'created_at'];

    public const array ENTRY_COLUMNS_MUTABLE = ['reverses_id', 'reversed_by_id', 'updated_at'];

    // No quotes: it goes into SQL string literals as it is.
    private const string MESSAGE = 'Coffer: posted journal rows cannot be changed or deleted. Post a reversal instead.';

    private const array TRIGGERS = [
        'lines_no_update', 'lines_no_delete', 'entries_guard_update', 'entries_no_delete',
    ];

    public static function supported(?Connection $db = null): bool
    {
        return in_array(self::driver($db), ['mysql', 'mariadb', 'pgsql'], true);
    }

    /** Create (or recreate) the triggers. */
    public static function install(?Connection $db = null): void
    {
        $db ??= DB::connection();

        if (! self::supported($db)) {
            return;
        }

        self::uninstall($db);

        self::driver($db) === 'pgsql' ? self::installPostgres($db) : self::installMysql($db);
    }

    public static function uninstall(?Connection $db = null): void
    {
        $db ??= DB::connection();

        if (! self::supported($db)) {
            return;
        }

        if (self::driver($db) === 'pgsql') {
            $db->unprepared('DROP TRIGGER IF EXISTS '.self::PREFIX.'lines_guard ON journal_lines');
            $db->unprepared('DROP TRIGGER IF EXISTS '.self::PREFIX.'entries_guard ON journal_entries');
            $db->unprepared('DROP FUNCTION IF EXISTS '.self::PREFIX.'guard()');

            return;
        }

        foreach (self::TRIGGERS as $name) {
            $db->unprepared('DROP TRIGGER IF EXISTS '.self::PREFIX.$name);
        }
    }

    /** True when every trigger is in place. */
    public static function installed(?Connection $db = null): bool
    {
        $db ??= DB::connection();

        if (! self::supported($db)) {
            return false;
        }

        if (self::driver($db) === 'pgsql') {
            $found = $db->table('pg_trigger')->where('tgname', 'like', self::PREFIX.'%')->where('tgisinternal', false)->count();

            return $found === 2;
        }

        $found = $db->table('information_schema.TRIGGERS')
            ->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->whereIn('TRIGGER_NAME', array_map(fn (string $name): string => self::PREFIX.$name, self::TRIGGERS))
            ->count();

        return $found === count(self::TRIGGERS);
    }

    /**
     * Runs `$callback` with the guard lifted for this connection only (company erasure, D98).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function unlocked(callable $callback, ?Connection $db = null): mixed
    {
        $db ??= DB::connection();

        if (! self::supported($db)) {
            return $callback();
        }

        $pgsql = self::driver($db) === 'pgsql';
        $db->statement($pgsql ? "SELECT set_config('coffer.ledger_unlock', 'on', false)" : 'SET @coffer_ledger_unlock = 1');

        try {
            return $callback();
        } finally {
            $db->statement($pgsql ? "SELECT set_config('coffer.ledger_unlock', '', false)" : 'SET @coffer_ledger_unlock = NULL');
        }
    }

    private static function installMysql(Connection $db): void
    {
        $unlocked = 'COALESCE(@coffer_ledger_unlock, 0) = 1';
        $refuse = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'";

        foreach (['lines_no_update' => 'UPDATE', 'lines_no_delete' => 'DELETE'] as $name => $event) {
            $db->unprepared('CREATE TRIGGER '.self::PREFIX.$name." BEFORE {$event} ON journal_lines FOR EACH ROW
                BEGIN IF NOT ({$unlocked}) THEN {$refuse}; END IF; END");
        }

        $db->unprepared('CREATE TRIGGER '.self::PREFIX."entries_no_delete BEFORE DELETE ON journal_entries FOR EACH ROW
            BEGIN IF NOT ({$unlocked}) THEN {$refuse}; END IF; END");

        $same = 'NEW.`id` <=> OLD.`id` AND NEW.`company_id` <=> OLD.`company_id` AND NEW.`date` <=> OLD.`date`'
            .' AND NEW.`type` <=> OLD.`type` AND NEW.`source_type` <=> OLD.`source_type` AND NEW.`source_id` <=> OLD.`source_id`'
            .' AND NEW.`memo` <=> OLD.`memo` AND NEW.`posted_by` <=> OLD.`posted_by` AND NEW.`created_at` <=> OLD.`created_at`'
            .' AND (OLD.`reverses_id` IS NULL OR NEW.`reverses_id` <=> OLD.`reverses_id`)'
            .' AND (OLD.`reversed_by_id` IS NULL OR NEW.`reversed_by_id` <=> OLD.`reversed_by_id`)';

        $db->unprepared('CREATE TRIGGER '.self::PREFIX."entries_guard_update BEFORE UPDATE ON journal_entries FOR EACH ROW
            BEGIN IF NOT ({$unlocked}) AND NOT ({$same}) THEN {$refuse}; END IF; END");
    }

    private static function installPostgres(Connection $db): void
    {
        $allowed = "'reverses_id' - 'reversed_by_id' - 'updated_at'";
        $message = self::MESSAGE;

        $db->unprepared('CREATE OR REPLACE FUNCTION '.self::PREFIX."guard() RETURNS trigger LANGUAGE plpgsql AS \$guard\$
            BEGIN
                IF current_setting('coffer.ledger_unlock', true) = 'on' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                IF TG_OP = 'DELETE' OR TG_TABLE_NAME = 'journal_lines' THEN
                    RAISE EXCEPTION '{$message}';
                END IF;
                IF (to_jsonb(NEW) - {$allowed}) IS DISTINCT FROM (to_jsonb(OLD) - {$allowed})
                    OR (OLD.reverses_id IS NOT NULL AND NEW.reverses_id IS DISTINCT FROM OLD.reverses_id)
                    OR (OLD.reversed_by_id IS NOT NULL AND NEW.reversed_by_id IS DISTINCT FROM OLD.reversed_by_id) THEN
                    RAISE EXCEPTION '{$message}';
                END IF;
                RETURN NEW;
            END
            \$guard\$");

        $db->unprepared('CREATE TRIGGER '.self::PREFIX.'lines_guard BEFORE UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION '.self::PREFIX.'guard()');
        $db->unprepared('CREATE TRIGGER '.self::PREFIX.'entries_guard BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION '.self::PREFIX.'guard()');
    }

    private static function driver(?Connection $db): string
    {
        return ($db ?? DB::connection())->getDriverName();
    }
}
