<?php

// Bloodhound harness: a second site, `fr` (French), with the articles section on it,
// and a French title for "Hello Momentum". Idempotent. Run on a snapshot; restore afterwards.
// Run with: ddev artisan tinker --execute "require 'storage/bh-multisite-fixtures.php';"
// Craft 6 beta.1 queues no resave when a section gains a site: if it stops at "did not propagate",
// run `ddev artisan craft:resave:entries --section=articles`, then this file again.

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;

$fr = Sites::getSiteByHandle('fr');

if (! $fr) {
    $primary = Sites::getPrimarySite();
    $fr = new Site;
    $fr->groupId = $primary->groupId;
    $fr->name = 'Français';
    $fr->handle = 'fr';
    $fr->language = 'fr-FR';
    $fr->hasUrls = true;
    $fr->baseUrl = rtrim((string) config('app.url'), '/').'/fr/'; // `@web` isn't resolved in Craft 6 site URLs
    $fr->enabled = true;
    Sites::saveSite($fr) || throw new RuntimeException('site: '.json_encode($fr->errors()->all()));
}

$section = Sections::getSectionByHandle('articles');
$settings = $section->getSiteSettings();

if (! isset($settings[$fr->id])) {
    $s = new SectionSiteSettings;
    $s->siteId = $fr->id;
    $s->hasUrls = true;
    $s->uriFormat = 'articles/{slug}';
    $s->template = 'articles/_entry';
    $s->enabledByDefault = true;
    $settings[$fr->id] = $s;
    $section->setSiteSettings($settings);
    Sections::saveSection($section) || throw new RuntimeException('section: '.json_encode($section->errors()->all()));
}

// A French title for one entry (titles are per site; the harness's custom fields aren't
// translatable, so changing them here would change the English site too).
$entry = Entry::find()->slug('hello-momentum-2')->site('fr')->status(null)->one()
    ?? throw new RuntimeException('hello-momentum-2 did not propagate to fr (run the queue)');
$entry->title = 'Bonjour Montgolfière';
Elements::saveElement($entry) || throw new RuntimeException('entry: '.json_encode($entry->errors()->all()));

echo json_encode(['fr' => $fr->id, 'entry' => $entry->id]);
