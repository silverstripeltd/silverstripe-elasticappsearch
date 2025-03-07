# Migrating to Silverstripe Discoverer Module

This module is no longer recommended by Silverstripe for implementing search queries. Refer to the [main readme](../../README.md) for the explanation but in general is is driven by Elastic's deprecation of App search. We recommend migrating to the [silverstripe-discoverer](https://github.com/silverstripeltd/silverstripe-discoverer/) module. It is fully open source and is designed to be service agnostic which will hopefully make switching search providers easier in the long run. There currently two supported companion modules [silverstripe-discoverer-elastic-enterprise](https://github.com/silverstripeltd/silverstripe-discoverer-elastic-enterprise/) and [silverstripe-discoverer-bifrost](https://github.com/silverstripeltd/silverstripe-discoverer-bifrost/) that provide support for Elastic Enterprise search (née App Search) and Silverstripe Search Service respectively.

## Migration overview

This module is focussed on making search queries to Elastic Enterprise search so the migration only focuses on querying. Indexing can be handled by the existing [silverstripe-search-service](https://github.com/silverstripe/silverstripe-search-service) or our recommended [silverstripe-forager](https://github.com/silverstripe/silverstripe-forager) module. This guide assumes you have indexing set up and working.

### Simple queries

Simple queries work very similarly to the current module. For example a [simple query](./simple-usage.md) could look like:

```PHP
$keywords = "some keyword from the request";
$service = AppSearchService::create();

$query = SearchQuery::create();

$query->setQuery($keywords);

$query->addResultField('title', 'snippet', 20);

$engineName = $service->environmentizeIndex('main');

return $service->search($query, $engineName, $this->getRequest());
```

The [discoverer module's equivalent](https://github.com/silverstripeltd/silverstripe-discoverer/blob/main/docs/simple-usage.md) would be:

```PHP

$keywords = "some keyword from the request";
$service = SearchService::create();
// Instantiate a new Query, and provide the search terms that we wish to search for
$query = Query::create($keywords);

$query->addResultField('title', 20, true);
$query->addResultField('link');

return $service->search($query, 'main');
```

As you can see there are some new names but the principles remain very similar. Text queries, sorting and search or result fields work in a very similar way.

### Filtering

Filtering differs between the modules. In `silverstripe-elasticappsearch` filtering is achieved using a `stdClass` who's structure matches that expected by Elastic's php client [e.g.](https://github.com/silverstripeltd/silverstripe-elasticappsearch/blob/master/docs/en/usage.md#filtering-results):

```PHP
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
```

The discoverer module, by contrast, has dedicated classes for filter concepts [e.g.](https://github.com/silverstripeltd/silverstripe-discoverer/blob/main/docs/detailed-querying.md#filters) and `filter` and `filterAny` methods:

```PHP
use SilverStripe\Discoverer\Query\Filter\Criterion;use SilverStripe\Discoverer\Query\Query;

$query = Query::create();
// Where the author_id is specifically 1
$query->filter('author_id', 1, Criterion::EQUAL);
// Where the category_id is any of 1, 2, or 3
$query->filter('category_id', [1, 2, 3], Criterion::IN);
// Where the category_id is not 1, 2, or 3
$query->filter('category_id', [1, 2, 3], Criterion::NOT_IN);
```

This provides an abstraction to filtering that isn't coupled to the provider's filter implementation. This allows you to change your search provider without updating application code and allows API changes to be handled in provider modules rather than your business logic.

### Result Templates

The `silverstripe-elasticappsearch` module returns its results to the template [as Silverstripe DataObjects](https://github.com/silverstripeltd/silverstripe-elasticappsearch/blob/master/docs/en/simple-usage.md#searchresultsss):

```HTML
<% loop $SearchResults.Results %>
    <%-- Each iteration of this loop will be a DataObject of whatever class has been returned by Elastic (e.g. --%>
    <%-- it's usually a Page object). This is the SilverStripe ORM data object, so you can call anything on it --%>
    <%-- that you would normally call. --%>

    <%-- In addition to what you might normally call, you can also access $ElasticSnippets.field to get access --%>
    <%-- to any snippets (e.g. highlighted text) that you requested of Elastic. For example, using the example --%>
    <%-- code above you could use $ElasticSnippets.title --%>

    <%-- Note the use of $ClickthroughLink here instead of just $Link. This enables analytics tracking in --%>
    <%-- Elastic App Search (see the Configuration section below for more info on this). --%>
    <li><a href="$ClickthroughLink">$Title</a></li>
<% end_loop %>
```

This is convenient for templating but it does have a couple of down sides:
- performance: those DataObjects need to be fetched from the database when returning results.
- opinionated results: sometimes you don't want to use search data for DataObjects

In the [silverstripe-discoverer module](https://github.com/silverstripeltd/silverstripe-discoverer/blob/main/docs/simple-usage.md#searchresultsss), instead results are stored in a Record object that contains data returned from your search provider. This can be much faster than waiting for DataObject lookups and often the data you want to display is stored in the search provider anyway. If, however, you want to keep the original behaviour, you could add a processor to your search results call the [extractResults](https://github.com/silverstripeltd/silverstripe-elasticappsearch/blob/master/src/Service/SearchResult.php#L172) function in this module is a good example of how this can be achieved.

### Multi search

Multi search is not yet supported by the discoverer module. A workaround is to execute multiple searches sequentially. Please open an issue if you rely on this feature.

### Spellcheck

Spellcheck also has a new higher-level abstraction to allow different implementations. Refer the to the [silverstripe-discoverer-search-ui](https://github.com/silverstripeltd/silverstripe-discoverer-search-ui/blob/main/src/Controller/SearchResultsController.php#L132) for an example that uses Silverstripe Search. Elastic Enterprise search is also supported (without needing a second set of credentials).
