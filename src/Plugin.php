<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound;

use CraftCms\Cms\Plugin\Events\PluginSettingsSaved;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Search\Search as CraftSearchService;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\Search as SearchFacade;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Table;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Bloodhound\Search\Bloodhound;
use JustinHoltWeb\Bloodhound\Search\CraftSearch;
use Override;

use function CraftCms\Cms\t;

/**
 * Bloodhound — Laravel Scout for Craft elements: index entries and assets into
 * Meilisearch, Algolia or any Scout engine, and search them from Twig or Blade.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const string EDITION_LITE = 'lite';

    public const string EDITION_PRO = 'pro';

    public bool $hasCpSettings = true;

    public static function isPro(): bool
    {
        return Plugins::getPlugin('bloodhound')?->is(self::EDITION_PRO) ?? false;
    }

    protected array $events = [
        TemplateGlobalsResolving::class => Listeners\RegisterTemplateApi::class,
        PluginSettingsSaved::class => Listeners\PushEngineSettings::class,
    ];

    protected array $commands = [
        Commands\ImportCommand::class,
        Commands\FlushCommand::class,
        Commands\SyncScheduledCommand::class,
        Commands\StatusCommand::class,
        Commands\RepairCommand::class,
    ];

    protected array $utilities = [
        Utilities\BloodhoundUtility::class,
    ];

    #[Override]
    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public function boot(): void
    {
        $this->app->bind(Settings::class, fn (): Settings => $this->getSettings());
        $this->app->scoped(Bloodhound::class);
        Event::subscribe(Listeners\SyncOnElementChange::class);

        if ($this->is(self::EDITION_PRO)) {
            // Pro: Craft's `.search()` can be answered by the engine (setting `replaceCraftSearch`).
            $this->app->extend(CraftSearchService::class, fn (CraftSearchService $craft) => $craft instanceof CraftSearch ? $craft : CraftSearch::replacing($craft));
            SearchFacade::clearResolvedInstance(CraftSearchService::class);
        }
    }

    #[Override]
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('craft:bloodhound:sync-scheduled')->everyFifteenMinutes()->withoutOverlapping();
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
            Field::make(t('Indexes', category: 'bloodhound'), Table::make('indexes')
                ->columns([
                    'handle' => ['heading' => t('Handle', category: 'bloodhound'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'articles'],
                    'elementType' => ['heading' => t('Elements', category: 'bloodhound'), 'type' => 'select', 'options' => [
                        ['label' => t('Entries', category: 'bloodhound'), 'value' => 'entries'],
                        ['label' => t('Assets', category: 'bloodhound'), 'value' => 'assets'],
                    ]],
                    'sources' => ['heading' => t('Sections / volumes', category: 'bloodhound'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'news, blog'],
                    'fields' => ['heading' => t('Fields to index', category: 'bloodhound'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'summary, body'],
                ])
                ->allowAdd()->allowDelete()->allowReorder()
                ->addRowLabel(t('Add an index', category: 'bloodhound')))
                ->instructions(t('Each index is kept per site in your Scout engine (SCOUT_DRIVER). Only live elements are indexed. Run `craft:bloodhound:import` after changing an index.', category: 'bloodhound')),
            Field::make(t('Index prefix', category: 'bloodhound'), Text::make('indexPrefix')->placeholder('$CRAFT_ENVIRONMENT'))
                ->instructions(t('Prepended to engine index names so environments don’t share indexes.', category: 'bloodhound')),
            Field::make(t('Queue index updates', category: 'bloodhound'), Lightswitch::make('queueUpdates'))
                ->instructions(t('Write to the engine from the queue instead of during the save request.', category: 'bloodhound')),
            ...($this->is(self::EDITION_PRO) ? [
                Field::make(t('Answer Craft’s search from the engine', category: 'bloodhound'), Lightswitch::make('replaceCraftSearch'))
                    ->instructions(t('Front-end `.search()` queries whose results all live in one index (live entries or assets of its sections or volumes, one site) are answered by the engine. Everything else, including the control panel, drafts, disabled entries and Craft search syntax like `title:foo`, keeps using Craft’s own search, as does any query while the engine is down.', category: 'bloodhound')),
                Field::make(t('Synonyms', category: 'bloodhound'), Table::make('synonyms')
                    ->columns([
                        'term' => ['heading' => t('Word', category: 'bloodhound'), 'type' => 'singleline', 'placeholder' => 'sofa'],
                        'synonyms' => ['heading' => t('Means the same as', category: 'bloodhound'), 'type' => 'singleline', 'placeholder' => 'couch, settee'],
                    ])
                    ->allowAdd()->allowDelete()->allowReorder()
                    ->addRowLabel(t('Add a synonym', category: 'bloodhound')))
                    ->instructions(t('Each group works in every direction. Pushed to Meilisearch or Typesense when you save.', category: 'bloodhound')),
                Field::make(t('Stop words', category: 'bloodhound'), Textarea::make('stopWords')->rows(3))
                    ->instructions(t('Words dropped from search queries, separated by commas or new lines. A query made only of stop words is searched as typed.', category: 'bloodhound')),
            ] : []),
        ]);
    }
}
