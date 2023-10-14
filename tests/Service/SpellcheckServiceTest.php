<?php

namespace SilverStripe\ElasticAppSearch\Tests\Service;

use Elastic\EnterpriseSearch\Response\Response;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ElasticAppSearch\Gateway\AppSearchGateway;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\ElasticAppSearch\Service\AppSearchService;
use SilverStripe\ElasticAppSearch\Service\SpellcheckService;
use SilverStripe\ORM\ArrayList;
use Elastic\EnterpriseSearch\AppSearch\Schema;
use GuzzleHttp\Psr7;

class SpellcheckServiceTest extends SapphireTest
{
    public function testGetSpellingSuggestions(): void
    {
        $query = new SearchQuery();
        $query->setQuery('originalWord1 originalWord2');
        $engineName = 'test';

        $request = new HTTPRequest('GET', '/search', ['q' => 'originalWord1 originalWord2']);

        $service = $this->configureSpellcheckServiceMock();

        // Get a response from our mock Elasticsearch gateway
        $response = $service->getSpellingSuggestions($query, $engineName, $request);

        $this->assertInstanceOf(ArrayList::class, $response);
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

        $this->assertSame(10, $response->count());

        $this->assertSame('suggestion1 originalWord2', $response->offsetGet(0)->Suggestion);
        $this->assertSame('suggestion2 originalWord2', $response->offsetGet(1)->Suggestion);
        $this->assertSame('suggestion3 originalWord2', $response->offsetGet(2)->Suggestion);
        $this->assertSame('suggestion4 originalWord2', $response->offsetGet(3)->Suggestion);
        $this->assertSame('suggestion5 originalWord2', $response->offsetGet(4)->Suggestion);
        $this->assertSame('suggestion6 originalWord2', $response->offsetGet(5)->Suggestion);

        $this->assertSame('/search?q=suggestion1+originalWord2', $response->offsetGet(0)->Link);
        $this->assertSame('/search?q=suggestion2+originalWord2', $response->offsetGet(1)->Link);
        $this->assertSame('/search?q=suggestion3+originalWord2', $response->offsetGet(2)->Link);
        $this->assertSame('/search?q=suggestion4+originalWord2', $response->offsetGet(3)->Link);
        $this->assertSame('/search?q=suggestion5+originalWord2', $response->offsetGet(4)->Link);
        $this->assertSame('/search?q=suggestion6+originalWord2', $response->offsetGet(5)->Link);
    }

    private function configureSpellcheckServiceMock(): SpellcheckService
    {
        // Setup AppSearchGateway mock
        $gateway = new class extends AppSearchGateway {
            public function querySuggestion(string $engineName, Schema\QuerySuggestionRequest $request): Response
            {
                $suggestions = [];

                for ($i = 1; $i <= $request->size; $i++) {
                    $suggestions[] = ['suggestion' => 'suggestion' . $i . ' originalWord2'];
                }

                return new Response(new Psr7\Response(200, [
                    'Content-Type' => 'application/json',
                ], json_encode([
                    'results' => [
                        'documents' => $suggestions,
                    ],
                ], JSON_THROW_ON_ERROR)));
            }
        };

        // Setup AppSearchService mock
        $appSearchServiceMock = $this->createMock(AppSearchService::class);
        $appSearchServiceMock
            ->method('environmentizeIndex')
            ->with($this->stringContains('test')) // Engine name from YML config fragment below
            ->willReturn('test'); // 'Full' engine name (same as input in this case)

        /** @var SpellcheckService $service */
        $service = Injector::inst()->get(SpellcheckService::class);
        $service->gateway = $gateway;
        $service->setAppSearchService($appSearchServiceMock);

        Config::modify()
            ->set(SpellcheckService::class, 'query_param', 'q')
            ->set(SpellcheckService::class, 'spellcheck_suggestion_fields', [
                'test' => [
                    'documents' => [
                        'fields' => [
                            'field1',
                            'field2'
                        ],
                    ],
                ],
            ]);

        return $service;
    }
}
