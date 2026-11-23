<?php
/**
 * Site URL Rules
 *
 * Sprachspezifische Routen: Die Suche liegt auf Deutsch unter /suche,
 * auf Englisch unter /en/search (Template: templates/search.twig).
 *
 * @link https://craftcms.com/docs/5.x/system/routing.html#advanced-routing-with-url-rules
 */

return [
    'de' => [
        'suche' => ['template' => 'search'],
    ],
];
