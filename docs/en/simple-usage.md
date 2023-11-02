# Simple usage example
<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->
**Table of Contents**

  - [SearchResultsController](#searchresultscontroller)
  - [SearchResultsController.ss](#searchresultscontrollerss)
- [Next steps](#next-steps)

<!-- END doctoc generated TOC please keep comment here to allow auto update -->

**Note:** This is not meant to be a feature-packed demo - just provide you the basics to get started. Once you've implemented this, check out [usage.md](usage.md) to see other features that can be enabled.

This module does not provide a search results page out of the box. You an hook it up to your existing results page, or create a new one. A minimal example of a `SearchResultsController` is below, along with the Silverstripe template that might go alongside it.

## `SearchResults.php`

```php
<?php

namespace App\Pages;

use Page;

class SearchResults extends Page
{

    private static string $table_name = 'SearchResults';

    private static string $icon_class = 'font-icon-p-search';

    private static string $singular_name = 'Search results page';

    private static string $plural_name = 'Search results pages';

    private static string $description = 'Display search results from Elastic search';

}

```

## `SearchResultsController.php`

```php
<?php

namespace App\Pages;

use PageController;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\ElasticAppSearch\Service\AppSearchService;
use SilverStripe\ElasticAppSearch\Service\SearchResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\TextField;
use Throwable;

class SearchResultsController extends PageController
{

    private static array $allowed_actions = [
        'SearchForm',
    ];

    public function SearchForm(): Form
    {
        // The keyword that we want to search
        $keywords = Convert::raw2xml($this->getRequest()->getVar('q'));

        return Form::create(
            $this,
            __FUNCTION__,
            FieldList::create(
                TextField::create('q', 'Terms', $keywords)
            ),
            FieldList::create(
                FormAction::create('search', 'Search')
            )
        )
            ->setFormAction($this->dataRecord->Link())
            ->setFormMethod('GET')
            ->disableSecurityToken();
    }

    /**
     * Given URL parameters, create a SearchQuery object and pass it to Elastic App Search, then return the results (if
     * any) to the template for parsing.
     */
    public function SearchResults(): ?SearchResult
    {
        // The keywords that we want to search
        $keywords = Convert::raw2xml($this->getRequest()->getVar('q'));

        if (!$keywords) {
            return null; // No results unless we search for something
        }

        try {
            $service = AppSearchService::create();

            $query = SearchQuery::create();

            $query->setQuery($keywords);
            // Assumes you have a 'title' field in your schema - see below
            $query->addResultField('title', 'snippet', 20);

            // Note: The second value here *must* match the name of the engine (see elasticappsearch.yml)
            // We combine the ENTERPRISE_SEARCH_ENGINE_PREFIX env var with the name of the engine set in YML to get
            // the full name of the engine to search on.
            $engineName = $service->environmentizeIndex('main');

            return $service->search($query, $engineName, $this->getRequest());
        } catch (Throwable $e) {
            // Log the error without breaking the page
            Injector::inst()->get(LoggerInterface::class)
                ->error(sprintf('Elastic error: %s', $e->getMessage()), ['elastic' => $e]);
        }

        return null;
    }

}
```

## `SearchResults.ss`

The corresponding SearchResults template might look like this:

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

        <%-- Note the use of $ClickthroughLink here instead of just $Link. This enables analytics tracking in --%>
        <%-- Elastic App Search (see the Configuration section below for more info on this). --%>
        <li><a href="$ClickthroughLink">$Title</a></li>
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

# Next steps
* [View usage.md for how to use other features](usage.md)
