<?php

namespace App\Services\Intelligence;

use App\Services\Intelligence\Rules\AgentOrHostRule;
use App\Services\Intelligence\Rules\ApplicationResponseRule;
use App\Services\Intelligence\Rules\DirectSignalRule;
use App\Services\Intelligence\Rules\DnsChangeRule;
use App\Services\Intelligence\Rules\MaintenanceRule;
use App\Services\Intelligence\Rules\RebootRule;
use App\Services\Intelligence\Rules\RecentChangeRule;
use App\Services\Intelligence\Rules\ResourcePressureRule;
use App\Services\Intelligence\Rules\Rule;
use App\Services\Intelligence\Rules\ServiceFailureRule;
use App\Services\Intelligence\Rules\UpstreamIncidentRule;

/**
 * Runs the rule catalog over an evidence snapshot, scores and ranks the
 * candidates. "insufficient_evidence" is always part of the result and is
 * the primary outcome when no candidate reaches medium confidence: an
 * unknown cause is a valid answer, an invented one is not.
 */
class HypothesisEngine
{
    public const RULES = [
        DirectSignalRule::class,
        MaintenanceRule::class,
        UpstreamIncidentRule::class,
        AgentOrHostRule::class,
        ServiceFailureRule::class,
        ApplicationResponseRule::class,
        RebootRule::class,
        ResourcePressureRule::class,
        DnsChangeRule::class,
        RecentChangeRule::class,
    ];

    public function __construct(private ConfidenceScorer $scorer, private NextStepAdvisor $advisor) {}

    /**
     * @return array{hypotheses: array<int, array<string, mixed>>, rejected: array<int, array<string, mixed>>, primary: string}
     */
    public function evaluate(InvestigationContext $context): array
    {
        $scored = [];
        $rejected = [];

        foreach (self::RULES as $class) {
            /** @var Rule $rule */
            $rule = app($class);

            foreach ($rule->evaluate($context) as $candidate) {
                $row = [
                    'code' => $candidate->code,
                    'resource' => $candidate->resource,
                    'params' => $candidate->params,
                    'base' => $candidate->base,
                    'supporting' => array_values(array_unique($candidate->supporting)),
                    'contradicting' => array_values(array_unique($candidate->contradicting)),
                    'missing' => $candidate->missing,
                    'path' => $candidate->path,
                ];

                if ($candidate->rejected !== null) {
                    $rejected[] = [...$row, 'reason' => $candidate->rejected];

                    continue;
                }

                $score = $this->scorer->score($candidate, $context);
                $scored[] = [...$row, 'confidence' => $score['level'], 'reasons' => $score['reasons'], 'priority' => $candidate->priority,
                    'first_at' => $this->firstAt($candidate->supporting, $context)];
            }
        }

        // Strongest first; then the more direct kind of evidence; then the earliest.
        usort($scored, fn ($a, $b) => [-Confidence::rank($a['confidence']), $a['priority'], $a['first_at'] ?? PHP_INT_MAX, $a['code'], (string) $a['resource']]
            <=> [-Confidence::rank($b['confidence']), $b['priority'], $b['first_at'] ?? PHP_INT_MAX, $b['code'], (string) $b['resource']]);

        $insufficient = ['code' => 'insufficient_evidence', 'resource' => $context->subject(), 'params' => [], 'base' => 'insufficient', 'supporting' => [], 'contradicting' => [],
            'missing' => $this->missing($context), 'path' => [], 'confidence' => 'insufficient', 'reasons' => ['no_candidate_reached_medium'], 'priority' => 9, 'first_at' => null];

        $leading = $scored !== [] && Confidence::atLeast($scored[0]['confidence'], 'medium');
        $hypotheses = $leading ? [...$scored, $insufficient] : [$insufficient, ...$scored];

        foreach ($hypotheses as $i => &$hypothesis) {
            $hypothesis['key'] = 'H'.($i + 1);
            $hypothesis['next_steps'] = $this->advisor->forHypothesis($hypothesis, $context);
            unset($hypothesis['priority'], $hypothesis['first_at']);
        }

        unset($hypothesis);

        return ['hypotheses' => $hypotheses, 'rejected' => $rejected, 'primary' => $hypotheses[0]['key']];
    }

    /** @param  array<int, string>  $keys */
    private function firstAt(array $keys, InvestigationContext $context): ?int
    {
        $times = array_filter(array_map(fn ($k) => InvestigationContext::at($context->evidence[$k] ?? [])?->getTimestamp(), $keys));

        return $times === [] ? null : min($times);
    }

    /** @return array<int, string> */
    private function missing(InvestigationContext $context): array
    {
        $missing = [];

        foreach ($context->gaps as $gap) {
            $missing[] = match ($gap['code']) {
                'no_dependencies' => 'dependencies',
                'no_metrics', 'no_raw' => 'metrics',
                'no_subject' => 'monitored_resource',
                'collector_failed' => 'complete_collection',
                default => null,
            };
        }

        return array_values(array_unique(array_filter($missing)));
    }
}
