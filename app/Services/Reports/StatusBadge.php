<?php

namespace App\Services\Reports;

/**
 * An animated SVG status badge for READMEs and websites: a pulsing dot in
 * the state colour, a label and the state in words. Self-contained (no
 * fonts, scripts or external references); the pulse is a CSS animation
 * that stops for people who prefer reduced motion.
 */
class StatusBadge
{
    public const THEMES = ['light', 'dark'];

    /** State colours per theme, readable on the badge background (WCAG AA for the text). */
    private const COLORS = [
        'light' => ['operational' => '#1a7f37', 'degraded' => '#9a6700', 'partial_outage' => '#bc4c00', 'major_outage' => '#cf222e', 'maintenance' => '#0969da', 'unknown' => '#6e7781'],
        'dark' => ['operational' => '#3fb950', 'degraded' => '#d29922', 'partial_outage' => '#f0883e', 'major_outage' => '#f85149', 'maintenance' => '#58a6ff', 'unknown' => '#8b949e'],
    ];

    private const SURFACE = [
        'light' => ['bg' => '#ffffff', 'border' => '#d0d7de', 'label' => '#57606a', 'divider' => '#eaeef2'],
        'dark' => ['bg' => '#0d1117', 'border' => '#30363d', 'label' => '#8b949e', 'divider' => '#21262d'],
    ];

    /** Seconds per pulse: calm when all is well, urgent during an outage. */
    private const PULSE = ['operational' => 2.4, 'degraded' => 1.6, 'partial_outage' => 1.3, 'major_outage' => 0.9, 'maintenance' => 2.4, 'unknown' => 0];

    public function render(string $label, string $state, string $stateText, string $theme = 'light'): string
    {
        $theme = in_array($theme, self::THEMES, true) ? $theme : 'light';
        $state = isset(self::COLORS[$theme][$state]) ? $state : 'unknown';
        $color = self::COLORS[$theme][$state];
        $surface = self::SURFACE[$theme];
        $pulse = self::PULSE[$state];

        $labelWidth = $this->textWidth($label, false);
        $stateWidth = $this->textWidth($stateText, true);
        $height = 24;
        $labelX = 26;
        $dividerX = $labelX + $labelWidth + 9;
        $stateX = $dividerX + 9;
        $width = $stateX + $stateWidth + 12;

        $e = fn (string $text) => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $title = $e($label.': '.$stateText);
        $ring = $pulse > 0
            ? '<circle class="ring" cx="14" cy="12" r="4" fill="'.$color.'"/>'
            : '';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}" role="img" aria-label="{$title}">
<title>{$title}</title>
<style>
.ring{transform-box:fill-box;transform-origin:center;animation:p {$pulse}s ease-out infinite}
@keyframes p{0%{transform:scale(1);opacity:.55}100%{transform:scale(2.6);opacity:0}}
@media (prefers-reduced-motion:reduce){.ring{animation:none;opacity:0}}
text{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;font-size:11px}
</style>
<rect x=".5" y=".5" width="{$this->n($width - 1)}" height="23" rx="11.5" fill="{$surface['bg']}" stroke="{$surface['border']}"/>
{$ring}<circle cx="14" cy="12" r="4" fill="{$color}"/>
<text x="{$labelX}" y="15.5" fill="{$surface['label']}" textLength="{$labelWidth}" lengthAdjust="spacingAndGlyphs">{$e($label)}</text>
<line x1="{$dividerX}" y1="5" x2="{$dividerX}" y2="19" stroke="{$surface['divider']}"/>
<text x="{$stateX}" y="15.5" fill="{$color}" font-weight="600" textLength="{$stateWidth}" lengthAdjust="spacingAndGlyphs">{$e($stateText)}</text>
</svg>
SVG;
    }

    /**
     * Approximate width of 11px system-UI text, so the badge fits without
     * measuring fonts; textLength makes every renderer use exactly this.
     */
    private function textWidth(string $text, bool $bold): int
    {
        $width = 0.0;

        foreach (mb_str_split($text) as $char) {
            $width += match (true) {
                str_contains('iIl.,:;|!\'`', $char) => 3.0,
                str_contains('fjrt()[]{}- /', $char) => 4.0,
                str_contains('mwMW', $char) => 9.2,
                ctype_upper($char) || ctype_digit($char) => 7.2,
                default => 6.1,
            };
        }

        return (int) ceil($width * ($bold ? 1.06 : 1.0));
    }

    private function n(float|int $value): string
    {
        return (string) $value;
    }
}
