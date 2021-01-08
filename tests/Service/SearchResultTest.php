<?php

namespace SilverStripe\ElasticAppSearch\Tests\Service;

use InvalidArgumentException;
use LogicException;
use SilverStripe\ElasticAppSearch\Service\SearchResult;
use SilverStripe\Dev\SapphireTest;

class SearchResultTest extends SapphireTest
{
    public function testValidateResponseHasErrors()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Response appears to be from Elastic but is an error, not a valid search result');
        new SearchResult('query', ['errors' => []]);
    }

    public function testValidateResponseNoMetaOrResults()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Response decoded as JSON but is not an Elastic search response');
        new SearchResult('query', []);
    }

    public function testValidateResponseNoMeta()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Response decoded as JSON but is not an Elastic search response');
        new SearchResult('query', ['results' => []]);
    }

    public function testValidateResponseNoResults()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Response decoded as JSON but is not an Elastic search response');
        new SearchResult('query', ['meta' => []]);
    }

    public function testFacets()
    {
        $result = new SearchResult('query', $this->getValidResponseFromBase([
            'facets' => [
                'content_type' => [
                    [
                        'type' => 'value',
                        'data' => [
                            [
                                'value' => 'document',
                                'count' => 9,
                            ],
                            [
                                'value' => 'page',
                                'count' => 2,
                            ]
                        ]
                    ]
                ],
                'topic' => [
                    [
                        'type' => 'value',
                        'name' => 'root_topics',
                        'data' => [
                            [
                                'value' => 'foo',
                                'count' => 11,
                            ],
                            [
                                'value' => 'bar',
                                'count' => 6,
                            ]
                        ]
                    ],
                    [
                        'type' => 'value',
                        'name' => 'sub_topics',
                        'data' => [
                            [
                                'value' => 'foobaz',
                                'count' => 7,
                            ],
                            [
                                'value' => 'barqux',
                                'count' => 1,
                            ]
                        ]
                    ]
                ],
                'size' => [
                    [
                        'type' => 'range',
                        'data' => [
                            [
                                'to' => 100,
                                'from' => 1,
                                'count' => 23,
                            ],
                            [
                                'from' => 100,
                                'count' => 66
                            ]
                        ]
                    ]
                ]
            ]
        ]));
        $this->assertNotNull($result->getFacets());
        $this->assertNotNull($sizeFacet = $result->getFacet('size'));
        $this->assertCount(2, $sizeFacet);

        $sizeFirst = $sizeFacet->first();
        $this->assertEquals(100, $sizeFirst->To);
        $this->assertEquals(1, $sizeFirst->From);
        $this->assertEquals(23, $sizeFirst->Count);


        $sizeLast = $sizeFacet->last();
        $this->assertEquals('', $sizeLast->To);
        $this->assertEquals(100, $sizeLast->From);
        $this->assertEquals(66, $sizeLast->Count);


        $this->assertNotNull($result->getFacet('content_type'));
        $this->assertNotNull($result->getFacet('topic', 'root_topics'));
        $this->assertNotNull($result->getFacet('topic', 'sub_topics'));
    }

    private function getValidResponseFromBase(?array $toMerge = null): array
    {
        $base = [
            'meta' => [
                'page' => [
                    'current' => 1,
                    'size' => 10,
                    'total_pages' => 1,
                    'total_results' => 10,
                ],
                'request_id' => 'test1234'
            ],
            'results' => []
        ];

        if ($toMerge === null || empty($toMerge)) {
            return $base;
        }

        return array_merge($base, $toMerge);
    }
}
