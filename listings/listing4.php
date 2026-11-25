<?php
declare(strict_types=1);

use Pdo\Pgsql;

/**
 * Ingests a batch via TEMP staging table + idempotent
 * upsert, routing malformed rows to a dead-letter file.
 *
 * @param iterable<int, array<string, mixed>> $records
 * @return array{loaded: int, rejected: int}
 */
function ingestWithStagingAndDlq(
   PDO $pdo,
   iterable $records,
   string $dlqPath
): array {
   $dlq = fopen($dlqPath, 'ab');
   if ($dlq === false) {
      throw new RuntimeException("Cannot open DLQ: {$dlqPath}");
   }

   $validLines = [];
   $rejected = 0;

   try {
      foreach ($records as $idx => $rec) {
         try {
            $validLines[] = formatCopyRow([
               validateInt($rec['ts_epoch'] ?? null, 'ts'),
               validateInt($rec['zone_id'] ?? null, 'zone'),
               validateFloat($rec['temp_k'] ?? null, 'temp'),
               validateFloat($rec['flow'] ?? null, 'flow'),
               isset($rec['note'])
                  ? (string) $rec['note']
                  : null,
            ]);
         } catch (InvalidArgumentException $e) {
            $rejected++;
            fwrite($dlq, json_encode([
               'line' => $idx + 1,
               'error' => $e->getMessage(),
               'payload' => $rec,
            ], JSON_UNESCAPED_SLASHES) . "\n");
         }
      }
   } finally {
      fclose($dlq);
   }

   if ($validLines === []) {
      return ['loaded' => 0, 'rejected' => $rejected];
   }

   $pdo->beginTransaction();
   try {
      $pdo->exec(
         'CREATE TEMP TABLE telemetry_stage '
         . '(LIKE telemetry INCLUDING DEFAULTS) '
         . 'ON COMMIT DROP'
      );

      $cols = 'telemetry_stage '
         . '(ts_epoch, zone_id, temp_k, flow, note)';
      if ($pdo instanceof Pgsql) {
         $pdo->copyFromArray($cols, $validLines, "\t", '\\\\N');
      } else {
         $pdo->pgsqlCopyFromArray(
            $cols,
            $validLines,
            "\t",
            '\\\\N'
         );
      }

      $pdo->exec(
         'INSERT INTO telemetry '
         . '(ts_epoch, zone_id, temp_k, flow, note) '
         . 'SELECT ts_epoch, zone_id, temp_k, flow, note '
         . 'FROM telemetry_stage '
         . 'ON CONFLICT (ts_epoch, zone_id) DO UPDATE '
         . 'SET temp_k = EXCLUDED.temp_k, '
         . 'flow = EXCLUDED.flow, '
         . 'note = EXCLUDED.note'
      );

      $pdo->commit();
   } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
         $pdo->rollBack();
      }
      throw $e;
   }

   return [
      'loaded' => count($validLines),
      'rejected' => $rejected,
   ];
}

function validateInt(mixed $v, string $name): int {
   if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
      throw new InvalidArgumentException("Invalid {$name}");
   }
   return (int) $v;
}

function validateFloat(mixed $v, string $name): float {
   if (!is_numeric($v)) {
      throw new InvalidArgumentException("Invalid {$name}");
   }
   return (float) $v;
}
