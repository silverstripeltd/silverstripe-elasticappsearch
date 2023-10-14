<?php

namespace SilverStripe\ElasticAppSearch\Query;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

class MultiSearchQuery
{
    /**
     * @var SearchQuery[]
     */
    private array $queries = [];

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

    public function getQueries(): array
    {
        return $this->queries ?? [];
    }

    /**
     * Renders the data for passing into the Elastic App Client.
     */
    public function getSearchParams(): array
    {
        $data = [];
        foreach ($this->getQueries() as $query) {
            $data[] = $query->getSearchParams();
        }

        return $data;
    }

    public function addQuery(SearchQuery $query): self
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
