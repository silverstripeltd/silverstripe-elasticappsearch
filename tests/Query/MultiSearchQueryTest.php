<?php

namespace Madmatt\ElasticAppSearch\Tests\Query;

use Elastic\OpenApi\Codegen\Serializer\SmartSerializer;
use Madmatt\ElasticAppSearch\Query\MultiSearchQuery;
use Madmatt\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class MultiSearchQueryTest extends SapphireTest
{
    public function testAddQuery()
    {
        /** @var MultiSearchQuery $multisearchQuery */
        $multisearchQuery = Injector::inst()->create(MultiSearchQuery::class);

        /** @var SearchQuery $fooQuery */
        $fooQuery = Injector::inst()->create(SearchQuery::class);
        $fooQuery->setQuery('foo');

        $multisearchQuery->addQuery($fooQuery);

        $this->assertEquals($fooQuery, $multisearchQuery->getQueries()[0]);

        /** @var SearchQuery $barQuery */
        $barQuery = Injector::inst()->create(SearchQuery::class);
        $barQuery->setQuery('bar');

        $multisearchQuery->addQuery($barQuery);

        $this->assertEquals($fooQuery, $multisearchQuery->getQueries()[0]);
        $this->assertEquals($barQuery, $multisearchQuery->getQueries()[1]);
    }

    public function testSetQueries()
    {
        /** @var MultiSearchQuery $multisearchQuery */
        $multisearchQuery = Injector::inst()->create(MultiSearchQuery::class);

        $fooQuery = Injector::inst()->create(SearchQuery::class);
        $fooQuery->setQuery('foo');

        /** @var SearchQuery $barQuery */
        $barQuery = Injector::inst()->create(SearchQuery::class);
        $barQuery->setQuery('bar');

        $multisearchQuery->setQueries([$fooQuery, $barQuery]);

        $this->assertCount(2, $multisearchQuery->getQueries());
        $this->assertEquals($fooQuery, $multisearchQuery->getQueries()[0]);
        $this->assertEquals($barQuery, $multisearchQuery->getQueries()[1]);

        // Assert that a too-big array is reduced to 10
        $tooManyQueries = array_pad([], 11, $fooQuery);

        $multisearchQuery->setQueries($tooManyQueries);
        $this->assertCount(10, $multisearchQuery->getQueries());
    }

    public function testRenderQueries()
    {
        /** @var MultiSearchQuery $multisearchQuery */
        $multisearchQuery = Injector::inst()->create(MultiSearchQuery::class);

        /** @var SearchQuery $fooQuery */
        $fooQuery = Injector::inst()->create(SearchQuery::class);
        $fooQuery->setQuery('foo');

        /** @var SearchQuery $barQuery */
        $barQuery = Injector::inst()->create(SearchQuery::class);
        $barQuery->setQuery('bar');
        $barQuery->addSorts(
            [
                'baz' => 'desc',
                'qux' => 'asc',
            ]
        );

        $multisearchQuery->setQueries([$fooQuery, $barQuery]);
        $serializer = new SmartSerializer();
        $sortedQueries = $multisearchQuery->renderQueries();
        ksort($sortedQueries);

        $expectedJson = <<<JSON
[
    {
        "query": "foo"
    },
    {
        "query": "bar",
        "sort": [
            {"baz": "desc"},
            {"qux": "asc"},
            {"_score": "desc"}
        ]
    }
]
JSON;

        $this->assertJsonStringEqualsJsonString(
            $expectedJson,
            $serializer->serialize($sortedQueries)
        );
    }
}
