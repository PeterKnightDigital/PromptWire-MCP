<?php

namespace PromptWire\UI;

/**
 * Shared Lucide icon SVG helper.
 *
 * Central registry of Lucide icon paths used across PromptWire modules.
 * Add new icons here and they become available everywhere via:
 *
 *   LucideIcons::render('file-input')
 *   LucideIcons::render('file-input', 20)   // custom size
 *
 * Icon paths sourced from https://lucide.dev (v0.563.0)
 */
class LucideIcons
{
    /**
     * SVG inner markup keyed by icon name.
     * Only the elements inside the <svg> wrapper — no outer tag.
     */
    protected static array $icons = [
        // Arrows / generic
        'download'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'upload'          => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
        'arrow-left'      => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',

        // File actions
        'file-input'      => '<path d="M4 11V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.706.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M2 15h10"/><path d="m9 18 3-3-3-3"/>',
        'file-output'     => '<path d="M4.226 20.925A2 2 0 0 0 6 22h12a2 2 0 0 0 2-2V8a2.4 2.4 0 0 0-.706-1.706l-3.588-3.588A2.4 2.4 0 0 0 14 2H6a2 2 0 0 0-2 2v3.127"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="m5 11-3 3"/><path d="m5 17-3-3h10"/>',
        'file-text'       => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'file-braces'     => '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 12a1 1 0 0 0-1 1v1a1 1 0 0 1-1 1 1 1 0 0 1 1 1v1a1 1 0 0 0 1 1"/><path d="M14 18a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1 1 1 0 0 1-1-1v-1a1 1 0 0 0-1-1"/>',

        // UI chrome
        'refresh-cw'      => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/>',
        'wrench'          => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z"/>',
        'check'           => '<path d="M20 6 9 17l-5-5"/>',
        'search'          => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'activity'        => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',

        // Chevrons
        'chevron-right'   => '<path d="m9 18 6-6-6-6"/>',
        'chevron-down'    => '<path d="m6 9 6 6 6-6"/>',

        // Layout / toggles
        'layout-template' => '<rect width="18" height="7" x="3" y="3" rx="1"/><rect width="9" height="7" x="3" y="14" rx="1"/><rect width="5" height="7" x="16" y="14" rx="1"/>',
        'toggle-right'    => '<rect width="20" height="12" x="2" y="6" rx="6"/><circle cx="16" cy="12" r="2"/>',
        'toggle-left'     => '<rect width="20" height="12" x="2" y="6" rx="6"/><circle cx="8" cy="12" r="2"/>',
    ];

    /**
     * CSS class applied to every rendered SVG.
     */
    protected static string $cssClass = 'pwmcp-lucide';

    /**
     * Render a Lucide icon as an inline SVG element.
     *
     * @param string $name  Icon name (e.g. 'file-input')
     * @param int    $size  Width & height in pixels (default 16)
     * @return string       SVG markup, or empty string if icon not found
     */
    public static function render(string $name, int $size = 16): string
    {
        $inner = static::$icons[$name] ?? '';
        if ($inner === '') return '';

        return '<svg class="' . static::$cssClass . '"'
            . ' xmlns="http://www.w3.org/2000/svg"'
            . ' width="' . $size . '" height="' . $size . '"'
            . ' viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . $inner
            . '</svg>';
    }

    /**
     * Check whether an icon name is registered.
     */
    public static function has(string $name): bool
    {
        return isset(static::$icons[$name]);
    }

    /**
     * Get all registered icon names.
     *
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(static::$icons);
    }
}
