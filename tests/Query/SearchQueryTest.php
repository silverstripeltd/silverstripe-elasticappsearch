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
        parent::setUpBeforeClass();
        self::$serializer = new SmartSerializer();
    }

    public function testQuery()
    {
        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $searchQuery->setQuery('foo');
        $this->assertEquals('foo', $searchQuery->getQuery());
    }

    public function testSort()
    {
        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $params = $searchQuery->getSearchParamsAsArray();
        $this->assertArrayNotHasKey('sort', $params);
        $expectedJson = <<<JSON
{}
JSON;
        $this->assertJsonStringEqualsJsonString($expectedJson, self::serializeBody($searchQuery));

        $searchQuery->addSort('foo', 'desc');
        $params = $searchQuery->getSearchParamsAsArray();
        $this->assertArrayHasKey('sort', $params);
        $this->assertCount(2, $params['sort']);
        $this->assertEquals(['foo' => 'desc'], array_shift($params['sort']));
        $this->assertEquals(['_score' => 'desc'], array_pop($params['sort']));
        $expectedJson = <<<JSON
{
    "sort": [
        {"foo": "desc"},
        {"_score": "desc"}
    ]
}
JSON;
        $this->assertJsonStringEqualsJsonString($expectedJson, self::serializeBody($searchQuery));

        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $searchQuery->addSort('foo', 'desc');
        $searchQuery->addSort('qux');
        $params = $searchQuery->getSearchParamsAsArray();
        $this->assertArrayHasKey('sort', $params);
        $this->assertCount(3, $params['sort']);
        $this->assertEquals(['foo' => 'desc'], array_shift($params['sort']));
        $this->assertEquals(['qux' => 'asc'], array_shift($params['sort']));
        $this->assertEquals(['_score' => 'desc'], array_pop($params['sort']));
        $expectedJson = <<<JSON
{
    "sort": [
        {"foo": "desc"},
        {"qux": "asc"},
        {"_score": "desc"}
    ]
}
JSON;
        $this->assertJsonStringEqualsJsonString($expectedJson, self::serializeBody($searchQuery));


        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $searchQuery->addSorts(
            [
                'bar' => 'desc',
                'baz' => 'asc',
            ]
        );
        $params = $searchQuery->getSearchParamsAsArray();
        $this->assertArrayHasKey('sort', $params);
        $this->assertCount(3, $params['sort']);
        $this->assertEquals(['bar' => 'desc'], array_shift($params['sort']));
        $this->assertEquals(['baz' => 'asc'], array_shift($params['sort']));
        $this->assertEquals(['_score' => 'desc'], array_pop($params['sort']));
        $expectedJson = <<<JSON
{
    "sort": [
        {"bar": "desc"},
        {"baz": "asc"},
        {"_score": "desc"}
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
