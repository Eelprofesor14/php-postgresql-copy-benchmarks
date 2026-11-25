<?php
declare(strict_types=1);

require_once __DIR__ . '/listings/listing1.php';
require_once __DIR__ . '/listings/listing2.php';
require_once __DIR__ . '/listings/listing3.php';
require_once __DIR__ . '/listings/listing4.php';

function formatCopyLine(int $ts, int $zone, float $temp, float $flow): string {
   return formatCopyRow([$ts, $zone, $temp, $flow]);
}

$dsn = getenv('PG_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=bench';
$user = getenv('PG_USER') ?: 'postgres';
$pass = getenv('PG_PASS') ?: 'postgres';

$pdo = openPgsqlPdo($dsn, $user, $pass);
$pdo->exec('DROP TABLE IF EXISTS telemetry, telemetry_stage');
$pdo->exec('CREATE TABLE telemetry (ts_epoch BIGINT NOT NULL, zone_id INT NOT NULL, temp_k DOUBLE PRECISION NOT NULL, flow DOUBLE PRECISION NOT NULL, note TEXT NULL, PRIMARY KEY (ts_epoch, zone_id))');
$pdo->exec('CREATE TABLE telemetry_stage (ts_epoch BIGINT NOT NULL, zone_id INT NOT NULL, temp_k DOUBLE PRECISION NOT NULL, flow DOUBLE PRECISION NOT NULL)');

// Test Listing 1 (ext-pgsql)
$csv = sys_get_temp_dir() . '/test_telemetry.csv';
file_put_contents($csv, "ts,zone,temp,flow\n1764000001,1,293.5,0.4\n1764000002,2,294.1,0.5\n");
$conn = pg_connect('host=127.0.0.1 port=5432 dbname=bench user=postgres password=postgres');
$loaded1 = streamCsvWithPgCopy($conn, $csv, 1);
assert($loaded1 === 2, 'Listing 1 failed');

// Test Listing 2 (Pdo\Pgsql + Generator)
$pdo->exec('TRUNCATE telemetry_stage');
$gen = (static function (): Generator {
   yield formatCopyRow([1764000010, 3, 295.0, 0.6]);
   yield formatCopyRow([1764000011, 4, 296.0, 0.7]);
})();
$loaded2 = copyGeneratorViaPdo($pdo, 'telemetry_stage (ts_epoch, zone_id, temp_k, flow)', $gen, 1);
assert($loaded2 === 2, 'Listing 2 failed');

// Test Listing 3 + Listing 4 (Escaping tabs/newlines/nulls + Staging Upsert + DLQ)
$dlq = sys_get_temp_dir() . '/dlq.jsonl';
@unlink($dlq);
$res = ingestWithStagingAndDlq($pdo, [
   ['ts_epoch' => 1764000100, 'zone_id' => 5, 'temp_k' => 293.2, 'flow' => 0.3, 'note' => "line1\nline2\twith\\slash"],
   ['ts_epoch' => 1764000101, 'zone_id' => 6, 'temp_k' => 294.0, 'flow' => 0.4, 'note' => null],
   ['ts_epoch' => 'bad_int', 'zone_id' => 7, 'temp_k' => 294.0, 'flow' => 0.4],
], $dlq);
assert($res['loaded'] === 2 && $res['rejected'] === 1, 'Listing 4 counts failed');
$note = $pdo->query('SELECT note FROM telemetry WHERE ts_epoch = 1764000100')->fetchColumn();
assert($note === "line1\nline2\twith\\slash", 'Escaping roundtrip failed');
$nullNote = $pdo->query('SELECT note FROM telemetry WHERE ts_epoch = 1764000101')->fetchColumn();
assert($nullNote === null, 'NULL marker roundtrip failed');

echo "ALL 4 LISTINGS INTEGRATION TESTS PASSED ON PHP 8.4 + POSTGRESQL 17\n";
