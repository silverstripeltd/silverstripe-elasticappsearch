<?php

namespace SilverStripe\ElasticAppSearch\Tests\Service;

use Mockery;
use ReflectionMethod;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ElasticAppSearch\Gateway\ElasticsearchGateway;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\ElasticAppSearch\Service\AppSearchService;
use SilverStripe\ElasticAppSearch\Service\SpellcheckService;
use SilverStripe\ORM\ArrayList;

class SpellcheckServiceTest extends SapphireTest
{
    public function testGetSpellingSuggestions()
    {
        $query = new SearchQuery();
        $query->setQuery('originalWord1 originalWord2');
        $engineName = 'test';

        $request = new HTTPRequest('GET', '/search', ['q' => 'originalWord1 originalWord2']);

        $service = $this->configureSpellcheckServiceMock();

        // Get a response from our mock Elasticsearch gateway
        $response = $service->getSpellingSuggestions($query, $engineName, $request);

        $this->assertTrue($response instanceof ArrayList);
        $this->assertSame(2, $response->count());

        // Ensure the first suggestion is correct
        $this->assertSame('suggestion1 originalWord2', $response->offsetGet(0)->Suggestion);
        $this->assertSame('/search?q=suggestion1+originalWord2', $response->offsetGet(0)->Link);

        // Ensure the second suggestion is correct
        $this->assertSame('suggestion2 originalWord2', $response->offsetGet(1)->Suggestion);
        $this->assertSame('/search?q=suggestion2+originalWord2', $response->offsetGet(1)->Link);

        // After modifying the query param, test that links still get re-written correctly
        Config::modify()->set(SpellcheckService::class, 'query_param', 'keywords');
        $newRequest = new HTTPRequest('GET', '/search', ['keywords' => 'originalWord1 originalWord2']);
        $response = $service->getSpellingSuggestions($query, $engineName, $newRequest);

        $this->assertSame('/search?keywords=suggestion1+originalWord2', $response->offsetGet(0)->Link);
        $this->assertSame('/search?keywords=suggestion2+originalWord2', $response->offsetGet(1)->Link);

        // If the maximum number of suggestions is increased, ensure we get more suggestions
        Config::modify()->set(SpellcheckService::class, 'query_param', 'q');
        Config::modify()->set(SpellcheckService::class, 'max_spellcheck_suggestions', 10);
        $response = $service->getSpellingSuggestions($query, $engineName, $request);

        $this->assertSame(9, $response->count());

        $this->assertSame('suggestion1 originalWord2', $response->offsetGet(0)->Suggestion);
        $this->assertSame('suggestion2 originalWord2', $response->offsetGet(1)->Suggestion);
        $this->assertSame('suggestion3 originalWord2', $response->offsetGet(2)->Suggestion);
        $this->assertSame('suggestion4 originalWord2', $response->offsetGet(3)->Suggestion);
        $this->assertSame('suggestion5 originalWord2', $response->offsetGet(4)->Suggestion);
        $this->assertSame('suggestion6 originalWord2', $response->offsetGet(5)->Suggestion);
        $this->assertSame('originalWord1 suggestion1', $response->offsetGet(6)->Suggestion);
        $this->assertSame('originalWord1 suggestion2', $response->offsetGet(7)->Suggestion);
        $this->assertSame('originalWord1 suggestion3', $response->offsetGet(8)->Suggestion);

        $this->assertSame('/search?q=suggestion1+originalWord2', $response->offsetGet(0)->Link);
        $this->assertSame('/search?q=suggestion2+originalWord2', $response->offsetGet(1)->Link);
        $this->assertSame('/search?q=suggestion3+originalWord2', $response->offsetGet(2)->Link);
        $this->assertSame('/search?q=suggestion4+originalWord2', $response->offsetGet(3)->Link);
        $this->assertSame('/search?q=suggestion5+originalWord2', $response->offsetGet(4)->Link);
        $this->assertSame('/search?q=suggestion6+originalWord2', $response->offsetGet(5)->Link);
        $this->assertSame('/search?q=originalWord1+suggestion1', $response->offsetGet(6)->Link);
        $this->assertSame('/search?q=originalWord1+suggestion2', $response->offsetGet(7)->Link);
        $this->assertSame('/search?q=originalWord1+suggestion3', $response->offsetGet(8)->Link);
    }

