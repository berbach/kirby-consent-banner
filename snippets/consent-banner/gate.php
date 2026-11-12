<?php
/**
 * Puts content behind the consent for a component. The visitor sees a
 * placeholder with an accept toggle; on consent the content appears without
 * a page reload.
 *
 *   <?php snippet('consent-banner/gate', ['component' => 'youtube'], slots: true) ?>
 *     <iframe src="https://www.youtube-nocookie.com/embed/…"></iframe>
 *   <?php endsnippet() ?>
 *
 * @var string $component slug or id of the component
 * @var string|null $class more classes for the placeholder, e.g. for its height
 * @var array<string, string>|null $attributes more attributes for the placeholder
 * @var \Kirby\Template\Slot|null $slot
 */
echo consentBanner()->gate(
    (string) ($component ?? ''),
    (string) ($slot ?? ''),
    (string) ($class ?? \Bb\ConsentBanner\Placeholder::VARIABLE_HEIGHT),
    is_array($attributes ?? null) ? $attributes : [],
);
