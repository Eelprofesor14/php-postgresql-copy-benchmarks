<?php
declare(strict_types=1);

use Pdo\Pgsql;

$dsn = getenv('PG_DSN')
   ?: 'pgsql:host=127.0.0.1;port=5432;dbname=bench';
$user = getenv('PG_USER') ?: 'postgres';
$pass = getenv('PG_PASS') ?: 'postgres';
$rows = (int) ($argv[1] ?? 50_000);

$pdo = class_exists(Pgsql::class)
   ? PDO::connect($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
   ])
   : new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
   ]);

$pdo->exec('DROP TABLE IF EXISTS bench_telemetry');
$pdo->exec(
   'CREATE TABLE bench_telemetry ('
   . 'ts_epoch BIGINT NOT NULL, '
   . 'zone_id INT NOT NULL, '
   . 'temp_k DOUBLE PRECISION NOT NULL, '
   . 'flow DOUBLE PRECISION NOT NULL)'
);

function generateRows(int $count): Generator {
   $base = 1764000000;
   for ($i = 0; $i < $count; $i++) {
      yield [
         $base + $i,
         $i % 120,
         293.15 + (($i % 50) * 0.05),
         0.25 + (($i % 10) * 0.05),
      ];
   }
}

function measure(
   string $label,
   int $rows,
   callable $fn
): void {
   if (function_exists('memory_reset_peak_usage')) {
      memory_reset_peak_usage();
   }
   gc_collect_cycles();
   $t0 = hrtime(true);
   $fn();
   $ms = (hrtime(true) - $t0) / 1e6;
   $rps = $rows / ($ms / 1000.0);
   $peakMb = memory_get_peak_usage(false) / 1048576;
   printf(
      "%-28s | %7.1f ms | %9.0f rows/s | %4.2f MB\n",
      $label,
      $ms,
      $rps,
      $peakMb
   );
}

// 1. Row-by-row prepared INSERT
$pdo->exec('TRUNCATE bench_telemetry');
measure("Prepared INSERT ({$rows})", $rows, function () use (
   $pdo,
   $rows
) {
   $pdo->beginTransaction();
   $st = $pdo->prepare(
      'INSERT INTO bench_telemetry VALUES (?, ?, ?, ?)'
   );
   foreach (generateRows($rows) as $r) {
      $st->execute($r);
   }
   $pdo->commit();
});

// 2. Multi-row batched INSERT (500 rows/query)
$pdo->exec('TRUNCATE bench_telemetry');
measure("Batched INSERT x500 ({$rows})", $rows, function () use (
   $pdo,
   $rows
) {
   $pdo->beginTransaction();
   $batch = [];
   $vals = [];
   foreach (generateRows($rows) as $r) {
      $batch[] = '(?,?,?,?)';
      array_push($vals, ...$r);
      if (count($batch) === 500) {
         $st = $pdo->prepare(
            'INSERT INTO bench_telemetry VALUES '
            . implode(',', $batch)
         );
         $st->execute($vals);
         $batch = [];
         $vals = [];
      }
   }
   if ($batch !== []) {
      $st = $pdo->prepare(
         'INSERT INTO bench_telemetry VALUES '
         . implode(',', $batch)
      );
      $st->execute($vals);
   }
   $pdo->commit();
});

// 3. Streaming COPY FROM STDIN via bounded Generator chunks
$pdo->exec('TRUNCATE bench_telemetry');
measure("COPY FROM STDIN ({$rows})", $rows, function () use (
   $pdo,
   $rows
) {
   $pdo->beginTransaction();
   $chunk = [];
   foreach (generateRows($rows) as $r) {
      $chunk[] = "{$r[0]}\t{$r[1]}\t{$r[2]}\t{$r[3]}";
      if (count($chunk) === 10_000) {
         if ($pdo instanceof Pgsql) {
            $pdo->copyFromArray(
               'bench_telemetry',
               $chunk,
               "\t",
               '\\\\N'
            );
         } else {
            $pdo->pgsqlCopyFromArray(
               'bench_telemetry',
               $chunk,
               "\t",
               '\\\\N'
            );
         }
         $chunk = [];
      }
   }
   if ($chunk !== []) {
      if ($pdo instanceof Pgsql) {
         $pdo->copyFromArray(
            'bench_telemetry',
            $chunk,
            "\t",
            '\\\\N'
         );
      } else {
         $pdo->pgsqlCopyFromArray(
            'bench_telemetry',
            $chunk,
            "\t",
            '\\\\N'
         );
      }
   }
   $pdo->commit();
});
