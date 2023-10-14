<?php

namespace SilverStripe\ElasticAppSearch\Tests\Query;

use Elastic\EnterpriseSearch\AppSearch\Schema\SimpleObject;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class SearchQueryTest extends SapphireTest
{
    public function testQuery(): void
    {
        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $searchQuery->setQuery('foo');
        $this->assertEquals('foo', $searchQuery->getQuery());
    }

    public function testSort(): void
    {
        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $searchQuery->addSort('foo', 'desc');
        $params = $searchQuery->getSearchParams();

        $this->assertCount(2, $params->sort);
        $this->assertEquals(['foo' => 'desc'], array_shift($params->sort));
        $this->assertEquals(['_score' => 'desc'], array_pop($params->sort));

        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $searchQuery->addSort('foo', 'desc');
        $searchQuery->addSort('qux');
        $params = $searchQuery->getSearchParams();

        $this->assertCount(3, $params->sort);
        $this->assertEquals(['foo' => 'desc'], array_shift($params->sort));
        $this->assertEquals(['qux' => 'asc'], array_shift($params->sort));
        $this->assertEquals(['_score' => 'desc'], array_pop($params->sort));

        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $searchQuery->addSorts(
            [
                'bar' => 'desc',
                'baz' => 'asc',
            ]
        );
        $params = $searchQuery->getSearchParams();
        $this->assertCount(3, $params->sort);
        $this->assertEquals(['bar' => 'desc'], array_shift($params->sort));
        $this->assertEquals(['baz' => 'asc'], array_shift($params->sort));
        $this->assertEquals(['_score' => 'desc'], array_pop($params->sort));
    }

    public function testFacets(): void
    {
        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);

        $facets = new SimpleObject();
        $facets->foo = [];
        $facets->foo[] = (object)[
            'type' => 'value',
            'name' => 'bar',
            'sort' => (object)['count' => 'desc'],
            'size' => 250,
        ];
        $facets->foo[] = (object)[
            'type' => 'range',
            'name' => 'baz',
            'ranges' => [
                (object)['from' => 1, 'to' => 100],
                (object)['from' => 100],
            ],
        ];
        $facets->qux = [];
        $facets->qux[] = (object)[
            'type' => 'value',
            'name' => 'qit',
            'sort' => (object)['count' => 'desc'],
            'size' => 250,
        ];
        $searchQuery->addRawFacets($facets);

        $params = $searchQuery->getSearchParams();

        $this->assertSame($params->facets, $facets);
    }
}
