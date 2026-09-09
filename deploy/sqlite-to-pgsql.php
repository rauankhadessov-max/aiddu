<?php
/**
 * Однократный перенос данных из SQLite в PostgreSQL.
 *
 * Схему в PostgreSQL создаёт `php artisan migrate --force` — этот скрипт
 * только копирует строки, приводя типы к тем, что объявлены в PostgreSQL
 * (SQLite хранит булевы значения как 0/1, PostgreSQL требует true/false).
 *
 * Запуск внутри контейнера app:
 *   docker compose exec -T app php deploy/sqlite-to-pgsql.php /tmp/database.sqlite
 */

declare(strict_types=1);

$sqlitePath = $argv[1] ?? null;
if (!$sqlitePath || !is_file($sqlitePath)) {
    fwrite(STDERR, "Укажите путь к файлу SQLite: php deploy/sqlite-to-pgsql.php /tmp/database.sqlite\n");
    exit(1);
}

// Порядок важен: родительские таблицы идут раньше дочерних (внешние ключи).
const TABLES = [
    'users',
    'regulatory_profiles',
    'workspaces',
    'sources',
    'source_versions',
    'workspace_sources',
    'regulatory_profile_sources',
    'documents',
    'analyses',
    'analysis_source_versions',
    'analysis_findings',
    'analysis_amendments',
    'draft_packages',
    'artifacts',
    'attachments',
];

$src = new PDO("sqlite:$sqlitePath", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_DATABASE'));
$dst = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/** Типы колонок в PostgreSQL — по ним приводим значения. */
function pgTypes(PDO $dst, string $table): array
{
    $stmt = $dst->prepare(
        'select column_name, data_type from information_schema.columns
         where table_schema = current_schema() and table_name = ?'
    );
    $stmt->execute([$table]);

    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'data_type', 'column_name');
}

$dst->beginTransaction();
$total = 0;

try {
    foreach (TABLES as $table) {
        $exists = $src->query(
            "select count(*) from sqlite_master where type='table' and name=" . $src->quote($table)
        )->fetchColumn();

        if (!$exists) {
            echo "  пропуск: таблицы $table нет в SQLite\n";
            continue;
        }

        $types = pgTypes($dst, $table);
        if ($types === []) {
            echo "  пропуск: таблицы $table нет в PostgreSQL\n";
            continue;
        }

        $dst->exec("truncate table \"$table\" restart identity cascade");

        $rows = $src->query("select * from \"$table\"");
        $count = 0;
        $insert = null;

        foreach ($rows as $row) {
            // Оставляем только колонки, которые есть в PostgreSQL
            $row = array_intersect_key($row, $types);

            foreach ($row as $col => $value) {
                if ($value === null) {
                    continue;
                }
                if ($types[$col] === 'boolean') {
                    $row[$col] = ((int) $value === 1) ? 'true' : 'false';
                }
            }

            if ($insert === null) {
                $cols = array_map(fn ($c) => "\"$c\"", array_keys($row));
                $insert = $dst->prepare(sprintf(
                    'insert into "%s" (%s) values (%s)',
                    $table,
                    implode(', ', $cols),
                    implode(', ', array_fill(0, count($row), '?'))
                ));
            }

            $insert->execute(array_values($row));
            $count++;
        }

        // Сбрасываем счётчик последовательности, иначе первый же insert
        // в приложении упадёт на конфликте первичного ключа.
        if (isset($types['id'])) {
            $dst->exec(
                "select setval(pg_get_serial_sequence('\"$table\"', 'id'),
                 coalesce((select max(id) from \"$table\"), 1),
                 (select max(id) is not null from \"$table\"))"
            );
        }

        printf("  %-28s %d строк\n", $table, $count);
        $total += $count;
    }

    $dst->commit();
    echo "Готово. Перенесено строк: $total\n";
} catch (Throwable $e) {
    $dst->rollBack();
    fwrite(STDERR, 'ОШИБКА: ' . $e->getMessage() . "\nИзменения отменены.\n");
    exit(1);
}
