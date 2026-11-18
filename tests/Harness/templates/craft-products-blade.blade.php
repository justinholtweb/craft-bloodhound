{{-- Bloodhound harness (Pro): Commerce's product ->search() from Blade --}}
<p id="ids">{{ implode(',', \CraftCms\Commerce\Product\Elements\Product::find()->type('gear')->status(request('all') ? null : 'live')->search(request('q'))->orderBy('score desc')->ids()) }}</p>
