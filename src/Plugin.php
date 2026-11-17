<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Override;

use function CraftCms\Cms\t;

/**
 * Bloodhound — Laravel Scout for Craft elements — index entries, assets, users or products into Meilisearch, Typesense, Algolia or the database driver.
 *
 * See PLAN.md for the build phases.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public bool $hasCpSettings = true;

    public const string EDITION_LITE = 'lite';

    public const string EDITION_PRO = 'pro';

    #[Override]
    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        return Ui::make([
            Field::make(t('Index prefix', category: 'bloodhound'), Text::make('indexPrefix'))
                ->instructions(t('Prepended to every index name, e.g. the environment ($CRAFT_ENVIRONMENT).', category: 'bloodhound')),
            Field::make(t('Queue index updates', category: 'bloodhound'), Lightswitch::make('queueUpdates'))
                ->instructions(t('Push index updates to the queue instead of doing them during the save request.', category: 'bloodhound')),
            Field::make(t('Take over Craft search', category: 'bloodhound'), Lightswitch::make('takeOverSearch'))
                ->instructions(t('Answer element queries\' search param from the Scout engine instead of Craft\'s own search index (Pro).', category: 'bloodhound')),
        ]);
    }
}
