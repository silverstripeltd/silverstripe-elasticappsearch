<?php

namespace SilverStripe\ElasticAppSearch\Service;

use Elastic\EnterpriseSearch\AppSearch\Schema\MultiSearchData;
use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ElasticAppSearch\Gateway\AppSearchGateway;
use SilverStripe\ElasticAppSearch\Query\MultiSearchQuery;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\ORM\ArrayList;

class AppSearchService
{
    use Injectable;
    use Extensible;
    use Configurable;

    /**
     * The gateway to be used when querying Elastic App Search.
     */
    public ?AppSearchGateway $gateway = null;

    /**
     * The service class used to retrieve spelling suggestions (if enabled).
     */
    public ?SpellcheckService $spellcheckService = null;

    /**
     * The monolog interface used to handle errors and warnings during searching.
     */
    public ?LoggerInterface $logger = null;

    /**
     * @var string The _GET var to use for the PaginatedList when paging through search results. Defaults to 'start',
     *             same as PaginatedList itself.
     *
     * @config
     */
    private static string $pagination_getvar = 'start';

    /**
     * @var int The number of search results to return per page. Can be overridden by calling setPagination() on the
     *          SearchQuery object passed in to the search method.
     *
     * @config
     */
    private static int $pagination_size = 10;

    /**
     * @var bool Enable spellchecking of keywords when zero results are returned. Used in conjunction with YML config
     *           flag "SearchQuery.enable_typo_tolerance = false". See the 'Spelling suggestions' section of the README for more
     *           details.
     */
    private static bool $enable_spellcheck_on_zero_results = false;

    /**
     * These values are used for analytics clickthrough tracking, where we have to pass some data through the user's browser, so we want to disambiguate the classname from the 'type'.
     *
     * @var array Map between FQCN and a human-readable 'type' (e.g. SilverStripe\CMS\Model\SiteTree -> page).
     *
     * @see _config/analytics.yml
     */
    private static array $classname_to_type_mapping = [];

    private static array $dependencies = [
        'gateway' => '%$' . AppSearchGateway::class,
        'spellcheckService' => '%$' . SpellcheckService::class,
        'logger' => '%$' . LoggerInterface::class . '.errorhandler',
    ];

    /**
     * @throws Exception If the connection to Elastic is not available, index not configured or any other error
     */
    public function search(SearchQuery $query, string $engineName, HTTPRequest $request): ?SearchResult
    {
        $cfg = $this->config();

        // Ensure we take pagination into account within the query. Allows overriding of pagination directly if required
        // by simply calling setPagination on the SearchQuery object prior to calling this method.
        if (!$query->hasPagination()) {
            $pageNum = (int)$request->getVar($cfg->pagination_getvar) / (int)$cfg->pagination_size ?? 0;
            ++$pageNum; // We do this because PaginatedList uses a zero-based index and App Search is one-based
            $query->setPagination((int)$cfg->pagination_size, $pageNum);
        }

        $response = $this->gateway->search($engineName, $query->getSearchParams())->asArray();

        // Allow extensions to manipulate the results array before any validation or processing occurs
        // WARNING: This extension hook is fired before the response is checked for validity, it may not be a valid
        // response from Elastic App Search. Use caution when modifying the array or assuming any structure exists.
        $this->extend('augmentSearchResultsPreValidation', $response);

        // Create the new SearchResult object in which to store results, including validating the response
        $result = SearchResult::create($query->getQuery(), $response);
        $result->setEngineName($engineName);

        // If we want to inject spelling suggestions, do so
        if ($result->getResults()->TotalItems() == 0 && $this->config()->enable_spellcheck_on_zero_results) {
            $suggestions = $this->spellcheckService->getSpellingSuggestions($query, $engineName, $request);

            if ($suggestions instanceof ArrayList) {
                $result->setSpellingSuggestions($suggestions);
            }
        }

        return $result;
    }

    /**
     * @throws Exception If the connection to Elastic is not available, index not configured or any other error
     */
    public function multisearch(MultiSearchQuery $query, string $engineName, HTTPRequest $request): ?MultiSearchResult
    {
        $cfg = $this->config();
        $queries = $query->getQueries();

        foreach ($queries as $singleQuery) {
            if (!$singleQuery->hasPagination()) {
                $pageNum = $request->getVar($cfg->pagination_getvar) / $cfg->pagination_size ?? 0;
                ++$pageNum; // We do this because PaginatedList uses a zero-based index and App Search is one-based
                $singleQuery->setPagination($cfg->pagination_size, $pageNum);
            }
        }

        $response = $this->gateway->multisearch($engineName, new MultiSearchData($query->getSearchParams()))->asArray();

        // Allow extensions to manipulate the results array before any validation or processing occurs
        // WARNING: This extension hook is fired before the response is checked for validity, it may not be a valid
        // response from Elastic App Search. Use caution when modifying the array or assuming any structure exists.
        $this->extend('augmentMultiSearchResultsPreValidation', $response);

        // Create the new MultiSearchResult object in which to store results, including validating the response
        $result = MultiSearchResult::create($query, $response);
        $result->setEngineName($engineName);

        return $result;
    }

    /**
     * @param string $className The FQCN to attempt to map to a human-readable name (e.g. SilverStripe\Security\Member)
     *
     * @return string The human-readable name, or the original class name if a suitable map can't be found
     */
    public function classToType(string $className): string
    {
        $map = $this->config()->classname_to_type_mapping;

        if (!array_key_exists($className, $map)) {
            return $className;
        }

        return $map[$className];
    }

    /**
     * Opposite of @param string $typeOrClass The short human-readable.
     *
     * @see classToType() above. Takes a type (e.g. page), and returns the associated DataObject
     * (e.g. SiteTree). If we can't find a match, return null to indicate that we don't know.
     */
    public function typeToClass(string $typeOrClass): ?string
    {
        $map = $this->config()->classname_to_type_mapping;

        // First check if it's in the map as the full class name
        if (in_array($typeOrClass, array_keys($map))) {
            return $map[$typeOrClass];
        }

        // Check if it's a short name that maps to a class
        $map = array_flip($map);

        if (in_array($typeOrClass, array_keys($map))) {
            return $map[$typeOrClass];
        }

        // If it's not a class name or a short name we recognise, then we assume it's a class name and return it as-is
        return $typeOrClass;
    }

    /**
     * Duplicate of AppSearchService::environmentizeIndex() in silverstripe/silverstripe-search-service to avoid
     * coupling the two modules together.
     *
     * @param string $indexName The untouched index name. If a variant exists, it will be added to this.
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
            return sprintf('%s-%s', $variant, $indexName);
        }

        return $indexName;
    }
}
