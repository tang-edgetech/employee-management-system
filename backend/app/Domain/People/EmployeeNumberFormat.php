<?php

namespace App\Domain\People;

use Carbon\CarbonInterface;

/**
 * The employee number pattern (D22), e.g. "HJ-{seq}" -> HJ-0052.
 *
 * Tokens: {seq} running number padded to `digits` (required, exactly once),
 * {yyyy} / {yy} join year, {dept} department code (initials, up to 3 letters).
 * Other characters: letters, digits and - _ / .
 */
final class EmployeeNumberFormat
{
    public const TOKENS = ['{seq}', '{yyyy}', '{yy}', '{dept}'];

    /** employees.employee_no is VARCHAR(20). */
    public const MAX_LENGTH = 20;

    public function __construct(
        public readonly string $pattern,
        public readonly int $digits,
    ) {}

    /** Why the pattern is unusable, or null when it is fine. */
    public static function problem(string $pattern, int $digits): ?string
    {
        if (substr_count($pattern, '{seq}') !== 1) {
            return 'The format must contain {seq} exactly once.';
        }
        $literal = str_replace(self::TOKENS, '', $pattern);
        if (str_contains($literal, '{') || str_contains($literal, '}')) {
            return 'Unknown token. Use {seq}, {yyyy}, {yy} or {dept}.';
        }
        if (! preg_match('#^[A-Za-z0-9\-_/.]*$#', $literal)) {
            return 'Use only letters, digits and - _ / . outside the tokens.';
        }
        if ((new self($pattern, $digits))->maxLength() > self::MAX_LENGTH) {
            return 'Numbers made with this format can be longer than '.self::MAX_LENGTH.' characters.';
        }

        return null;
    }

    /** The longest number this format can produce. */
    public function maxLength(): int
    {
        return strlen(str_replace(['{seq}', '{yyyy}', '{yy}', '{dept}'], [str_repeat('9', $this->digits), '9999', '99', 'XXX'], $this->pattern));
    }

    public function format(int $seq, ?CarbonInterface $joined = null, ?string $department = null): string
    {
        $year = ($joined ?? now())->format('Y');

        return str_replace(
            ['{seq}', '{yyyy}', '{yy}', '{dept}'],
            [str_pad((string) $seq, $this->digits, '0', STR_PAD_LEFT), $year, substr($year, 2), self::departmentCode($department)],
            $this->pattern,
        );
    }

    /** "Back End" -> "BE", "Customer Service" -> "CS", "Management" -> "M". */
    public static function departmentCode(?string $department): string
    {
        $words = preg_split('/\s+/', trim((string) $department), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $code = strtoupper(substr(implode('', array_map(fn (string $w) => $w[0], $words)), 0, 3));

        return preg_replace('/[^A-Z0-9]/', '', $code) ?: 'XX';
    }
}