    public function testExtractPotentialSuggestions()
    {
        // This is a very simple function, we just have to test that it works correctly when we pass an expected array
        // and fails gracefully when we pass an array without key features present.
        // The bulk of the processing is tested in other tests below
        $service = Injector::inst()->get(SpellcheckService::class);
        $method = new ReflectionMethod(SpellcheckService::class, 'extractPotentialSuggestions');
        $method->setAccessible(true);

        // With an invalid input, we expect a blank array response
        $this->assertSame([], $method->invoke($service, []));
        $this->assertSame([], $method->invoke($service, ['invalid_value']));
        $this->assertSame([], $method->invoke($service, ['invalid_array_key' => 'invalid_value']));
    }

    public function testFlattenSuggestions()
    {
        $service = Injector::inst()->get(SpellcheckService::class);
        $method = new ReflectionMethod(SpellcheckService::class, 'flattenSuggestions');
        $method->setAccessible(true);

        $esResponse = $this->getMockElasticsearchResponse();
        $flattenedSuggestions = $method->invoke($service, $esResponse);

        $this->assertSame(2, sizeof($flattenedSuggestions));
        $this->assertArrayHasKey('originalWord1', $flattenedSuggestions);
        $this->assertArrayHasKey('originalWord2', $flattenedSuggestions);

        // Ensure suggestions that exist in multiple fields are merged into a single suggestion
        $this->assertSame(6, sizeof($flattenedSuggestions['originalWord1']));
        $this->assertSame(4, sizeof($flattenedSuggestions['originalWord2'])); // 3 suggestions plus the original word

        // Assert first suggestion for originalWord2 is the original word, it should have been inserted with score = 1
        $this->assertSame('originalWord2', $flattenedSuggestions['originalWord2'][0]['text']);
        $this->assertSame(1, $flattenedSuggestions['originalWord2'][0]['score']);

        // Flattened suggestions are also sorted, so we can double-check that sorting works across multiple fields here
        $this->assertSame('suggestion1', $flattenedSuggestions['originalWord1'][0]['text']);
        $this->assertSame('suggestion2', $flattenedSuggestions['originalWord1'][1]['text']);
        $this->assertSame('suggestion3', $flattenedSuggestions['originalWord1'][2]['text']);
        $this->assertSame('suggestion4', $flattenedSuggestions['originalWord1'][3]['text']);
        $this->assertSame('suggestion5', $flattenedSuggestions['originalWord1'][4]['text']);
        $this->assertSame('suggestion6', $flattenedSuggestions['originalWord1'][5]['text']);

        // Ensure that scores are overwritten when higher in a subsequent field
        $this->assertSame(1, $flattenedSuggestions['originalWord1'][0]['score']);
        $this->assertSame(0.75, $flattenedSuggestions['originalWord1'][1]['score']);
        $this->assertSame(0.65, $flattenedSuggestions['originalWord1'][2]['score']);
        $this->assertSame(0.25, $flattenedSuggestions['originalWord1'][3]['score']);
        $this->assertSame(0.15, $flattenedSuggestions['originalWord1'][4]['score']);
        $this->assertSame(0.10, $flattenedSuggestions['originalWord1'][5]['score']);

        // Same checks for originalWord2
        $this->assertSame('originalWord2', $flattenedSuggestions['originalWord2'][0]['text']);
        $this->assertSame('suggestion1', $flattenedSuggestions['originalWord2'][1]['text']);
        $this->assertSame('suggestion2', $flattenedSuggestions['originalWord2'][2]['text']);
        $this->assertSame('suggestion3', $flattenedSuggestions['originalWord2'][3]['text']);

        // Ensure that scores work for a simpler suggestion set
        $this->assertSame(1, $flattenedSuggestions['originalWord2'][0]['score']);
        $this->assertSame(0.90, $flattenedSuggestions['originalWord2'][1]['score']);
        $this->assertSame(0.80, $flattenedSuggestions['originalWord2'][2]['score']);
        $this->assertSame(0.20, $flattenedSuggestions['originalWord2'][3]['score']);
    }

