<?php

namespace SilverStripe\ElasticAppSearch\Service;

use Elasticsearch\ClientBuilder;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Environment;
use SilverStripe\ElasticAppSearch\Gateway\AppSearchGateway;
use SilverStripe\ElasticAppSearch\Gateway\ElasticsearchGateway;
use SilverStripe\ElasticAppSearch\Query\MultiSearchQuery;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\ArrayList;

class AppSearchService
{
    use Injectable;
    use Extensible;
    use Configurable;

    /**
     * @var string The _GET var to use for the PaginatedList when paging through search results. Defaults to 'start',
     * same as PaginatedList itself.
     * @config
     */
    private static $pagination_getvar = 'start';

    /**
     * @var int The number of search results to return per page. Can be overridden by calling setPagination() on the
     * SearchQuery object passed in to the search method.
     * @config
     */
    private static $pagination_size = 10;

    /**
     * @var bool Enable spellchecking of keywords when zero results are returned. Used in conjunction with YML config
     * flag "SearchQuery.enable_typo_tolerance = false". See the 'Spelling suggestions' section of the README for more
     * details.
     */
    private static $enable_spellcheck_on_zero_results = false;

    private static $dependencies = [
        'gateway' => '%$' . AppSearchGateway::class,
        'spellcheckService' => '%$' . SpellcheckService::class,
        'logger' => '%$' . LoggerInterface::class . '.errorhandler',
    ];

    /**
     * @var AppSearchGateway The gateway to be used when querying Elastic App Search
     */
    public $gateway;

    /**
     * @var LoggerInterface The monolog interface used to handle errors and warnings during searching
     */
    public $logger;

    /**
     * @param SearchQuery $query
     * @param string $engineName
     * @param HTTPRequest $request
     * @throws Exception If the connection to Elastic is not available, index not configured or any other error
     * @return SearchResult|null
     */
    public function search(SearchQuery $query, string $engineName, HTTPRequest $request): ?SearchResult
    {
        $cfg = $this->config();

        // Ensure we take pagination into account within the query. Allows overriding of pagination directly if required
        // by simply calling setPagination on the SearchQuery object prior to calling this method.
        if (!$query->hasPagination()) {
            $pageNum = $request->getVar($cfg->pagination_getvar) / $cfg->pagination_size ?? 0;
            $pageNum++; // We do this because PaginatedList uses a zero-based index and App Search is one-based
            $query->setPagination($cfg->pagination_size, $pageNum);
        }

        $response = $this->gateway->search($engineName, $query->getQueryForElastic(), $query->getSearchParamsAsArray());

        // Allow extensions to manipulate the results array before any validation or processing occurs
        // WARNING: This extension hook is fired before the response is checked for validity, it may not be a valid
        // response from Elastic App Search. Use caution when modifying the array or assuming any structure exists.
        $this->extend('augmentSearchResultsPreValidation', $response);

        // Create the new SearchResult object in which to store results, including validating the response
        $result = SearchResult::create($query->getQuery(), $response);

        // If we want to inject spelling suggestions, do so
        if ($result->getResults()->TotalItems() == 0 && $this->config()->enable_spellcheck_on_zero_results) {
            $suggestions = $this->spellcheckService->getSpellingSuggestions($query, $engineName, $request);
            $result->setSpellingSuggestions($suggestions);
        }

        return $result;
    }

    /**
     * @param MultiSearchQuery $query
     * @param string $engineName
     * @param HTTPRequest $request
     * @throws Exception If the connection to Elastic is not available, index not configured or any other error
     * @return MultiSearchResult|null
     */
    public function multisearch(MultiSearchQuery $query, string $engineName, HTTPRequest $request): ?MultiSearchResult
    {
        $cfg = $this->config();
        $queries = $query->getQueries();

        foreach ($queries as $singleQuery) {
            if (!$singleQuery->hasPagination()) {
                $pageNum = $request->getVar($cfg->pagination_getvar) / $cfg->pagination_size ?? 0;
                $pageNum++; // We do this because PaginatedList uses a zero-based index and App Search is one-based
                $singleQuery->setPagination($cfg->pagination_size, $pageNum);
            }
        }

        $response = $this->gateway->multisearch($engineName, $query->renderQueries());

        // Allow extensions to manipulate the results array before any validation or processing occurs
        // WARNING: This extension hook is fired before the response is checked for validity, it may not be a valid
        // response from Elastic App Search. Use caution when modifying the array or assuming any structure exists.
        $this->extend('augmentMultiSearchResultsPreValidation', $response);

        // Create the new MultiSearchResult object in which to store results, including validating the response
        return MultiSearchResult::create($query, $response);
    }

    /**
     * Duplicate of AppSearchService::environmentizeIndex() in silverstripe/silverstripe-search-service to avoid
     * coupling the two modules together.
     * 
     * @param string $indexName The untouched index name. If a variant exists, it will be added to this.
     * @return string
     */
    public function environmentizeIndex(string $indexName): string
    {
        $variant = self::config()->index_variant;

        // If $variant starts and ends with a backtick, it's an envvar that needs evaluation
        if (preg_match('/^`(?<name>[^`]+)`$/', $variant, $matches)) {
            $envValue = Environment::getEnv($matches['name']);
            
            if ($envValue !== false) {
                $variant = $envValue;
            } elseif (defined($matches['name'])) {
                $variant = constant($matches['name']);
            } else {
                $variant = null;
            }
        }
        
        if ($variant) {
            return sprintf("%s-%s", $variant, $indexName);
        }

        return $indexName;
    }
}
