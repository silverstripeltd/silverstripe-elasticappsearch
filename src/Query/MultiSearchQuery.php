<?php

namespace SilverStripe\ElasticAppSearch\Query;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

class MultiSearchQuery
{
    /**
     * @var SearchQuery[]
     */
    private $queries;

    /**
     * @param SearchQuery[] $queries
     */
    public function setQueries(array $queries): MultiSearchQuery
    {
        if (count($queries) > 10) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'Maximum number of queries for multisearch is exceeded, only submitting first 10'
            );
            $queries = array_slice($queries, 0, 10);
        }

        $this->queries = $queries;

        return $this;
    }

    /**
     * @return SearchQuery[]
     */
    public function getQueries(): array
    {
        return $this->queries ?? [];
    }

    /**
     * Renders the data for passing into the Elastic App Client
     *
     * @return array
     */
    public function renderQueries(): array
    {
        $data = [];
        foreach ($this->getQueries() as $query) {
            array_push($data, array_merge(
                ['query' => $query->getQuery()],
                $query->getSearchParamsAsArray()
            ));
        }
        return $data;
    }

    /**
     * @param SearchQuery $query
     * @return $this
     */
    public function addQuery(SearchQuery $query): MultiSearchQuery
    {
        if ($this->queries !== null && count($this->queries) >= 10) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'Maximum number of queries for multisearch is reached'
            );
        } else {
            $this->queries[] = $query;
        }

        return $this;
    }
}
