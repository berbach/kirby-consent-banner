<?php

declare(strict_types=1);

namespace Bb\ConsentBanner;

use Kirby\Cms\Page;
use Kirby\Content\VersionId;
use Kirby\Exception\NotFoundException;

/**
 * The banner, its groups and its components are pages only to be edited in
 * the panel. In the frontend they do not exist: their URL answers with the
 * error page.
 */
class HiddenPage extends Page
{
    public function render(
        array $data = [],
        $contentType = 'html',
        VersionId|string|null $versionId = null,
    ): string {
        throw new NotFoundException(['fallback' => 'The page does not exist']);
    }
}
