{{-- Bloodhound harness (Pro): Craft's own ->search() from Blade --}}
@php($query = \CraftCms\Cms\Entry\Elements\Entry::find()->section('articles')->status(request('all') ? null : 'live'))
@php(request('site') ? $query->site(request('site')) : null)
<p id="ids">{{ implode(',', $query->search(request('q'))->orderBy('score desc')->ids()) }}</p>
