{{-- Bloodhound harness (Pro): Craft's own ->search() from Blade --}}
<p id="ids">{{ implode(',', \CraftCms\Cms\Entry\Elements\Entry::find()->section('articles')->status(request('all') ? null : 'live')->search(request('q'))->orderBy('score desc')->ids()) }}</p>
