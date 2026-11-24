<?php

declare(strict_types=1);

/*
| Backups (spec §23 "daily encrypted DB backups", D96). `php artisan coffer:backup` dumps the
| database (PHP dumper or mysqldump, pg_dump on PostgreSQL) and the private and public storage into one
| AES-256 encrypted ZIP, writes it to `disk`, then deletes backups older than `keep_days`.
| Restore drill: `php artisan coffer:backup-drill` (monthly from the scheduler), docs/DEPLOY.md.
*/

return [

    // Where backups go. `backups` is storage/backups on this server (outside the archived
    // folders); point it at an off-site disk (e.g. an S3 bucket in another region) in production.
    'disk' => env('BACKUP_DISK', 'backups'),

    // Folder inside the disk.
    'path' => env('BACKUP_PATH', 'coffer'),

    // The ZIP's AES-256 password. Required: the command refuses to write an unencrypted backup.
    // Keep it in the password manager next to VAULT_MASTER_KEY; without it a backup can't be read.
    'password' => env('BACKUP_PASSWORD'),

    // Folders archived next to the dump, by name inside the ZIP's storage/ folder.
    'folders' => [
        'private' => storage_path('app/private'),
        'public' => storage_path('app/public'),
    ],

    // Backups older than this are deleted after each successful run.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // MySQL / MariaDB dumper (D150): `php` needs no binary (shared hosting) and records exact row
    // counts for the restore drill; `mysqldump` uses the binary below.
    'dumper' => env('BACKUP_DUMPER', 'php'),

    // The restore drill (`coffer:backup-drill`) loads the newest backup into this scratch
    // database on the same server and checks it. Never the live database; empty = only check
    // that the backup decrypts and reads.
    'drill_database' => env('BACKUP_DRILL_DATABASE'),

    // Dump binaries (full paths if they're not on PATH, e.g. C:/xampp/mysql/bin/mysqldump.exe).
    'mysqldump' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
    'pg_dump' => env('BACKUP_PG_DUMP_PATH', 'pg_dump'),

    // Seconds the dump may take.
    'timeout' => (int) env('BACKUP_TIMEOUT', 1800),

];
