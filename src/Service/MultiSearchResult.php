<?php

namespace SilverStripe\ElasticAppSearch\Service;

use Elastic\EnterpriseSearch\Response\Response;
use InvalidArgumentException;
use LogicException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ElasticAppSearch\Query\MultiSearchQuery;
use SilverStripe\View\ViewableData;

class MultiSearchResult extends ViewableData
{
    use Injectable;

    private ?MultiSearchQuery $query = null;

    private ?array $response = null;

    private array $results = [];

    public function __construct(MultiSearchQuery $query, array $response)
    {
        parent::__construct();

        $this->query = $query;
        $this->setResponse($response);
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function setResults(array $results): self
    {
        $this->results = $results;

        return $this;
    }

    public function addResult(SearchResult $result): self
    {
        $this->results[] = $result;

        return $this;
    }

    public function setResponse(array $response): self
    {
        $this->validateResponse($response);

        $this->response = $response;

        return $this;
    }

    public function getResponse(): array
    {
        return $this->response;
    }

    public function setEngineName(string $name): self
    {
        foreach ($this->results as $result) {
            $result->setEngineName($name);
        }

        return $this;
    }

    /**
     * @throws InvalidArgumentException Thrown if the provided response is not from Elastic, or is missing expected data
     * @throws LogicException Thrown if the provided response is valid, but is an error
     */
    protected function validateResponse(array $response): void
    {
        $queries = $this->query->getQueries();

        // We rely on the results coming back in the same order as the queries - there are no identifying
        // characteristics on the result that we can match across
        foreach ($response as $index => $singleResponse) {
            /** @var Response $singleResponse */
            $singleResult = SearchResult::create($queries[$index]->getQuery(), $singleResponse, true);
            $this->addResult($singleResult);
        }
    }
}
