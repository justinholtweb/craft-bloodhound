{{-- Bloodhound harness: Blade search page --}}
@php($results = $bloodhound->search('articles', request('q'), ['page' => request('page', 1), 'perPage' => 2, 'where' => request('section') ? ['section' => request('section')] : []]))
<p id="total">{{ $results->total }}</p>
<p id="pages">{{ $results->pages() }}</p>
<p id="error">{{ $results->error ?? '' }}</p>
<ol id="hits">@foreach ($results as $entry)<li data-id="{{ $entry->id }}">{{ $entry->title }}</li>@endforeach</ol>
