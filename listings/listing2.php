<?php
declare(strict_types=1);

use Pdo\Pgsql;

/**
 * Connects via driver-specific Pdo\Pgsql on PHP 8.4+
 * to avoid the PHP 8.5 PDO::pgsqlCopyFromArray deprecation.
 */
function openPgsqlPdo(
   string $dsn,
   string $user,
   string $pass
): PDO {
   $opts = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
   ];

   if (class_exists(Pgsql::class)) {
      return PDO::connect($dsn, $user, $pass, $opts);
   }

   return new PDO($dsn, $user, $pass, $opts);
}

/**
 * Streams an iterable/Generator into PostgreSQL COPY
 * using bounded array chunks (PHP 8.4+ strict-typed).
 *
 * @param iterable<int, string> $lines
 */
function copyGeneratorViaPdo(
   PDO $pdo,
   string $tableWithCols,
   iterable $lines,
   int $chunkSize = 10_000
): int {
   $total = 0;
   $chunk = [];

   $pdo->beginTransaction();
   try {
      foreach ($lines as $line) {
         $chunk[] = $line;
         if (count($chunk) >= $chunkSize) {
            flushPdoCopyChunk($pdo, $tableWithCols, $chunk);
            $total += count($chunk);
            $chunk = [];
         }
      }

      if ($chunk !== []) {
         flushPdoCopyChunk($pdo, $tableWithCols, $chunk);
         $total += count($chunk);
      }

      $pdo->commit();
      return $total;
   } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
         $pdo->rollBack();
      }
      throw $e;
   }
}

/**
 * @param list<string> $rows
 */
function flushPdoCopyChunk(
   PDO $pdo,
   string $tableWithCols,
   array $rows
): void {
   $ok = ($pdo instanceof Pgsql)
      ? $pdo->copyFromArray(
         $tableWithCols,
         $rows,
         "\t",
         '\\\\N'
      )
      : $pdo->pgsqlCopyFromArray(
         $tableWithCols,
         $rows,
         "\t",
         '\\\\N'
      );

   if ($ok === false) {
      throw new RuntimeException('PDO COPY returned false');
   }
}
