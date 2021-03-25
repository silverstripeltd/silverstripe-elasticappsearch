# Simple usage example
<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->
**Table of Contents**

  - [SearchResultsController](#searchresultscontroller)
  - [SearchResultsController.ss](#searchresultscontrollerss)
- [Next steps](#next-steps)

<!-- END doctoc generated TOC please keep comment here to allow auto update -->

**Note:** This is not meant to be a feature-packed demo - just provide you the basics to get started. Once you've implemented this, check out [configuration.md](configuration.md) to see other features that can be enabled.

This module does not provide a search results page out of the box. You an hook it up to your existing results page, or create a new one. A minimal example of a `SearchResultsController` is below, along with the Silverstripe template that might go alongside it.

## SearchResultsController
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

## SearchResultsController.ss

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
* [View configuration.md for additional configuration options](configuration.md)
