{{-- Bloodhound harness (Pro): Craft's own user ->search() from Blade --}}
<p id="ids">{{ implode(',', \CraftCms\Cms\User\Elements\User::find()->group('staff')->status(request('all') ? null : 'active')->search(request('q'))->orderBy('score desc')->ids()) }}</p>
