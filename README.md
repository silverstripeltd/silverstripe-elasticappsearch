# madmatt/silverstripe-elasticappsearch

This is a module that provides services to connect a [SilverStripe CMS](https://www.silverstripe.org/) powered website to [Elastic App Search](https://www.elastic.co/enterprise-search).

**Note:** This module only provides the query service that allows you to query Elastic App Search and build search result pages. To index content from a SilverStripe CMS powered website, you should include and configure the [silverstripe/silverstripe-search-service](https://github.com/silverstripe/silverstripe-search-service/) module as well.

## Installing
Add the following to your composer.json:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:madmatt/silverstripe-elasticappsearch.git"
        }
    ]
}
```

Then run the following:
```shell script
cd /path/to/your/project-root
composer require madmatt/silverstripe-elasticappsearch
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
    Madmatt\ElasticAppSearch\Query\SearchQuery;

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
    Madmatt\ElasticAppSearch\Query\SearchQuery;

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
    Madmatt\ElasticAppSearch\Query\SearchQuery;

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
    Madmatt\ElasticAppSearch\Query\SearchQuery;

$query = Injector::inst()->get(SearchQuery::class);
$query->addSearchField('content'); // Don't specify a weight for content, the default will be used
$query->addSearchField('title', 10); // Make title the most relevant field
```

### Pagination
By default, this module automatically handles the pagination of results for you, provided you use the built-in `PaginatedList` class (used by default). However, if you want to override this, you can call `$query->setPagination($pageSize, $pageNum)` to configure it directly.

## Simple example of usage
```php
<?php

use Madmatt\ElasticAppSearch\Query\SearchQuery,
    Madmatt\ElasticAppSearch\Service\AppSearchService,
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
