<?php

// Themes (Profile → Appearance, command palette). Light and dark are the
// defaults; the others apply only when chosen explicitly.
return [
    'title' => 'Appearance',
    'text' => 'Light and dark follow your system unless you choose. The other themes apply only when you pick one here. Stored in this browser.',
    'noscript' => 'Choosing a theme needs JavaScript.',
    'default' => 'Default',
    'extra' => 'Extra themes',
    'names' => [
        'system' => 'System',
        'light' => 'Light',
        'dark' => 'Dark',
        'midnight' => 'Midnight',
        'terminal' => 'Terminal',
        'contrast' => 'High contrast',
    ],
    'descriptions' => [
        'system' => 'Light or dark, as your device is set',
        'light' => 'Always light',
        'dark' => 'Always dark',
        'midnight' => 'Deep blue with a neon glow',
        'terminal' => 'Green on black, monospace',
        'contrast' => 'Maximum contrast, strong outlines',
    ],
    'palette' => 'Theme: :name',
];
