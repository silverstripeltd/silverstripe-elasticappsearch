<?php

namespace Madmatt\ElasticAppSearch\Service;

use Exception;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use \SilverStripe\View\ViewableData;

/**
 * Class SearchResult
 * @package Madmatt\ElasticAppSearch\Service
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

    private static $casting = [
        'Query' => 'Varchar',
    ];

    public function __construct(string $query, array $response)
    {
        parent::__construct();

        $this->query = $query;
        $this->response = $response;
        $this->validateResponse($response);
    }

    /**
     * @return PaginatedList
     */
    public function getResults()
    {
        if (!isset($this->results)) {
            $this->results = $this->extractResults($this->response);
        }

        return $this->results;
    }

    /**
     * @return ArrayList
     */
    public function getFacets()
    {
        if (!isset($this->facets)) {
            $this->facets = $this->extractFacets($this->response);
        }

        return $this->facets;
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
                if (in_array($resultField, ['_meta', 'id', 'record_base_class', 'record_id'])) continue;

                if (is_array($fieldValues) && array_key_exists('snippet', $fieldValues)) {
                    $snippets[$resultField] = DBField::create_field('HTMLVarchar', $fieldValues['snippet']);
                }
            }

            if (sizeof($snippets) > 0) {
                $obj->ElasticSnippets = ArrayData::create($snippets);
            }

            $this->extend('augmentSearchResult', $obj);

            // With the DataObject, we can check whether the current user can view it, and add it to our ArrayList if so
            if ($obj->canView(Security::getCurrentUser())) {
                $list->push($obj);
            }
        }

        // Convert the ArrayList we have into a PaginatedList so we can access pagination features within templates
        $list = PaginatedList::create($list);
        $list->setLimitItems(false);
        $list->setPageLength($response['meta']['page']['size']);
        $list->setTotalItems($response['meta']['page']['total_results']);
        $list->setCurrentPage($response['meta']['page']['current']);

        return $list;
    }


    protected function extractFacets(array $response): ArrayList
    {
        $list = [];
        foreach ($response['facets'] as $property => $results) {
            foreach ($results as $result){
                $list[] = [$property =>[$result['name']] = $result['data']];
            }
        }

        return ArrayList::create($list);
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

        // Ensure we have a request_id for future reference if needed
        if (!array_key_exists('request_id', $response['meta'])) {
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
}
