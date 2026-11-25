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
 * Streams a Generator directly into PostgreSQL COPY.
 *
 * @param iterable<int, string> $lines
 */
function copyGeneratorViaPdo(
   PDO $pdo,
   string $tableWithCols,
   iterable $lines
): void {
   $rows = is_array($lines) || $lines instanceof Traversable
      ? $lines
      : (static function () use ($lines): Generator {
         yield from $lines;
      })();

   $pdo->beginTransaction();
   try {
      if ($pdo instanceof Pgsql) {
         $ok = $pdo->copyFromArray(
            $tableWithCols,
            $rows,
            "\t",
            '\\\\N'
         );
      } else {
         $ok = $pdo->pgsqlCopyFromArray(
            $tableWithCols,
            $rows,
            "\t",
            '\\\\N'
         );
      }

      if ($ok === false) {
         throw new RuntimeException('PDO COPY returned false');
      }
      $pdo->commit();
   } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
         $pdo->rollBack();
      }
      throw $e;
   }
}
