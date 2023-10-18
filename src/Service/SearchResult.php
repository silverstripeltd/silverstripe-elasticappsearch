<?php

namespace SilverStripe\ElasticAppSearch\Service;

use Exception;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ElasticAppSearch\Controller\ClickthroughController;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use \SilverStripe\View\ViewableData;

/**
 * Class SearchResult
 * @package SilverStripe\ElasticAppSearch\Service
 *
 * Wraps a single Elastic App Search result set. When constructed, all returned documents are retrieved from the local
 * database and stored as DataObjects in the 'results' key for use in templates.
 */
class SearchResult extends ViewableData
{
    use Injectable;

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class . '.errorhandler',
    ];

    /**
     * Tracking clickthroughs enables Elastic App Search to display analytics for how many queries were 'successful'
     * (e.g. caused a user to click through to at least one search result), as well as tracking which queries were
     * 'unsuccessful' (zero clickthroughs). This powers some of the graphs seen in the Analytics section of Elastic App
     * Search.
     *
     * @var bool true to log clickthroughs back to Elastic App Search, false to disable this feature
     */
    private static $track_clickthroughs = true;

    /**
     * Elastic has a default limit of handling 100 pages. If you request a page beyond this limit
     * then an error occurs. We use this to limit the number of pages that are returned.
     */
    private $elastic_page_limit = 100;

    /**
     * Elastic has a default limit of 10000 results returned in a single query
     */
    private $elastic_results_limit = 10000;

    /**
     * Whilst we might limit the number of results being returned in the paginated list
     * store the actual number of results a query returns.
     */
    private int $actual_result_count = 0;

    /**
     * @var LoggerInterface
     */
    public $logger;

    /**
     * @var string
     */
    private $query;

    /**
     * @var array
     */
    private $response;

    /**
     * @var PaginatedList
     */
    private $results;

    /**
     * @var ArrayList
     */
    private $facets;

    /**
     * @var ArrayList A list of spelling suggestions for the given result set, if enabled
     */
    private $spellingSuggestions;

    /**
     * @var bool
     */
    private $isPartOfMultiSearch = false;

    /**
     * @var string The name of the engine this search result has been returned from. Used for clickthrough tracking.
     * @see getClickthroughLink()
     */
    private $engineName = "";

    /**
     * @var array A set of tags that are passed through when clickthroughs are tracked.
     * @see getClickthroughLink()
     */
    private $tags = [];

    private static $casting = [
        'Query' => 'Varchar',
    ];

    public function __construct(string $query, array $response, bool $isPartOfMultiSearch = false)
    {
        parent::__construct();

        $this->query = $query;
        $this->response = $response;
        $this->isPartOfMultiSearch = $isPartOfMultiSearch;
        $this->validateResponse($response);
    }

    /**
     * @return PaginatedList
     */
    public function getResults(): PaginatedList
    {
        if (!isset($this->results)) {
            $this->results = $this->extractResults($this->response);
        }

        return $this->results;
    }

    public function getFacets(): ArrayList
    {
        if (!isset($this->facets)) {
            $this->facets = $this->extractFacets($this->response);
        }

        return $this->facets;
    }

    /**
     * @param string $facet
     * @param string|null $name
     */
    public function getFacet(string $facet, ?string $name = null): ArrayList
    {
        if (!isset($this->facets)) {
            $this->facets = $this->extractFacets($this->response);
        }

        $filtered = $this->facets->filter(
            [
                'Property' => $facet,
                'Name' => $name ?? 0,
            ]
        )->first();

        if ($filtered && $filtered->exists()) {
            return $filtered->Data;
        }

        return ArrayList::create();
    }

    public function getSpellingSuggestions(): ?ArrayList
    {
        return $this->spellingSuggestions;
    }

    public function setSpellingSuggestions(ArrayList $suggestions): self
    {
        $this->spellingSuggestions = $suggestions;
        return $this;
    }

    public function setEngineName(string $name): self
    {
        $this->engineName = $name;
        return $this;
    }

    public function setTags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    protected function extractResults(array $response): PaginatedList
    {
        $list = ArrayList::create();

        foreach ($response['results'] as $result) {
            // Get the DataObject ID and ClassName out for lookup in the database
            $class = $result['record_base_class']['raw'];
            $id = $result['record_id']['raw'];

            // Attempt to get the DataObject from the database to access the original object, skip this result if the
            // object no longer exists in the database
            try {
                /** @var DataObject $obj */
                $obj = $class::get()->byID($id);
            } catch (Exception $e) {
                $this->logger->warning(
                    sprintf(
                        'Could not find search result with class %s and ID %s in database (error: %s)',
                        $class,
                        $id,
                        $e->getMessage()
                    )
                );

                continue;
            }

            // Final check: if $obj doesn't exist it might be because the class is no longer known to Silverstripe (this
            // doesn't produce an exception above). Make sure we skip these records
            if (!$obj) {
                continue;
            }

            // Loop over all returned result fields and extract any that have a snippet, to be added into the object so
            // they can be displayed on the website later
            $snippets = [];
            foreach ($result as $resultField => $fieldValues) {
                // Skip known fields that we don't care about checking for snippet data
                if (in_array($resultField, ['_meta', 'id', 'record_base_class', 'record_id'])) {
                    continue;
                }

                if (is_array($fieldValues) && array_key_exists('snippet', $fieldValues) && $fieldValues['snippet'] !== null) {
                    $snippets[$resultField] = DBField::create_field('HTMLVarchar', $fieldValues['snippet']);
                }
            }

            if (sizeof($snippets) > 0) {
                $obj->ElasticSnippets = ArrayData::create($snippets);
            }

            // Build data required to process the clickthrough link
            if ($this->config()->track_clickthroughs) {
                $obj->ClickthroughLink = $this->getClickthroughLink($result, $obj);
            }

            $this->extend('augmentSearchResult', $obj);

            // With the DataObject, we can check whether the current user can view it, and add it to our ArrayList if so
            // First check the method 'canViewInSearch', then fallback to regular 'canView'
            $currentUser = Security::getCurrentUser();
            $canView = $obj->extendedCan('canViewInSearch', $currentUser);
            if ($canView === null) {
                $canView = $obj->canView($currentUser);
            }

            if ($canView) {
                $list->push($obj);
            }
        }

        // Limit results to 100 pages
        $pageSize = $response['meta']['page']['size'];
        $this->actual_result_count = $response['meta']['page']['total_results'];

        // Calculate total results that can be handled, taking into account default elastic limits.
        $totalResults = min([
            $this->actual_result_count,
            $this->elastic_page_limit * $pageSize,
            $this->elastic_results_limit
        ]);

        // Convert the ArrayList we have into a PaginatedList so we can access pagination features within templates
        $list = PaginatedList::create($list);
        $list->setLimitItems(false);
        $list->setPageLength($pageSize);
        $list->setTotalItems($totalResults);
        $list->setCurrentPage($response['meta']['page']['current']);

        return $list;
    }

    /**
     * Return the actual number of results a query returns, regardless of any limits
     * on pagination.
     */
    public function getActualResultCount(): int
    {
        return $this->actual_result_count;
    }

    /**
     * @param array $response
     * @return ArrayList
     */
    protected function extractFacets(array $response): ArrayList
    {
        $list = ArrayList::create();
        foreach ($response['facets'] as $property => $results) {
            foreach ($results as $index => $result) {
                $data = ArrayList::create();
                foreach ($result['data'] as $resultData) {
                    $data->push(ArrayData::create([
                        'Value' => $resultData['value'] ?? '',
                        'From' => $resultData['from'] ?? '',
                        'To' => $resultData['to'] ?? '',
                        'Count' => $resultData['count'],
                    ]));
                }

                $list[] = ArrayData::create([
                    'Property' => $property,
                    'Name' => $result['name'] ?? $index,
                    'Data' => $data,
                ]);
            }
        }

        return $list;
    }

    /**
     * @param array $response
     * @throws InvalidArgumentException Thrown if the provided response is not from Elastic, or is missing expected data
     * @throws LogicException Thrown if the provided response is valid, but is an error
     */
    protected function validateResponse(array $response)
    {
        // Ensure we don't have errors in our response
        if (array_key_exists('errors', $response)) {
            throw new LogicException('Response appears to be from Elastic but is an error, not a valid search result');
        }

        // Ensure we have both required top-level array keys (`meta` and `results`)
        if (!array_key_exists('meta', $response) || !array_key_exists('results', $response)) {
            throw new InvalidArgumentException('Response decoded as JSON but is not an Elastic search response');
        }

        // Ensure we have pagination results (total number of items etc)
        if (!array_key_exists('page', $response['meta'])) {
            throw new InvalidArgumentException('Missing array structure for meta.page in Elastic search response');
        }

        foreach (['current', 'size', 'total_pages', 'total_results'] as $expectedKey) {
            if (!array_key_exists($expectedKey, $response['meta']['page'])) {
                throw new InvalidArgumentException(sprintf('Expected value for meta.page.%s not found', $expectedKey));
            }
        }

        // Ensure we have a request_id for future reference if needed (not included in multi-search results)
        if (!$this->isPartOfMultiSearch && !array_key_exists('request_id', $response['meta'])) {
            throw new InvalidArgumentException('Expected value for meta.request_id not found');
        }

        // Ensure 'results' is a multi-dimensional array (an array containing arrays)
        if (!is_array($response['results'])) {
            throw new InvalidArgumentException('Expected results key of Elastic App Search response to be an array');
        }

        // Ensure we have the necessary result keys for each result
        foreach ($response['results'] as $result) {
            foreach (['record_base_class', 'record_id'] as $expectedKey) {
                if (!array_key_exists($expectedKey, $result)) {
                    throw new InvalidArgumentException('Unexpected document returned by Elastic without object context');
                }
            }
        }
    }

    /**
     * Get the clickthrough link for a given DataObject. This requires a set of data provided by the SearchService
     * during creation of the object to get context. We then create a JSON object and base64 encode it to get a URL-safe
     * string that can be inserted in a tracking URL, which will be picked up by the @link ClickthroughController and a
     * clickthrough registered in Elastic App Search before the user is directed to the search result.
     *
     * @return string
     */
    private function getClickthroughLink($result, DataObject $dataObject): string
    {
        $controllerLink = ClickthroughController::get_base_url();
        $documentId = isset($result['id']['raw']) ? $result['id']['raw'] : null;
        $requestId = isset($this->response['meta']['request_id']) ? $this->response['meta']['request_id'] : null;

        $data = [
            'id' => $dataObject->ID,
            'type' => $this->dataObjectToShortName($dataObject),
            'tags' => $this->tags,
            'query' => $this->query,
            'requestId' => $requestId,
            'documentId' => $documentId,
            'engineName' => $this->engineName,
        ];

        $linkData = sprintf('?d=%s', base64_encode(json_encode($data)));
        return Controller::join_links($controllerLink, $linkData);
    }

    /**
     * Converts a DataObject into a short string based on the name of the class. Can be overwridden by specifying the
     * class names you're searching over on AppSearchService (see _config/analytics.yml for some defaults).
     *
     * @param DataObject $object The DataObject to look up
     * @return string Either the human-readable short string, or the FQCN if one can't be found
     */
    private function dataObjectToShortName(DataObject $object): string
    {
        /** @var AppSearchService $service */
        $service = Injector::inst()->get(AppSearchService::class);
        return $service->classToType(DataObject::getSchema()->baseDataClass($object));
    }
}