    public function testSortSuggestions()
    {
        $service = Injector::inst()->get(SpellcheckService::class);
        $method = new ReflectionMethod(SpellcheckService::class, 'sortSuggestions');
        $method->setAccessible(true);

        $suggestions = [
            'originalWord1' => [
                // The value of `text` here indicates the order we expect to see the sorted results in
                [ 'text' => 'suggestion5', 'score' => 0 ],
                [ 'text' => 'suggestion2', 'score' => 0.50 ],
                [ 'text' => 'suggestion4', 'score' => 0.25 ],
                [ 'text' => 'suggestion1', 'score' => 1 ],
                [ 'text' => 'suggestion3', 'score' => 0.251 ]
            ],

            'originalWord2' => [
                [ 'text' => 'suggestion6', 'score' => 1 ],
                [ 'text' => 'suggestion8', 'score' => 0.2 ],
                [ 'text' => 'suggestion7', 'score' => 0.75 ],
            ],

            'originalWord3' => [],

            'originalWord4' => [
                [ 'text' => 'suggestion9', 'score' => 0.5 ]
            ]
        ];

        $sortedSuggestions = $method->invoke($service, $suggestions);

        $this->assertSame(4, sizeof($sortedSuggestions)); // Four original words
        $this->assertSame(5, sizeof($sortedSuggestions['originalWord1'])); // Still 5 suggestions for word1
        $this->assertSame(3, sizeof($sortedSuggestions['originalWord2'])); // And 3 suggestions for word2
        $this->assertSame(0, sizeof($sortedSuggestions['originalWord3'])); // Still 0 suggestions for word3
        $this->assertSame(1, sizeof($sortedSuggestions['originalWord4'])); // And 1 suggestion for word4

        $this->assertSame('suggestion1', $sortedSuggestions['originalWord1'][0]['text']);
        $this->assertSame('suggestion2', $sortedSuggestions['originalWord1'][1]['text']);
        $this->assertSame('suggestion3', $sortedSuggestions['originalWord1'][2]['text']);
        $this->assertSame('suggestion4', $sortedSuggestions['originalWord1'][3]['text']);
        $this->assertSame('suggestion5', $sortedSuggestions['originalWord1'][4]['text']);

        $this->assertSame('suggestion6', $sortedSuggestions['originalWord2'][0]['text']);
        $this->assertSame('suggestion7', $sortedSuggestions['originalWord2'][1]['text']);
        $this->assertSame('suggestion8', $sortedSuggestions['originalWord2'][2]['text']);

        $this->assertSame('suggestion9', $sortedSuggestions['originalWord4'][0]['text']);
    }

    public function testSelectSuggestions()
    {
        $service = Injector::inst()->get(SpellcheckService::class);
        $method = new ReflectionMethod(SpellcheckService::class, 'selectSuggestions');
        $method->setAccessible(true);

        $searchQuery = new SearchQuery();
        $searchQuery->setQuery('originalWord1 originalWord2');

        $mockResults = [
            'originalWord1' => [
                [
                    'text' => 'suggestion1',
                    'score' => 1
                ],
                [
                    'text' => 'suggestion2',
                    'score' => 0.75
                ]
            ],

            'originalWord2' => [
                [
                    'text' => 'suggestion3',
                    'score' => 1
                ]
            ]
        ];

        // Test that we get a single suggestion back that modifies originalWord1 by default
        Config::modify()->set(SpellcheckService::class, 'max_spellcheck_suggestions', 1);
        $selectedSuggestions = $method->invoke($service, $mockResults, $searchQuery);
        $this->assertSame(1, sizeof($selectedSuggestions));
        $this->assertSame('suggestion1 originalWord2', $selectedSuggestions[0]);

        // Test that we get two suggestions back for originalWord1 when we increase the max
        Config::modify()->set(SpellcheckService::class, 'max_spellcheck_suggestions', 2);
        $selectedSuggestions = $method->invoke($service, $mockResults, $searchQuery);
        $this->assertSame(2, sizeof($selectedSuggestions));
        $this->assertSame('suggestion1 originalWord2', $selectedSuggestions[0]);
        $this->assertSame('suggestion2 originalWord2', $selectedSuggestions[1]);

        // Test that we replace the second word if there aren't enough suggestions for the first word
        Config::modify()->set(SpellcheckService::class, 'max_spellcheck_suggestions', 3);
        $selectedSuggestions = $method->invoke($service, $mockResults, $searchQuery);
        $this->assertSame(3, sizeof($selectedSuggestions));
        $this->assertSame('suggestion1 originalWord2', $selectedSuggestions[0]);
        $this->assertSame('suggestion2 originalWord2', $selectedSuggestions[1]);
        $this->assertSame('originalWord1 suggestion3', $selectedSuggestions[2]);

        // Finally, test that the max number being larger than our total suggestions doesn't break anything
        Config::modify()->set(SpellcheckService::class, 'max_spellcheck_suggestions', 500);
        $selectedSuggestions = $method->invoke($service, $mockResults, $searchQuery);
        $this->assertSame(3, sizeof($selectedSuggestions));
    }

