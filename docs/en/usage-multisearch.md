# Advanced usage: The `MultiSearchQuery` class
<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->

<!-- END doctoc generated TOC please keep comment here to allow auto update -->

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
use stdClass;

/*...*/

$service = AppSearchService::create();

$keywordQuery = SearchQuery::create();
$keywordQuery->setQuery('biscuits');

$keywordFilters = new stdClass();
$keywordFilters->all = [];
$keywordFilters->all[] = (object) ['content_type' => ['page']];
$keywordQuery->addRawFilters($keywordFilters);

$contentTypeQuery = SearchQuery::create();
$contentTypeQuery->setQuery('biscuits');
$contentTypeQuery->setPagination(0, 1);

$facets = new stdClass();
$facets->content_type = [];
$facets->content_type[] = (object) [
    'type' => 'value',
];

$contentTypeQuery->addRawFacets($facets);

$multisearchQuery = MultiSearchQuery::create();
$multisearchQuery->addQuery($keywordQuery);
$multisearchQuery->addQuery($contentTypeQuery);

// Could equally do:
// $multisearchQuery->setQueries([$keywordQuery, $contentTypeQuery]);

$engineName = $service->environmentizeIndex('content');

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
