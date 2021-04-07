<?php

namespace SilverStripe\ElasticAppSearch\Service;

use SilverStripe\ElasticAppSearch\Query\MultiSearchQuery;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\View\ViewableData;

class MultiSearchResult extends ViewableData
{
    use Injectable;

    /**
     * @var MultiSearchQuery
     */
    private $query;

    /**
     * @var array
     */
    private $response;

    /**
     * @var SearchResult[]
     */
    private $results;

    /**
     * @return SearchResult[]
     */
    public function getResults(): ?array
    {
        return $this->results;
    }

    /**
     * @param SearchResult[] $results
     */
    public function setResults(array $results): void
    {
        $this->results = $results;
    }

    public function addResult(SearchResult $result): MultiSearchResult
    {
        $this->results[] = $result;
        return $this;
    }

    public function __construct(MultiSearchQuery $query, array $response)
    {
        parent::__construct();

        $this->query = $query;
        $this->response = $response;
        $this->validateResponse($response);
    }

    public function setEngineName(string $name): self
    {
        foreach ($this->results as $result) {
            $result->setEngineName($name);
        }

        return $this;
    }

    /**
     * @param array $response
     * @throws \InvalidArgumentException Thrown if the provided response is not from Elastic, or is missing expected data
     * @throws \LogicException Thrown if the provided response is valid, but is an error
     */
    protected function validateResponse(array $response)
    {
        $queries = $this->query->getQueries();
        // We rely on the results coming back in the same order as the queries - there are no identifying
        // characteristics on the result that we can match across
        foreach ($response as $index => $singleResponse) {
            $singleResult = SearchResult::create($queries[$index]->getQuery(), $singleResponse, true);
            $this->addResult($singleResult);
        }
    }
}
