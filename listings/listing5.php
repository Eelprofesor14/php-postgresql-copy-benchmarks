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

function measure(string $label, callable $fn): void {
   gc_collect_cycles();
   $memBefore = memory_get_usage(true);
   $t0 = hrtime(true);
   $fn();
   $ms = (hrtime(true) - $t0) / 1e6;
   $peakMb = memory_get_peak_usage(true) / 1048576;
   $deltaMb = (memory_get_usage(true) - $memBefore) / 1048576;
   printf(
      "%-28s | %8.1f ms | peak %5.1f MB (delta %+.1f MB)\n",
      $label,
      $ms,
      $peakMb,
      $deltaMb
   );
}

// 1. Row-by-row prepared INSERT
$pdo->exec('TRUNCATE bench_telemetry');
measure("Prepared INSERT ({$rows})", function () use ($pdo, $rows) {
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
measure("Batched INSERT x500 ({$rows})", function () use ($pdo, $rows) {
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

// 3. Streaming COPY FROM STDIN via Generator
$pdo->exec('TRUNCATE bench_telemetry');
measure("COPY FROM STDIN ({$rows})", function () use ($pdo, $rows) {
   $lines = (static function () use ($rows): Generator {
      foreach (generateRows($rows) as $r) {
         yield "{$r[0]}\t{$r[1]}\t{$r[2]}\t{$r[3]}";
      }
   })();

   $pdo->beginTransaction();
   if ($pdo instanceof Pgsql) {
      $pdo->copyFromArray('bench_telemetry', $lines, "\t", '\\\\N');
   } else {
      $pdo->pgsqlCopyFromArray(
         'bench_telemetry',
         $lines,
         "\t",
         '\\\\N'
      );
   }
   $pdo->commit();
});
