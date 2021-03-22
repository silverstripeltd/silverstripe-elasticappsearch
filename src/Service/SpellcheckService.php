<?php

namespace SilverStripe\ElasticAppSearch\Service;

use Elasticsearch\ClientBuilder;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ElasticAppSearch\Gateway\ElasticsearchGateway;
use SilverStripe\ElasticAppSearch\Query\SearchQuery;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;

class SpellcheckService
{
    use Injectable;
    use Extensible;
    use Configurable;

    /**
     * @var int The maximum number of spelling suggestions to return. The code will still do all calculations, so
     * lowering this number will not make the website faster, but it can be used to ensure that you only get a small
     * number of actual suggestions (so you don't have to do this manually in your template).
     */
    private static $max_spellcheck_suggestions = 2;

    /**
     * @var string The HTTP query param that needs to be changed whenever a spelling suggestion is suggested
     */
    private static $query_param = null;

    /**
     * @var array The suggestion fields to query for each engine/index. See the README for more information on the
     * required structure of this array.
     */
    private static $spellcheck_suggestion_fields = [];

    private static $dependencies = [
        'elasticsearchGateway' => '%$' . ElasticsearchGateway::class,
        'appSearchService' => '%$' . AppSearchService::class,
        'logger' => '%$' . LoggerInterface::class . '.errorhandler',
    ];

    /**
     * @var ElasticsearchGateway The Elasticsearch gateway to be used when querying the underlying Elasticsearch store
     */
    public $elasticsearchGateway;

    /**
     * @var AppSearchService The AppSearchService class used to environment-ize engine / index names
     */
    public $appSearchService;

    /**
     * @var LoggerInterface The monolog interface used to handle errors and warnings during searching
     */
    public $logger;

