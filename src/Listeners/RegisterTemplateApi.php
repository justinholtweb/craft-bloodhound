<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Listeners;

use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use JustinHoltWeb\Bloodhound\Search\Bloodhound;

/**
 * `bloodhound` global for Twig and Blade alike (Craft feeds template globals to both).
 */
class RegisterTemplateApi
{
    public function handle(TemplateGlobalsResolving $event): void
    {
        $event->globals['bloodhound'] = app(Bloodhound::class);
    }
}
