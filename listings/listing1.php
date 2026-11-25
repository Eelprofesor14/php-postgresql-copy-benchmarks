<?php
declare(strict_types=1);

use PgSql\Connection;

/**
 * Streams validated CSV rows into PostgreSQL in bounded
 * COPY chunks using constant PHP memory.
 */
function streamCsvWithPgCopy(
   Connection $conn,
   string $csvPath,
   int $chunkSize = 10_000
): int {
   $file = new SplFileObject($csvPath, 'r');
   $file->setFlags(
      SplFileObject::READ_CSV
      | SplFileObject::SKIP_EMPTY
      | SplFileObject::DROP_NEW_LINE
   );

   // Skip header row
   $file->fgetcsv();

   $total = 0;
   $buffer = [];

   while (!$file->eof()) {
      $row = $file->fgetcsv();
      if (!is_array($row) || count($row) < 4) {
         continue;
      }

      $buffer[] = formatCopyLine(
         (int) $row[0],
         (int) $row[1],
         (float) $row[2],
         (float) $row[3]
      );

      if (count($buffer) >= $chunkSize) {
         flushPgCopyChunk($conn, $buffer);
         $total += count($buffer);
         $buffer = [];
      }
   }

   if ($buffer !== []) {
      flushPgCopyChunk($conn, $buffer);
      $total += count($buffer);
   }

   return $total;
}

function flushPgCopyChunk(
   Connection $conn,
   array $lines
): void {
   pg_query($conn, 'BEGIN');
   $ok = pg_copy_from(
      $conn,
      'telemetry_stage (ts_epoch, zone_id, temp_k, flow)',
      $lines,
      "\t",
      '\\N'
   );

   if ($ok === false) {
      $err = pg_last_error($conn);
      pg_query($conn, 'ROLLBACK');
      throw new RuntimeException("COPY failed: {$err}");
   }

   pg_query($conn, 'COMMIT');
}