    private function getMockElasticsearchResponse()
    {
        return [
            'took' => 1,
            'timed_out' => null,
            'hits' => [],
            '_shards' => [],
            'suggest' => [
                'field1' => [
                    [
                        'text' => 'originalWord1',
                        'options' => [
                            [ 'text' => 'suggestion1', 'score' => 1 ],
                            [ 'text' => 'suggestion3', 'score' => 0.5 ],
                            [ 'text' => 'suggestion4', 'score' => 0.25 ],
                            [ 'text' => 'suggestion2', 'score' => 0.75 ]
                        ]
                    ],

                    [
                        'text' => 'originalWord2',
                        'options' => [
                            [ 'text' => 'suggestion1', 'score' => 0.90 ], // Should not be merged with above
                            [ 'text' => 'suggestion3', 'score' => 0.20 ],
                            [ 'text' => 'suggestion2', 'score' => 0.80 ], // Should not be merged with above
                        ]
                    ]
                ],

                'field2' => [
                    [
                        'text' => 'originalWord1',
                        'options' => [
                            [ 'text' => 'suggestion1', 'score' => 0.25 ], // Lower score than field1->suggestion1
                            [ 'text' => 'suggestion3', 'score' => 0.65 ], // Higher score than field1->suggestion3
                            [ 'text' => 'suggestion5', 'score' => 0.15 ],
                            [ 'text' => 'suggestion6', 'score' => 0.10 ],
                        ]
                    ],
                    [
                        'text' => 'originalWord2',
                        'options' => [] // Implying that the original word is correctly spelled in this field
                    ]
                ]
            ]
        ];
    }

    private function configureSpellcheckServiceMock()
    {
        // Setup ElasticsearchGateway mock
        $gatewayMock = Mockery::mock(ElasticsearchGateway::class);
        $gatewayMock
            ->shouldReceive('search')
            ->withArgs([
                [
                    'index' => '.ent-search-engine-abc123thisisahash',
                    'body' => [
                        'suggest' => [
                            'text' => 'originalWord1 originalWord2',
                            'field1' => [
                                'term' => [
                                    'field' => 'field1'
                                ]
                            ],
                            'field2' => [
                                'term' => [
                                    'field' => 'field2'
                                ]
                            ]
                        ]
                    ]
                ]
            ])
            ->andReturn($this->getMockElasticsearchResponse());

        // Setup AppSearchService mock
        $appSearchServiceMock = Mockery::mock(AppSearchService::class);
        $appSearchServiceMock
            ->shouldReceive('environmentizeIndex')
            ->withArgs(['test']) // Engine name from YML config fragment below
            ->andReturn('test'); // 'Full' engine name (same as input in this case)

        // Catch the call to ensureSpellcheckAvailable and disable it (don't run original method as we don't care if
        // things aren't configured properly)
        /** @var SpellcheckService $service */
        $service = Mockery::mock(SpellcheckService::class . '[ensureSpellcheckAvailable]')
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('ensureSpellcheckAvailable');

        $service
            ->setElasticsearchGateway($gatewayMock)
            ->setAppSearchService($appSearchServiceMock);

        Config::modify()
            ->set(SpellcheckService::class, 'query_param', 'q')
            ->set(SpellcheckService::class, 'spellcheck_suggestion_fields', [
                'test' => [
                    'internal_index_name' => '.ent-search-engine-abc123thisisahash',
                    'fields' => [
                        'field1',
                        'field2'
                    ]
                ]
            ]);

        return $service;
    }
}
