<?php

namespace SilverStripe\ElasticAppSearch\Tests\Query;

use Elastic\OpenApi\Codegen\Serializer\SmartSerializer;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
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

    public function testFacets()
    {
        /** @var SearchQuery $searchQuery */
        $searchQuery = Injector::inst()->create(SearchQuery::class);
        $params = $searchQuery->getSearchParamsAsArray();
        $this->assertArrayNotHasKey('facets', $params);
        $expectedJson = <<<JSON
{}
JSON;
        $this->assertJsonStringEqualsJsonString($expectedJson, self::serializeBody($searchQuery));

        $facets = new \stdClass();
        $facets->foo = [];
        $facets->foo[] = (object) [
            'type' => 'value',
            'name' => 'bar',
            'sort' => (object) ['count' => 'desc'],
            'size' => 250,
        ];
        $facets->foo[] = (object) [
            'type' => 'range',
            'name' => 'baz',
            'ranges' => [
                (object) ['from' => 1, 'to' => 100],
                (object) ['from' => 100],
            ],
        ];
        $facets->qux = [];
        $facets->qux[] = (object) [
            'type' => 'value',
            'name' => 'qit',
            'sort' => (object) ['count' => 'desc'],
            'size' => 250,
        ];
        $searchQuery->addRawFacets($facets);

        $expectedJson = <<<JSON
{
    "facets": {
        "foo": [
            {
                "type": "value",
                "name": "bar",
                "sort": { "count": "desc" },
                "size": 250
            },
            {
                "type": "range",
                "name": "baz",
                "ranges": [
                  { "from": 1, "to": 100 },
                  { "from": 100 }
                ]
            }
        ],
        "qux": [
            {
                "type": "value",
                "name": "qit",
                "sort": { "count": "desc" },
                "size": 250
            }
        ]
    }
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
