<?php

namespace App\Support;

/**
 * The dashboard as widgets: which exist, their default order and size, the
 * ready-made layouts, and how a person's saved layout is read back. The
 * default layout is the dashboard as it always was, so nobody sees a
 * change until they arrange it themselves. Only the view changes; every
 * widget shows the same data as before.
 *
 * A layout: {"order": [id, …], "sizes": {id: "s"|"m"|"l"}, "hidden": [id, …]}.
 */
class DashboardWidgets
{
    /** id => default size (s = a third, m = half, l = full width on wide screens). */
    public const WIDGETS = [
        'health' => 'l',
        'tiles' => 'l',
        'timeline' => 'l',
        'pulse' => 'l',
        'attention' => 'l',
        'kpis' => 'l',
        'incidents' => 's',
        'expiring' => 's',
        'dns' => 's',
        'response' => 'm',
        'uptime' => 'm',
        'performance' => 'l',
        'servers' => 'l',
        'samples' => 'l',
    ];

    public const SIZES = ['s', 'm', 'l'];

    /** Ready-made layouts. Widgets they do not list keep their place after these, hidden. */
    public const PRESETS = [
        'developer' => [
            'order' => ['health', 'pulse', 'performance', 'servers', 'timeline', 'incidents', 'dns', 'response', 'samples'],
            'sizes' => ['incidents' => 'm', 'dns' => 'm', 'response' => 'l'],
        ],
        'manager' => [
            'order' => ['health', 'kpis', 'uptime', 'response', 'incidents', 'expiring', 'timeline', 'attention'],
            'sizes' => ['uptime' => 'm', 'response' => 'm', 'incidents' => 'm', 'expiring' => 'm'],
        ],
        'noc' => [
            'order' => ['health', 'tiles', 'pulse', 'incidents', 'timeline', 'attention'],
            'sizes' => ['incidents' => 'l'],
        ],
    ];

    /** A saved layout (or a preset) cleaned up: unknown ids dropped, every widget present. */
    public static function normalize(?array $layout): array
    {
        $ids = array_keys(self::WIDGETS);
        $order = array_values(array_unique(array_filter((array) ($layout['order'] ?? []), fn ($id) => is_string($id) && in_array($id, $ids, true))));
        $order = [...$order, ...array_values(array_diff($ids, $order))];
        $sizes = array_filter((array) ($layout['sizes'] ?? []), fn ($size, $id) => in_array($id, $ids, true) && in_array($size, self::SIZES, true), ARRAY_FILTER_USE_BOTH);
        $hidden = array_values(array_intersect($ids, (array) ($layout['hidden'] ?? [])));

        return ['order' => $order, 'sizes' => $sizes, 'hidden' => $hidden];
    }

    /** A preset as a full layout: its widgets in its order, the rest hidden. */
    public static function preset(string $name): ?array
    {
        $preset = self::PRESETS[$name] ?? null;

        if ($preset === null) {
            return null;
        }

        return self::normalize([...$preset, 'hidden' => array_values(array_diff(array_keys(self::WIDGETS), $preset['order']))]);
    }

    /** Per widget: its position, size and whether it is shown. */
    public static function resolve(?array $layout): array
    {
        $layout = self::normalize($layout);
        $out = [];

        foreach ($layout['order'] as $position => $id) {
            $out[$id] = [
                'order' => $position + 1,
                'size' => $layout['sizes'][$id] ?? self::WIDGETS[$id],
                'hidden' => in_array($id, $layout['hidden'], true),
            ];
        }

        return $out;
    }
}
