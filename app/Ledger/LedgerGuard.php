<?php

declare(strict_types=1);

namespace App\Ledger;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Posted books can't be changed in the database itself (Phase 10, D148). Triggers refuse any
 * UPDATE or DELETE on `journal_lines`, and on `journal_entries` anything but filling in the
 * set-once columns (the reversal links and the voucher number, null → value) and `updated_at`.
 * The audit trail (`audit_events`, D153) is append-only the same way. That stops a stray SQL
 * console, a buggy migration or a compromised admin tool from editing history; the app itself
 * only ever inserts (LedgerPoster, AuditTrail). Erasing a whole company (D98) is the one
 * legitimate delete, and goes through `unlocked()`, which sets a session flag the triggers
 * read. MySQL / MariaDB and PostgreSQL variants; other drivers (SQLite) have no guard.
 *
 * The triggers are built from the columns the tables have when `install()` runs, so an older
 * migration can install them before a later one adds a column; that later migration calls
 * `install()` again (it drops and recreates). Every `journal_entries` column must be listed
 * below; LedgerGuardTest fails when one isn't.
 */
final class LedgerGuard
{
    public const string PREFIX = 'coffer_journal_';

    /** `journal_entries` columns that never change after posting. */
    public const array ENTRY_COLUMNS_FIXED = ['id', 'company_id', 'date', 'type', 'source_type', 'source_id', 'memo', 'posted_by', 'created_at'];

    /** Columns that may be filled in once after posting (null → value), then never change. */
    public const array ENTRY_COLUMNS_SET_ONCE = ['reverses_id', 'reversed_by_id', 'voucher_no', 'voucher_seq'];

    /** Columns that may change freely. */
    public const array ENTRY_COLUMNS_FREE = ['updated_at'];

    // No quotes: these go into SQL string literals as they are.
    private const string MESSAGE = 'Coffer: posted journal rows cannot be changed or deleted. Post a reversal instead.';

    private const string AUDIT_MESSAGE = 'Coffer: the audit trail is append-only. Its rows cannot be changed or deleted.';

    private const array TRIGGERS = [
        'lines_no_update', 'lines_no_delete', 'entries_guard_update', 'entries_no_delete',
    ];

    private const array AUDIT_TRIGGERS = ['audit_no_update', 'audit_no_delete'];

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

            if (self::hasAudit($db)) {
                $db->unprepared('DROP TRIGGER IF EXISTS '.self::PREFIX.'audit_guard ON audit_events');
            }

            $db->unprepared('DROP FUNCTION IF EXISTS '.self::PREFIX.'guard()');

            return;
        }

        foreach ([...self::TRIGGERS, ...self::AUDIT_TRIGGERS] as $name) {
            $db->unprepared('DROP TRIGGER IF EXISTS '.self::PREFIX.$name);
        }
    }

    /** True when every trigger is in place (the audit trail's too, once its table exists). */
    public static function installed(?Connection $db = null): bool
    {
        $db ??= DB::connection();

        if (! self::supported($db)) {
            return false;
        }

        $audit = self::hasAudit($db);

        if (self::driver($db) === 'pgsql') {
            $found = $db->table('pg_trigger')->where('tgname', 'like', self::PREFIX.'%')->where('tgisinternal', false)->count();

            return $found === ($audit ? 3 : 2);
        }

        $expected = $audit ? [...self::TRIGGERS, ...self::AUDIT_TRIGGERS] : self::TRIGGERS;
        $found = $db->table('information_schema.TRIGGERS')
            ->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->whereIn('TRIGGER_NAME', array_map(fn (string $name): string => self::PREFIX.$name, $expected))
            ->count();

        return $found === count($expected);
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

        $columns = $db->getSchemaBuilder()->getColumnListing('journal_entries');
        $same = [];

        foreach (self::ENTRY_COLUMNS_FIXED as $column) {
            if (in_array($column, $columns, true)) {
                $same[] = "NEW.`{$column}` <=> OLD.`{$column}`";
            }
        }

        foreach (self::ENTRY_COLUMNS_SET_ONCE as $column) {
            if (in_array($column, $columns, true)) {
                $same[] = "(OLD.`{$column}` IS NULL OR NEW.`{$column}` <=> OLD.`{$column}`)";
            }
        }

        $db->unprepared('CREATE TRIGGER '.self::PREFIX."entries_guard_update BEFORE UPDATE ON journal_entries FOR EACH ROW
            BEGIN IF NOT ({$unlocked}) AND NOT (".implode(' AND ', $same).") THEN {$refuse}; END IF; END");

        if (self::hasAudit($db)) {
            $refuseAudit = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::AUDIT_MESSAGE."'";

            foreach (['audit_no_update' => 'UPDATE', 'audit_no_delete' => 'DELETE'] as $name => $event) {
                $db->unprepared('CREATE TRIGGER '.self::PREFIX.$name." BEFORE {$event} ON audit_events FOR EACH ROW
                    BEGIN IF NOT ({$unlocked}) THEN {$refuseAudit}; END IF; END");
            }
        }
    }

    private static function installPostgres(Connection $db): void
    {
        $columns = $db->getSchemaBuilder()->getColumnListing('journal_entries');
        $allowed = '';
        $setOnce = '';

        foreach ([...self::ENTRY_COLUMNS_SET_ONCE, ...self::ENTRY_COLUMNS_FREE] as $column) {
            $allowed .= " - '{$column}'";
        }

        foreach (self::ENTRY_COLUMNS_SET_ONCE as $column) {
            if (in_array($column, $columns, true)) {
                $setOnce .= " OR (OLD.{$column} IS NOT NULL AND NEW.{$column} IS DISTINCT FROM OLD.{$column})";
            }
        }

        $message = self::MESSAGE;
        $auditMessage = self::AUDIT_MESSAGE;

        $db->unprepared('CREATE OR REPLACE FUNCTION '.self::PREFIX."guard() RETURNS trigger LANGUAGE plpgsql AS \$guard\$
            BEGIN
                IF current_setting('coffer.ledger_unlock', true) = 'on' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                IF TG_TABLE_NAME = 'audit_events' THEN
                    RAISE EXCEPTION '{$auditMessage}';
                END IF;
                IF TG_OP = 'DELETE' OR TG_TABLE_NAME = 'journal_lines' THEN
                    RAISE EXCEPTION '{$message}';
                END IF;
                IF (to_jsonb(NEW){$allowed}) IS DISTINCT FROM (to_jsonb(OLD){$allowed}){$setOnce} THEN
                    RAISE EXCEPTION '{$message}';
                END IF;
                RETURN NEW;
            END
            \$guard\$");

        $db->unprepared('CREATE TRIGGER '.self::PREFIX.'lines_guard BEFORE UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION '.self::PREFIX.'guard()');
        $db->unprepared('CREATE TRIGGER '.self::PREFIX.'entries_guard BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION '.self::PREFIX.'guard()');

        if (self::hasAudit($db)) {
            $db->unprepared('CREATE TRIGGER '.self::PREFIX.'audit_guard BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION '.self::PREFIX.'guard()');
        }
    }

    private static function hasAudit(Connection $db): bool
    {
        return $db->getSchemaBuilder()->hasTable('audit_events');
    }

    private static function driver(?Connection $db): string
    {
        return ($db ?? DB::connection())->getDriverName();
    }
}
