<?php

namespace App\Services\Risk;

/**
 * The readable risk sentence: «På grunn av [årsak] kan [hendelse] skje, noe som kan føre til
 * [konsekvens].» Composed from the three parts on read — never stored and never typed by the user.
 *
 * The parts are used as written, apart from trailing punctuation, which would otherwise break the
 * sentence. Nothing is lower-cased or reworded: the text is the person's, not ours.
 */
class RiskStatement
{
    public function compose(?string $cause, ?string $event, ?string $consequence): ?string
    {
        $parts = array_map(fn (?string $part): string => $this->clean($part), [$cause, $event, $consequence]);

        if (in_array('', $parts, true)) {
            return null;
        }

        [$cause, $event, $consequence] = $parts;

        return __('procynia.risk.statement.sentence', [
            'cause' => $cause,
            'event' => $event,
            'consequence' => $consequence,
        ]);
    }

    private function clean(?string $part): string
    {
        return rtrim(trim((string) $part), " \t\n\r\0\x0B.!?,;:");
    }
}
