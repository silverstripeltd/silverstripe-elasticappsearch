<?php

namespace Madmatt\ElasticAppSearch\Tests\Query;

use Elastic\OpenApi\Codegen\Serializer\SmartSerializer;
use Madmatt\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class SearchQueryTest extends SapphireTest
{
    /**
     * @var SmartSerializer
     */
    private static $serializer;

    public static function setUpBeforeClass()
    {
        self::$serializer = new SmartSerializer();
    }

    public function testQuery()
    {
        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $searchQuery->setQuery('foo');
        $this->assertEquals('foo', $searchQuery->getQuery());
        $expectedJson = <<<JSON
{
    "query": "foo"
}
JSON;
        $this->assertJsonStringEqualsJsonString($expectedJson, self::serializeBody($searchQuery));
    }

    public function testSort()
    {
        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $params = $searchQuery->getSearchParamsAsArray();
        $this->assertArrayNotHasKey('sort', $params);

        $searchQuery->addSort('foo', 'desc');
        $params = $searchQuery->getSearchParamsAsArray();
        $this->assertArrayHasKey('sort', $params);
        $this->assertCount(2, $params['sort']);
        $this->assertEquals(['foo' => 'desc'], array_shift($params['sort']));
        $this->assertEquals(['_sort' => 'desc'], array_pop($params['sort']));

        $searchQuery->addSorts(
            [
                'bar' => 'desc',
                'baz' => 'asc',
            ]
        );
        $params = $searchQuery->getSearchParamsAsArray();
        $this->assertArrayHasKey('sort', $params);
        $this->assertCount(4, $params['sort']);
        $this->assertEquals(['foo' => 'desc'], array_shift($params['sort']));
        $this->assertEquals(['bar' => 'desc'], array_shift($params['sort']));
        $this->assertEquals(['baz' => 'asc'], array_shift($params['sort']));
        $this->assertEquals(['_sort' => 'desc'], array_pop($params['sort']));
        $expectedJson = <<<JSON
{
    "query": "",
    "sort": [
        {"foo": "desc"},
        {"bar": "desc"},
        {"baz": "asc"},
        {"_sort": "desc"}
    ]
}
JSON;
        $this->assertJsonStringEqualsJsonString($expectedJson, self::serializeBody($searchQuery));
    }

    /*
     * Mimic the method the Elastic Client uses to convert the search params into JSON so we can assert
     * that they look as expected
     */
    private static function serializeBody(SearchQuery $query): string
    {
        $body = $query->getSearchParamsAsArray();
        ksort($body);
        return self::$serializer->serialize($body);
    }
}