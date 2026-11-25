<?php
declare(strict_types=1);

/**
 * Escapes a single scalar value for PostgreSQL COPY
 * text format using TAB delimiter and \N for NULL.
 */
function escapeCopyField(
   string|int|float|bool|null $value
): string {
   if ($value === null) {
      return '\N';
   }
   if (is_bool($value)) {
      return $value ? 't' : 'f';
   }
   if (is_int($value)) {
      return (string) $value;
   }
   if (is_float($value)) {
      if (!is_finite($value)) {
         return '\N';
      }
      return sprintf('%.6F', $value);
   }

   // Reject invalid UTF-8 and strip NUL bytes
   if (!mb_check_encoding($value, 'UTF-8')) {
      throw new InvalidArgumentException(
         'Invalid UTF-8 byte sequence in COPY field'
      );
   }
   $clean = str_replace("\0", '', $value);

   // Escape backslash FIRST, then control chars
   return strtr($clean, [
      '\\' => '\\\\',
      "\t" => '\t',
      "\n" => '\n',
      "\r" => '\r',
   ]);
}

/**
 * Formats a list of column values into one COPY line.
 *
 * @param list<string|int|float|bool|null> $fields
 */
function formatCopyRow(array $fields): string {
   $escaped = array_map(escapeCopyField(...), $fields);
   return implode("\t", $escaped);
}
