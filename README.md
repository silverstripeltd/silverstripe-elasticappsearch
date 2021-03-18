# silverstripeltd/silverstripe-elasticappsearch

This is a module that provides services to connect a [SilverStripe CMS](https://www.silverstripe.org/) powered website to [Elastic App Search](https://www.elastic.co/enterprise-search).

**Note:** This module only provides the query service that allows you to query Elastic App Search and build search result pages. To index content from a SilverStripe CMS powered website, you should include and configure the [silverstripe/silverstripe-search-service](https://github.com/silverstripe/silverstripe-search-service/) module as well.

## Installing

Add the following to your composer.json:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:silverstripeltd/silverstripe-elasticappsearch.git"
        }
    ]
}
```

You will need to add the following environment variables:
* `APP_SEARCH_ENDPOINT`: This should already be defined if you are using the [silverstripe/silverstripe-search-service](https://github.com/silverstripe/silverstripe-search-service/) module. This is the API endpoint for your Elastic App Search interface.
* `APP_SEARCH_API_SEARCH_KEY`: This is the public search API key that you've configured in the Credentials section of Elastic App Search. It should begin with `search-`.

Then run the following:
```shell script
cd /path/to/your/project-root
composer require silverstripeltd/silverstripe-elasticappsearch
vendor/bin/sake dev/build flush=1
``` 
You may also need to visit `https://your-website.example.com/?flush=1` if you have separate cache sources for cli and web users.

## Using
Out of the box, this module does nothing. For now, you need to build your own search results page. You'll do most of your interaction with the module via the `AppSearchService` and `SearchQuery` classes.

See the end of this README for what a simple search results controller and matching template might look like.

