<?php

namespace Madmatt\ElasticAppSearch\Service;

use Exception;
use Madmatt\ElasticAppSearch\Gateway\AppSearchGateway;
use Madmatt\ElasticAppSearch\Query\MultiSearchQuery;
use Madmatt\ElasticAppSearch\Query\SearchQuery;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;

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



    private static $dependencies = [
        'gateway' => '%$' . AppSearchGateway::class,
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

        $response = $this->gateway->search($engineName, $query->getQuery(), $query->getSearchParamsAsArray());

        // Allow extensions to manipulate the results array before any validation or processing occurs
        // WARNING: This extension hook is fired before the response is checked for validity, it may not be a valid
        // response from Elastic App Search. Use caution when modifying the array or assuming any structure exists.
        $this->extend('augmentSearchResultsPreValidation', $response);

        // Create the new SearchResult object in which to store results, including validating the response
        return SearchResult::create($query->getQuery(), $response);
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
}
