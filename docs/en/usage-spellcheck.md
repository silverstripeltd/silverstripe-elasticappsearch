# Advanced usage: Spelling suggestions
<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->

<!-- END doctoc generated TOC please keep comment here to allow auto update -->
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