## The `SearchQuery` class
`SearchQuery` is where you configure the search query itself that you want to submit to Elastic App Search. Most of what you can do with [Elastic App Search itself](https://github.com/elastic/app-search-php#search) can be done through this class - if anything is missing please raise an issue or contribute the addition via a pull request.

### The keyword
Specify the keyword (or words) you want to search for using `$query->setQuery('search keywords')`. Subsequent calls to this method will override older values.

### Filtering results
You can specify raw App Search filters through to `SearchQuery` using the `addRawFilters()` method. For now, these should be passed in the exact way that the [elastic/app-search-php module expects](https://swiftype.com/documentation/app-search/api/search/filters). An example of this is below:

```php
use SilverStripe\Core\Injector\Injector,
    SilverStripe\ElasticAppSearch\Query\SearchQuery;

$filters = new stdClass;

// Filters that all records must have to be included in the results
$filters->all = [];
$filters->all[] = (object)['content_type_id' => 12345];

// 'OR' conditionals - only one of each has to match to include the document in the results
$filters->any = [];
$filters->any[] = (object)['title' => 'Text string'];
$filters->any[] = (object)['content' => 'Text string'];

// Ensure that no document that matches this filter is included in the results
$filters->none = [];
$filters->none[] = (object)['title' => 'Text string'];

// Apply the filters:
$query = Injector::inst()->get(SearchQuery::class);
$query->addRawFilters($filters);

// Lots more examples are available here: https://swiftype.com/documentation/app-search/api/search/filters
```

### Add Facets to your results
You can return metadata related to your results by adding raw App Search facets to `SearchQuery` via the `addRawFacets()` method. Like [filters](#filtering-results), these should be passed in as a `stdClass` in the format that the [elastic/app-search-php module expects](https://swiftype.com/documentation/app-search/api/search/facets). An example below:

```php
use SilverStripe\Core\Injector\Injector,
    SilverStripe\ElasticAppSearch\Query\SearchQuery;

$facets = new stdClass();

// what property you would like to return information on
$facets->taxonomy_terms = [];

$facets->taxonomy_terms[] = (object) [
    'type' => 'value', // this will essentially return a count
    'name' => 'topics', // [Optional] a name for this facet, in case you want to return multiple facets on the same property
    'sort' => (object) ['count' => 'desc'], // [Optional] can also be sorted by value (say, alphabetically)
    'size' => 250, // [Optional] the maximum amount returned, max 250, default 10
];

// Apply the facets:
$query = Injector::inst()->get(SearchQuery::class);
$query->addRawFacets($facets);
```

You can access these values via the `->getFacets()` method on `SearchResult`, it will return it in an `ArrayList`, or to get one particular facet, you can use the `->getFacet()` method. In this case , `->getFacet('taxonomy_terms', 'topics')`.

*Note: if you don't use the name parameter when creating the query, it will be returned as index `0` - you can access this by omitting the second parameter to `->getFacet()` i.e. `->getFacet('taxonomy_terms')`*

You can also use these values in templates:

```html
<% loop $SearchResults.Facets %>
    <h1>$Name</h1>
    <h4>$Property</h4>
    <% loop $Data %>
        <p>$Value was returned $Count times</p>
    <% end_loop %>
<% end_loop %>
```

### Adding result fields
[Result Fields](https://swiftype.com/documentation/app-search/api/search/result-fields) are convenient ways to ask Elastic to return contextual information on why a document was returned in search results. This is often known as 'context highlighting' or 'excerpt content'. For example, a search for `test` would return the following result field for the page title if requested: `This is a <em>test</em> page`.

```php
use SilverStripe\Core\Injector\Injector,
    SilverStripe\ElasticAppSearch\Query\SearchQuery;

// Request an HTML snippet of an indexed field, with an optional maximum character length (default 100, range between 20 and 1000 characters)
$query = Injector::inst()->get(SearchQuery::class);
$query->addResultField('title', 'snippet');
$query->addResultField('content', 'snippet', 500);
```

### Search fields
By default, Elastic will search all text fields on every document in the engine that you're querying. This _can_ be slow, especially if you index a lot of fields. It can also return unwanted results (e.g. searching for a common keyword in all text fields might return results that you're not expecting).

You can use this feature to cut down on the fields that you want to search. This only works for fields in the schema that you designate as `text`.

You can optionally specify a weighting to give the text field you want to search. The higher the weighting, the more important the field is (relative to other specified fields). Weights should be between 1 (least relevant) and 10 (most relevant). If no weights are specified, then the engine-level weights (specified in the Elastic App Search console) are used.

```php
use SilverStripe\Core\Injector\Injector,
    SilverStripe\ElasticAppSearch\Query\SearchQuery;

$query = Injector::inst()->get(SearchQuery::class);
$query->addSearchField('content'); // Don't specify a weight for content, the default will be used
$query->addSearchField('title', 10); // Make title the most relevant field
```

### Sorting Results

The default sort is `_score`, which is essentially relevance. You can
add one or more sorts to help organise your results via the `SearchQuery` class:

```php
$query = Injector::inst()->get(SearchQuery::class);
// For a single sort, just pass the field and direction to addSort()
$query->addSort('publication_date', 'asc');

// For multiple sorts, pass an array to addSorts()
$query->addSorts([
    'publication_date' => 'desc',
    'title' => 'asc',
]);
```

If you add any sort at all, a final sort of `'_score' => 'desc'` is
appended to the end as the final tiebreaker.

*Note: You cannot sort on a Geolocation field*

See [the Elastic documentation](https://www.elastic.co/guide/en/app-search/current/sort.html) for more details.



### Pagination
By default, this module automatically handles the pagination of results for you, provided you use the built-in `PaginatedList` class (used by default). However, if you want to override this, you can call `$query->setPagination($pageSize, $pageNum)` to configure it directly.

## Spelling suggestions
If you want spellcheck (e.g. "Did you mean"), then you'll need to make some changes. Firstly, by default Elastic App Search includes 'typo tolerance' which automatically provides some level of spelling suggestion, however instead of suggesting alternate spelling, it just includes many different words as part of the query. For example, a search for `chanel` might return results for `channel` and `panel` as well. This can be helpful in some circumstances, but also it can easily include many irrelevant results when users accidentally mis-spell a word.

We recommend that you only run a spelling suggestion when you get zero results for a specific query (as it's impossible to tell the difference between helpful and unhelpful results when typo tolerance is enabled).

To do this, you can simply use the following YML fragment. If you don't do this, you are more likely to get _some_ results returned, which means your spelling suggestions will be less useful.

```yml
SilverStripe\ElasticAppSearch\Query\SearchQuery:
    enable_typo_tolerance: false
```

Next, we want to enable querying of the underlying Elasticsearch datastore whenever no search results are found. This can be done automatically when calling `AppSearchService->query()`, or it can be done manually in your own code. Either way, we first require three environment variables to be configured:
```dotenv
# The Cloud ID from the Elastic Cloud console. This should start with the name of the deployment, then a colon (:), then a base64-encoded string.
# Example: test-deployment:c29tZS11cmwtdG8tZWxhc3RpY3NlYXJjaC5leGFtcGxlLmNvbSRyYW5kb20tdXVpZC1zdHJpbmctMSRyYW5kb20tdXVpZC1zdHJpbmctMg==
ELASTICSEARCH_CLOUD_ID=""

# The API key ID, returned when generating the API key via the Elasticsearch CLI
ELASTICSEARCH_API_KEY_ID=""

# The API key, returned when generating the API key via the Elasticsearch CLI
ELASTICSEARCH_API_KEY=""
```

Then, you will need to use composer to install the `elastic/elasticsearch-php` library. If this is not installed, spellchecking won't occur (but no errors should be thrown).

```shell
# Confirm the version of Elastic Enterprise Search being used before running this - the verions of all libraries run in lock-step
composer require "elasticsearch/elasticsearch:^7.11"
```

Next, you can enable automatic spellcheck when zero results are returned. Alongside enabling it, you need to specify which fields you want to request suggestions from for each internal Elasticsearch index. Index and field names need to be in the internal Elasticsearch format (typically `.ent-search-engine-random-sha` for the index name and `field_name$string` for example for a `text` field in Elastic App Search):

```yml
SilverStripe\ElasticAppSearch\Service\AppSearchService:
    # Enable spellchecking by default when zero results are returned by Elastic App Search.
    enable_spellcheck_on_zero_results: true
    
    # Sets the index variant value. If using the silverstripe-search-service module, this must be the same as what you use for that (e.g. `APP_SEARCH_ENGINE_PREFIX`)
    index_variant: '`SS_ENVIRONMENT_TYPE`'

SilverStripe\ElasticAppSearch\Service\SpellcheckService:
    # Set the maximum number of spelling suggestions to return. Elasticsearch may return less than this, but if it returns more than only the top N suggestions will be provided to the SearchResult.
    max_spellcheck_suggestions: 2
    
    # Provide the GET query param that needs to be changed when creating links to other spelling suggestions
    query_param: q
    
    # The engine/index and field map for each Elastic App Search you want to provide spellchecking for, where:
    # - engine_name is the name of the engine (don't include the environment variable part - e.g. just use "content" if your engine name is actually "dev-content"
    # - internal_index_name is the internal name of the index within Elasticsearch. This typically starts with .ent-search-engine- and then a random SHA.
    # - fields is an array of internal Elasticsearch fields that should be used to get spelling suggestions from. These can only be taken from text (aka string) fields, and follow the Elastic App Search naming format (e.g. a field in your IndexConfiguration called 'Title' will have a field called 'Title$string' internally within Elasticsearch).
    spellcheck_suggestion_fields:
        engine_name:
            internal_index_name: '.ent-search-engine-random-sha'
            fields:
                - "title$string"
                - "content$string"
```

Finally, you just need to update your template to include the new spelling suggestions if there are zero results.

```html
<% if not $SearchResults.Results %>
    <p>Sorry, we didn't find any search results for your query "$SearchResults.Query".</p>
    <% if $SearchResults.SpellingSuggestions %>
        <p>
            Did you mean:
            <% loop $SearchResults.SpellingSuggestions %>
                <a href="$Link" title="Search for '$Suggestion' instead">$Suggestion</a><% if $Last %>?<% else %>, <% end_if %>
            <% end_loop %>
        </p>
    <% end_if %>
<% end_if %>
```

Alternatively, if you want to perform your own spellchecking or manipulate the results in a different way, you can query `AppSearchService->getSpellingSuggestions()` directly, passing in a `SearchQuery` object.

## Simple example of usage

```php
<?php

use SilverStripe\ElasticAppSearch\Query\SearchQuery,
    SilverStripe\ElasticAppSearch\Service\AppSearchService,
    SilverStripe\Core\Convert,
    SilverStripe\Core\Injector\Injector,
    SilverStripe\Forms\Form,
    SilverStripe\Forms\FieldList,
    SilverStripe\Forms\TextField,
    SilverStripe\Forms\FormAction,
    SilverStripe\SearchService\Services\AppSearch\AppSearchService as SilverStripeAppSearchService;

class SearchResultsController extends PageController
{
    private static $allowed_actions = [
        'SearchForm'
    ];

    public function SearchForm()
    {
        return Form::create(
            $this,
            __FUNCTION__,
            FieldList::create(
                TextField::create('q')
            ),
            FieldList::create(
                FormAction::create('results', 'Search')
            )
        )->disableSecurityToken();
    }

    public function SearchResults($data, Form $form)
    {
        $keywords = Convert::raw2xml($data['q']);

        /** @var AppSearchService $service */
        $service = Injector::inst()->get(AppSearchService::class);
        
        /** @var SearchQuery $query */
        $query = Injector::inst()->get(SearchQuery::class);
        $query->setQuery($keywords);
        $query->addResultField('title', 'snippet', 20); // Assumes you have a 'title' field in your schema - see below
        
        // This is the Elastic App Search engine name, this could just be a simple string - but here we use the
        // silverstripe-search-service code to ensure our engine name always matches the YML configuration used in that
        // module.
        $engineName = SilverStripeAppSearchService::environmentizeIndex('content');
        
        try {
            return $service->search($query, $engineName, $this->getRequest());        
        } catch (Exception $e) {
            return null;
        }
    }
}
```

The corresponding SearchResultsController template might look like this:
```html
<div class="search-form">$SearchForm</div>

<% if $SearchResults %>
    <% with $SearchResults %>
        <p class="_summary">Displaying $Results.FirstItem - $Results.LastItem results of $Results.TotalItems for "$Query"</p>
    <% end_with %>

    <ul>
    <% loop $SearchResults.Results %>
        <%-- Each iteration of this loop will be a DataObject of whatever class has been returned by Elastic (e.g. --%>
        <%-- it's usually a Page object). This is the SilverStripe ORM data object, so you can call anything on it --%>
        <%-- that you would normally call. --%>

        <%-- In addition to what you might normally call, you can also access $ElasticSnippets.field to get access --%>
        <%-- to any snippets (e.g. highlighted text) that you requested of Elastic. For example, using the example --%>
        <%-- code above you could use $ElasticSnippets.title --%>
        <li><a href="$Link">$Title</a></li>
    <% end_loop %>
    </ul>

    <% with $SearchResults.Results %>
    <% if $MoreThanOnePage %>
    <ul class="pagination">
        <li class="pagination-item pagination-item--prev">
            <% if $NotFirstPage %>
                <a title="View previous page of results" class="pagination-prev-link" href="$PrevLink">&laquo;</a>
            <% else %>
                <span title="View previous page of results" class="pagination-prev-link pagination-prev-link--disabled">&laquo;</span>
            <% end_if %>
        </li>
        <% loop $PaginationSummary(4) %>
            <% if $CurrentBool %>
                <li class="pagination-item pagination-item--active">
                    <a title="Viewing page $PageNum of results" class="pagination-link pagination-link--disabled">$PageNum</a>
                </li>
            <% else %>
                <% if $Link %>
                    <li class="pagination-item">
                        <a title="View page $PageNum of results" class="pagination-link" href="$Link">$PageNum</a>
                    </li>
                <% else %>
                    <li class="pagination-item">
                        <a class="pagination-link pagination-link--disabled">&hellip;</a>
                    </li>
                <% end_if %>
            <% end_if %>
        <% end_loop %>
        <li class="pagination-item pagination-item--next">
            <% if $NotLastPage %>
                <a title="View next page of results" class="pagination-next-link" href="$NextLink">&raquo;</a>
            <% else %>
                <span title="View next page of results" class="pagination-next-link pagination-next-link--disabled">&raquo;</span>
            <% end_if %>
        </li>
    </ul>
    <% end_if %>
    <% end_with %>
<% else %>
    <p class="error">Sorry, there was an error or you didn't enter a keyword.</p>
<% end_if %>
```

## The `MultiSearchQuery` class

Elastic allows you to do multiple queries at once - up to a maximum of ten. The `MultiSearchQuery` class
is a simple bundler that holds many `SearchQuery`s. To use it, simply build each individual `SearchQuery`
[as you would normally](#the-searchquery-class), then you can simply call `->addQuery()` or
`->setQueries()` to load up the `MultiSearchQuery`.

Then, instead of calling `->search()` from your engine, call `->multisearch()`. This will return a
`MultiSearchResult` instead of a `SearchResult` - and the concept is very similar. It is a bundler for
multiple `SearchResult` instances, based on the initial queries.

**NOTE - Order is important. Multi-search queries don't contain a `request_id`, so the order they are
attached in is the order they will return in, and there's no other way to connect which result goes with
which initial query**

In this example, I'll use one `SearchQuery` to return results based on a keyword, with filters applied,
and one `SearchQuery` to return facets on an unfiltered query, based on the same keyword. Here, I want
to only search for content type "page", but want to return a list of all content types that return a
result for the keyword. In this way, I can link to the "file" section if I detect there are relevant
results there.

```php
use SilverStripe\ElasticAppSearch\Query\MultiSearchQuery;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\ElasticAppSearch\Service\AppSearchService;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\SearchService\Services\AppSearch\AppSearchService as SilverStripeAppSearchService;
use stdClass;

/*...*/

/** @var AppSearchService $service */
$service = Injector::inst()->get(AppSearchService::class);

/** @var SearchQuery $keywordQuery */
$keywordQuery = Injector::inst()->create(SearchQuery::class);
$keywordQuery->setQuery('biscuits');

$keywordFilters = new stdClass();
$keywordFilters->all = [];
$keywordFilters->all[] = (object) ['content_type' => ['page']];
$keywordQuery->addRawFilters($keywordFilters);

/** @var SearchQuery $contentTypeQuery */
$contentTypeQuery = Injector::inst()->create(SearchQuery::class);
$contentTypeQuery->setQuery('biscuits');
$contentTypeQuery->setPagination(0, 1);

$facets = new stdClass();
$facets->content_type = [];
$facets->content_type[] = (object) [
    'type' => 'value',
];

$contentTypeQuery->addRawFacets($facets);

/** @var MultiSearchQuery $multisearchQuery */
$multisearchQuery = Injector::inst()->create(MultiSearchQuery::class);
$multisearchQuery->addQuery($keywordQuery);
$multisearchQuery->addQuery($contentTypeQuery);

// Could equally do:
// $multisearchQuery->setQueries([$keywordQuery, $contentTypeQuery]);

$engineName = SilverStripeAppSearchService::environmentizeIndex('content');

$keywordResults = null;
$contentTypeResults = null;

try {
    [$keywordResults, $contentTypeResults] = $service->multisearch(
        $multisearchQuery,
        $engineName,
        $this->getRequest()
    )->getResults();
} catch (Exception $e) {
    Injector::inst()->get(LoggerInterface::class)->error(
        'Error calling ElasticSearch Service',
        ['exception' => $e]
    );
}
```

Each of `$keywordResults` and `$contentTypeResults` will be a `SearchResult` that you can use as you
would the result of a standard single search.

As always, [check out the Elastic documentation](https://swiftype.com/documentation/app-search/api/search#multi)
for more details.
