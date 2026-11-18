<?php

declare(strict_types=1);

namespace Bb\ConsentBanner;

use Kirby\Form\Field\TextField;

/**
 * The field "consentservice" for Kirby 6: a text field; the panel component
 * k-consentservice-field (index.js) adds the search in the service database.
 * Kirby 6 only accepts fields as classes, Kirby 5 gets the array definition
 * in index.php. The type comes from the class name.
 */
class ConsentServiceField extends TextField
{
}
