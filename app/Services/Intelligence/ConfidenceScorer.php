<?php

namespace App\Services\Intelligence;

/**
 * Turns a rule's candidate into a scored hypothesis with explicit rules:
 *
 * 1. Start at the rule's base level (what that kind of evidence allows).
 * 2. No supporting evidence: insufficient.
 * 3. Contradicting evidence: one level lower.
 * 4. A collector the rule depends on failed or hit its row limit, or the
 *    server's metric history is missing: at most medium.
 * 5. Evidence that was cut off by the evidence limit: at most medium.
 *
 * Every adjustment adds a reason code, so the interface can say why.
 */
class ConfidenceScorer
{
    /** @return array{level: string, reasons: array<int, string>} */
    public function score(Candidate $candidate, InvestigationContext $context): array
    {
        $level = $candidate->base;
        $reasons = $candidate->basis;

        if ($candidate->supporting === []) {
            return ['level' => 'insufficient', 'reasons' => [...$reasons, 'no_supporting_evidence']];
        }

        if ($candidate->contradicting !== []) {
            $level = Confidence::down($level);
            $reasons[] = 'contradicted';
        }

        if ($context->failed($candidate->sources)) {
            $level = Confidence::cap($level, 'medium');
            $reasons[] = 'collector_failed';
        }

        if ($context->wasTruncated($candidate->sources) || in_array('evidence', $context->truncated, true)) {
            $level = Confidence::cap($level, 'medium');
            $reasons[] = 'truncated';
        }

        if ($candidate->metricsOf !== null && $context->metricGap($candidate->metricsOf)) {
            $level = Confidence::cap($level, 'medium');
            $reasons[] = 'data_gap';
        }

        return ['level' => $level, 'reasons' => array_values(array_unique($reasons))];
    }
}
