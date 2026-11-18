{{-- Bloodhound harness: any index (?index=), optional filter (?key=&value=), from Blade --}}
@php($results = $bloodhound->search(request('index'), request('q'), ['perPage' => 50, 'site' => request('site') ?: null, 'where' => request('key') ? [request('key') => request('value')] : []]))
<p id="total">{{ $results->total }}</p>
<p id="error">{{ $results->error ?? '' }}</p>
<ol id="hits">@foreach ($results as $el)<li data-id="{{ $el->id }}">{{ $el }}</li>@endforeach</ol>