    /**
     * Given a SearchQuery with keywords, split the keyword up by token (word) and query each word for spelling
     * suggestions individually, then return an ArrayList where each item in the list is an ArrayData containing the
     * following properties:
     *   - Link: A fully-qualified URL to the current page with the corrected word replaced in the URL string
     *   - Suggestion: The new complete phrase (e.g. searching for "chanel partner", Suggestion = "channel partner")
     *
     * @param SearchQuery $query The search query that was used to query Elastic App Search
     * @param string $engineName The engine name to perform the internal lookup on
     * @param HTTPRequest $request Used as a base to manipulate the URL in order to create the 'Link' values
     * @return ArrayList|null An ArrayList containing ArrayData elements for each suggestion, or null on error
     */
    public function getSpellingSuggestions(SearchQuery $query, string $engineName, HTTPRequest $request): ?ArrayList
    {
        try {
            // Ensure we can extract spelling suggestions from Elasticsearch directly
            $this->ensureSpellcheckAvailable($engineName);

            // Get suggestion fields to query Elastic on
            $suggestionConfig = $this->config()->spellcheck_suggestion_fields;
            $esIndexName = null;
            $esSuggestionFields = [];

            // Loop over each suggestion field to find the one that matches the requested engine name
            foreach ($suggestionConfig as $indexName => $suggestionData) {
                if ($this->appSearchService->environmentizeIndex($indexName) == $engineName) {
                    // We have the correct engine, so we want to extract the necessary fields and internal name
                    $esIndexName = $suggestionData['internal_index_name'];
                    $esSuggestionFields = $suggestionData['fields'];
                }
            }

            // Construct a search query for Elasticsearch
            $searchParams = [
                'index' => $esIndexName,
                'body' => [
                    'suggest' => [
                        'text' => $query->getQuery(),
                    ]
                ]
            ];

            // Loop over each suggestion field and add it to the $searchParams array
            foreach ($esSuggestionFields as $fieldName) {
                $searchParams['body']['suggest'][$fieldName] = [
                    'term' => [
                        'field' => $fieldName
                    ]
                ];
            }

            // Attempt to make a connection to Elasticsearch
            $suggestions = $this->elasticsearchGateway->search($searchParams);
            $extractedSuggestions = $this->extractPotentialSuggestions($suggestions);

            // Now we have a sorted, flattened list of potential suggestions, and we just need to pick some
            $selectedSuggestions = $this->selectSuggestions($extractedSuggestions, $query);

            // Now that we've selected some suggestions, create the ArrayList
            $list = ArrayList::create();

            foreach ($selectedSuggestions as $suggestion) {
                $data = ArrayData::create([
                    'Suggestion' => $suggestion,
                    'Link' => HTTP::setGetVar($this->config()->query_param, $suggestion, $request->getURL(true))
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

    /**
     * This method extracts a unique list of spelling suggestions for each original word in the search query.
     *
     * The returned array will look something like this:
     * [
     *     'original_word_1' => [
     *         'suggestion_1',
     *         'suggestion_2',
     *         'suggestion_3'
     *     ],
     *     'original_word_2' => [
     *         'original_word_2'
     *     ]
     * ]
     *
     * The original words will be ordered based on their original position in the original query string, so you can put
     * together a search suggestion by combining 'suggestion_1 + original_word_2' in this case.
     *
     * Note that if the original word is deemed to be spelt properly, no suggestions will be included for it, therefore
     * we insert the original word as the most likely result instead, so that later generation of suggestions is easier.
     *
     * @param array $suggestions The full API response from Elasticsearch (including the 'suggest' key).
     * @return array
     */
    protected function extractPotentialSuggestions(array $suggestions): array
    {
        // Ensure suggestions exist in the response
        if (!array_key_exists('suggest', $suggestions)) {
            return [];
        }

        $suggestions = $this->flattenSuggestions($suggestions);
        return $suggestions;
    }

    /**
     * Flattens a full Elasticsearch response containing the 'suggest' key down into a single array of suggestions. This
     * combines the individual field results into a single list for each word. If the same suggested word occurs in
     * multiple lists, then the one with the highest score will win. Once all suggested words are found, we re-sort the
     * list based on score (so that the first suggested word is the one with the highest score value).
     *
     * @param array $suggestions
     * @return array
     */
    protected function flattenSuggestions(array $suggestions): array
    {
        $potentialSuggestions = [];

        if (!array_key_exists('suggest', $suggestions)) {
            return [];
        }

        // $words is an array containing multiple arrays - one array for each word in the original search query
        foreach ($suggestions['suggest'] as $fieldName => $words) {
            foreach ($words as $word) {
                // First ensure we have a valid word suggestion, and skip if we don't have all the data we need
                if (!isset($word['text']) || !isset($word['options']) || !is_array($word['options'])) {
                    continue;
                }

                // If we don't already have an entry in $potentialSuggestions for the original word, create one
                if (!isset($potentialSuggestions[$word['text']])) {
                    $potentialSuggestions[$word['text']] = [];
                }

                // If there isn't a suggested word for this original word, make sure we insert the original word as a
                // suggestion with a score of 1 provided it's not already there. This makes subsequent processing easier.
                if (sizeof($word['options']) <= 0) {
                    // Make sure it's not already in the suggestion list
                    $inserted = false;

                    foreach ($potentialSuggestions[$word['text']] as $existingOption) {
                        if ($existingOption['text'] === $word['text']) {
                            $inserted = true;
                            break;
                        }
                    }

                    if (!$inserted) {
                        $potentialSuggestions[$word['text']][] = [
                            'text' => $word['text'],
                            'score' => 1
                        ];
                    }
                } else {
                    // Otherwise, there's at least one suggested word to replace the original word. Check through all
                    // existing suggestions to see if this suggestion has already been made. If it has, check the score
                    // and keep only the higher score. If it hasn't, then insert it as another suggestion.
                    foreach ($word['options'] as $option) {
                        $inserted = false;

                        // Loop over existing potential suggestions to find a match
                        foreach ($potentialSuggestions[$word['text']] as $existingOption) {
                            if ($existingOption['text'] === $option['text']) {
                                // We already have this word as a suggestion, so compare and overwrite if it's higher
                                if ($option['score'] > $existingOption['score']) {
                                    $existingOption['score'] = $option['score'];
                                }

                                // Mark as inserted so we don't insert again
                                $inserted = true;
                                break;
                            }
                        }

                        if (!$inserted) {
                            // We haven't see this suggestion before, so add it to the end of the list (we'll sort based
                            // on score later)
                            $potentialSuggestions[$word['text']][] = [
                                'text' => $option['text'],
                                'score' => $option['score']
                            ];
                        }
                    }
                }
            }
        }

        // Finally, sort the suggestions for each word based on the suggestion scores
        $potentialSuggestions = $this->sortSuggestions($potentialSuggestions);
        return $potentialSuggestions;
    }

    /**
     * Takes a flattened array of spelling suggestions for each original word in the search query and sorts the
     * suggestions based on their internal Elasticsearch score.
     *
     * Scores are from 0 to 1, with 1 being the best suggestion.
     *
     * @param array $suggestions An unsorted multi-dimensional array of word suggestions
     * @return array A sorted array
     */
    protected function sortSuggestions(array $suggestions): array
    {
        $sortFunc = function(array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return 0;
            } elseif ($a['score'] < $b['score']) {
                return 1;
            } else {
                return -1;
            }
        };

        foreach ($suggestions as $originalWord => $list) {
            usort($list, $sortFunc);
            $suggestions[$originalWord] = $list;
        }

        return $suggestions;
    }

    /**
     * From the flattened, sorted list of suggestions, select up to the maximum number of suggestions and return then.
     *
     * @param array $extractedSuggestions
     * @param SearchQuery $query
     * @return array A single-dimensional array of full query strings, with the original word swapped for the suggestion
     */
    protected function selectSuggestions(array $extractedSuggestions, SearchQuery $query): array
    {
        $maxNumSuggestions = $this->config()->max_spellcheck_suggestions;
        $selectedSuggestions = [];
        $originalQuery = $query->getQuery();

        foreach ($extractedSuggestions as $originalWord => $replacementSuggestions) {
            foreach ($replacementSuggestions as $replacement) {
                $newSuggestion = str_replace($originalWord, $replacement['text'], $originalQuery);

                // Make sure we don't just suggest the same query the user just entered
                if ($newSuggestion != $originalQuery) {
                    $selectedSuggestions[] = $newSuggestion;
                }

                // If we've got enough suggestions now, break out of both inner and outer loops to return the selections
                if (sizeof($selectedSuggestions) >= $maxNumSuggestions) {
                    break 2;
                }
            }
        }

        return $selectedSuggestions;
    }

    /**
     * Ensures Elasticsearch should be reachable and ready for querying, by checking that:
     * - All necessary environment variables are set
     * - That the elasticsearch/elasticsearch Composer library is installed
     * - That we have at least some Elasticsearch fields to ask for suggestions within
     *
     * This does not attempt to make a connection to Elasticsearch at all.
     *
     * @param string $requestedEngineName The Elastic App Search engine name that we expect to find mapped
     * @throws LogicException When any of the above conditions are not satisfied
     */
    private function ensureSpellcheckAvailable($requestedEngineName)
    {
        if (!Environment::getEnv('ELASTICSEARCH_CLOUD_ID')) {
            throw new LogicException('Required environment variable ELASTICSEARCH_CLOUD_ID not configured.');
        }

        if (strpos(Environment::getEnv('ELASTICSEARCH_CLOUD_ID'), ':') === false) {
            throw new LogicException('ELASTICSEARCH_CLOUD_ID env var is invalid - it must contain a colon (:).');
        }

        if (!Environment::getEnv('ELASTICSEARCH_API_KEY_ID')) {
            throw new LogicException('Required environment variable ELASTICSEARCH_API_KEY_ID not configured.');
        }

        if (!Environment::getEnv('ELASTICSEARCH_API_KEY')) {
            throw new LogicException('Required environment variable ELASTICSEARCH_API_KEY not configured.');
        }

        if (!class_exists(ClientBuilder::class)) {
            throw new LogicException('The elasticsearch/elasticsearch Composer library is not installed.');
        }

        // Ensure we have a query param to adjust
        if (!$this->config()->query_param) {
            throw new LogicException('You need to set query_param in YML configuration.');
        }

        // Ensure we have at least one field in Elasticsearch to check for suggestions within
        $suggestionFields = $this->config()->spellcheck_suggestion_fields;
        $engineNameFound = false;

        if (!is_array($suggestionFields) || sizeof($suggestionFields) == 0) {
            throw new LogicException('You must define at least one field in YML for spellcheck_suggestion_fields.');
        }

        foreach ($suggestionFields as $engineName => $data) {
            if (!array_key_exists('internal_index_name', $data)) {
                throw new LogicException(sprintf('You must define internal_index_name for the engine %s', $engineName));
            }

            if (!array_key_exists('fields', $data) || !is_array($data['fields'])) {
                throw new LogicException(sprintf('You must define the fields array for the engine %s', $engineName));
            }

            // Follow the same 'environmentalize' pattern that the silverstripe-search-service module does to make sure
            // the engine name matches
            $engineName = $this->appSearchService->environmentizeIndex($engineName);

            if ($engineName === $requestedEngineName) {
                $engineNameFound = true;
            }
        }

        // If we haven't found the engine name, then we know that the engine we are trying to query doesn't exist in our
        // loopup table, so we don't know what the internal_index_name is - this is a hard failure
        if (!$engineNameFound) {
            $error = sprintf(
                'You requested spellcheck suggestions from the %s engine but have not included that in the'
                . ' configuration for spellcheck_suggestion_fields',
                $requestedEngineName
            );

            throw new LogicException($error);
        }
    }
}
