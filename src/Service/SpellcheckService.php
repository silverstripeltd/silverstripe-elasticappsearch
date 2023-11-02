<?php

namespace SilverStripe\ElasticAppSearch\Service;

use Elastic\EnterpriseSearch\AppSearch\Schema\QuerySuggestionRequest;
use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ElasticAppSearch\Gateway\AppSearchGateway;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;

class SpellcheckService
{
    use Injectable;
    use Extensible;
    use Configurable;

    /**
     * The gateway to be used when querying Elastic App Search.
     */
    public ?AppSearchGateway $gateway = null;

    /**
     * The AppSearchService class used to environment-ize engine / index names.
     */
    public ?AppSearchService $appSearchService = null;

    /**
     * The monolog interface used to handle errors and warnings during searching.
     */
    public ?LoggerInterface $logger = null;

    /**
     * The maximum number of spelling suggestions to return. The code will still do all calculations, so
     * lowering this number will not make the website faster, but it can be used to ensure that you only get a small
     * number of actual suggestions (so you don't have to do this manually in your template).
     */
    private static int $max_spellcheck_suggestions = 2;

    /**
     * The HTTP query param that needs to be changed whenever a spelling suggestion is suggested.
     */
    private static string $query_param = 'q';

    /**
     * The suggestion fields to query for each engine/index. See the README for more information on the
     * required structure of this array.
     */
    private static array $spellcheck_suggestion_fields = [];

    private static array $dependencies = [
        'gateway' => '%$' . AppSearchGateway::class,
        'appSearchService' => '%$' . AppSearchService::class,
        'logger' => '%$' . LoggerInterface::class . '.errorhandler',
    ];

    /**
     * Given a SearchQuery with keywords, split the keyword up by token (word) and query each word for spelling
     * suggestions individually, then return an ArrayList where each item in the list is an ArrayData containing the
     * following properties:
     *   - Link: A fully-qualified URL to the current page with the corrected word replaced in the URL string
     *   - Suggestion: The new complete phrase (e.g. searching for "chanel partner", Suggestion = "channel partner").
     *
     * @param SearchQuery $query The search query that was used to query Elastic App Search
     * @param string $engineName The engine name to perform the internal lookup on
     * @param HTTPRequest $request Used as a base to manipulate the URL in order to create the 'Link' values
     *
     * @return null|ArrayList An ArrayList containing ArrayData elements for each suggestion, or null on error
     */
    public function getSpellingSuggestions(SearchQuery $query, string $engineName, HTTPRequest $request): ?ArrayList
    {
        try {
            $suggestRequest = $this->getRequest($query, $engineName);

            // Attempt to make a connection to Elasticsearch
            $suggestions = $this->gateway->querySuggestion($engineName, $suggestRequest);
            $extractedSuggestions = $this->extractPotentialSuggestions($suggestions->asArray());

            // Now that we've selected some suggestions, create the ArrayList
            $list = ArrayList::create();

            foreach ($extractedSuggestions as $suggestion) {
                $data = ArrayData::create([
                    'Suggestion' => $suggestion,
                    'Link' => HTTP::setGetVar($this->config()->get('query_param'), $suggestion, $request->getURL(true)),
                ]);

                $list->push($data);
            }

            return $list;
        } catch (Exception $e) {
            // Soft-fail - log the error but do not fail the entire request
            $this->logger->error(
                sprintf("Couldn't find spelling suggestions for %s: %s", $query->getQuery(), $e->getMessage())
            );
        }

        return null;
    }

    public function setAppSearchService(AppSearchService $service): self
    {
        $this->appSearchService = $service;

        return $this;
    }

    protected function getRequest(SearchQuery $query, string $engineName): QuerySuggestionRequest
    {
        // Number of suggestions to return, default to 10 in Elastic if not defined
        $size = (int) $this->config()->get('max_spellcheck_suggestions');

        // Get suggestion fields to query Elastic on
        $suggestionConfig = (array) $this->config()->get('spellcheck_suggestion_fields');
        $esSuggestionFields = [];

        // Loop over each suggestion field to find the one that matches the requested engine name
        foreach ($suggestionConfig as $indexName => $suggestionData) {
            if ($this->appSearchService->environmentizeIndex($indexName) == $engineName) {
                // We have the correct engine, so we want to extract the necessary fields and internal name
                $esSuggestionFields = $suggestionData;
            }
        }

        // Setup request for query suggestion. Only query is required.
        $suggestRequest = new QuerySuggestionRequest();
        $suggestRequest->query = mb_strtolower($query->getQuery());

        // Set max suggestion to return if defined.
        if ($size) {
            $suggestRequest->size = $size;
        }

        // Set the documents key within the types parameter to look for suggestions within certain text fields.
        // If not defined, it is all text fields.
        if (count($esSuggestionFields)) {
            $suggestRequest->types = (object)$esSuggestionFields;
        }

        return $suggestRequest;
    }

    /**
     * This method extracts a flat list of spelling suggestions.
     *
     * @param array $suggestions The full API response from App Search
     */
    protected function extractPotentialSuggestions(array $suggestions): array
    {
        // Ensure suggestions exist in the response
        if (!isset($suggestions['results']['documents']) || !count($suggestions['results']['documents'])) {
            return [];
        }

        return array_column($suggestions['results']['documents'], 'suggestion');
    }
}
