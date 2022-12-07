<?php

namespace SilverStripe\ElasticAppSearch\Query;

use SilverStripe\Core\Config\Configurable;
use stdClass;

class SearchQuery
{
    use Configurable;

    /**
     * @var bool Set to false to disable typo tolerance (which will force exact matches for every individual keyword)
     * @config
     */
    private static $enable_typo_tolerance = true;

    /**
     * @var string
     */
    private $query;

    /**
     * @var stdClass
     */
    private $rawFilters;

    /**
     * @var stdClass
     */
    private $rawFacets;

    /**
     * @var array
     */
    private $resultFields;

    /**
     * @var array
     */
    private $sort;

    /**
     * @var array
     */
    private $searchFields;

    /**
     * @var int
     */
    private $pageSize;

    /**
     * @var int
     */
    private $pageNum;

    /**
     * Set the query string that all documents must match in order to be returned. This can be set to an empty string to
     * return all documents. This can be useful for example when you want to return all documents that match a
     * particular filter.
     *
     * @param string $query
     * @return $this
     */
    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    /**
     * @return string The query string set on this query
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * If typo tolerance is disabled, then we sanitise and transform the keywords searched for into an AND search by
     * default. If the user has already used some Lucene syntax, then we assume they know what they are doing. This will
     * (for example) prefix every keyword with '+', and remove any keywords that are considered stopwords by
     * Elasticsearch (e.g. 'and', 'or').
     *
     * @see https://swiftype.com/documentation/app-search/api/search#search-queries
     *
     * @return string The query string that Elastic should be sent.
     */
    public function getQueryForElastic(): string
    {
        // If typo tolerance is enabled, just return the original query as-is with no modification
        if ($this->config()->enable_typo_tolerance) {
            return $this->query;
        }

        // Typo tolerance is disabled, so we need to adjust the query
        $keywordString = $this->query;

        if (empty($keywordString)) {
            return '';
        }

        $keywordString = rtrim($keywordString);
        $keywordStringWithANDs = '';

        // Don't hack it together if they are doing advanced manoeuvres already
        $luceneOperators = ['AND', 'OR', 'NOT', '"'];
        foreach ($luceneOperators as $luceneOperator) {
            if (strpos($keywordString, $luceneOperator) !== false) {
                return $keywordString;
            }
        }

        // Source: https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-stop-tokenfilter.html#analysis-stop-tokenfilter-stop-words-by-lang
        // @todo: Assumption here that stopwords are all in English
        $englishStopWords = [
            "a", "an", "and", "are", "as", "at", "be", "but", "by", "for", "if", "in", "into", "is",
            "it", "no", "not", "of", "on", "or", "such", "that", "the", "their", "then", "there",
            "these", "they", "this", "to", "was", "will", "with"
        ];

        foreach (explode(' ', $keywordString) as $keyword) {
            $prefix = '+';

            // Check again for individual word manipulations
            if ($keyword[0] === '+' || $keyword[0] === '-') {
                $prefix = '';
            }

            // Requiring a stopword to be present _always_ returns zero results
            // Remove punctuation so that we catch 'No.' being used for number, for example
            if (in_array(preg_replace("/[^a-z0-9]/", '', strtolower($keyword)), $englishStopWords)) {
                $prefix = '';
            }

            // Check there's at least a letter or a number, otherwise requiring just a punctuation mark (e.g. |) breaks
            if (preg_match('/[A-Za-z]/', $keyword) === 0 && preg_match('/[0-9]/', $keyword) === 0) {
                $prefix = '';
            }

            $keywordStringWithANDs .= "{$prefix}{$keyword} ";
        }

        return rtrim($keywordStringWithANDs, ' ');
    }

    /**
     * Sets the raw 'filters' attribute for filtering results. For more information on how to create filters, consult
     * the Elastic App Search documentation: https://www.elastic.co/guide/en/app-search/current/filters.html
     *
     * @todo It would be nice to allow for PHP-built filters (e.g. built from objects rather than needing the developer
     * to figure out how Elastic's 'filters' key works) but that's a feature for a later date.
     *
     * @param stdClass $filters
     * @return $this
     */
    public function addRawFilters(stdClass $filters): self
    {
        $this->rawFilters = $filters;
        return $this;
    }

    /**
     * Sets the raw 'facets' attribute for returning metadata related to the search query. See the docs for help:
     * https://swiftype.com/documentation/app-search/api/search/facets
     *
     *
     * @param stdClass $facets
     * @return $this
     */

    public function addRawFacets(stdClass $facets): self
    {
        $this->rawFacets = $facets;
        return $this;
    }

    /**
     * Add a sort method to the list, see:
     * https://www.elastic.co/guide/en/app-search/current/sort.html
     *
     * @param string $fieldName
     * @param string $direction valid values are asc/desc
     * @return $this
     */
    public function addSort(string $fieldName, string $direction = 'asc') : self
    {
        if (!isset($this->sort)) {
            $this->sort = [];
        }

        $this->sort[] = [$fieldName => strtolower($direction)];

        return $this;
    }

    /**
     * Adds multiple sort methods at once
     *
     * @param array $sortMethods [$fieldname => $direction]
     * @return $this
     */
    public function addSorts(array $sortMethods): self
    {
        foreach ($sortMethods as $fieldName => $direction) {
            $this->addSort($fieldName, $direction);
        }

        return $this;
    }

    /**
     * @param string $field
     * @param string $type
     * @param int $size
     * @return $this
     */
    public function addResultField(string $field, string $type = 'raw', int $size = 0)
    {
        if (!isset($this->resultFields)) {
            $this->resultFields = [];
        }

        $this->resultFields[$field] = [
            'type' => $type,
            'size' => $size
        ];

        return $this;
    }

    public function addSearchField(string $field, int $weight = 0)
    {
        if (!isset($this->searchFields)) {
            $this->searchFields = [];
        }

        $this->searchFields[$field] = $weight;
        return $this;
    }

    /**
     * Returns the string representation of the search params of this query, ready for sending to Elastic.
     *
     * @return array
     */
    public function getSearchParamsAsArray(): array
    {
        $query = [];

        if (isset($this->rawFilters)) {
            $query['filters'] = $this->rawFilters;
        }

        if (isset($this->rawFacets)) {
            $query['facets'] = $this->rawFacets;
        }

        if (isset($this->sort)) {
            $query['sort'] = $this->getSortForArray();
        }

        if (isset($this->resultFields)) {
            $query['result_fields'] = $this->getResultFieldsForArray();
        }

        if (isset($this->searchFields)) {
            $query['search_fields'] = $this->getSearchFieldsForArray();
        }

        if (isset($this->pageNum) && isset ($this->pageSize)) {
            $query['page'] = $this->getPaginationForArray();
        }

        return $query;
    }

    public function hasPagination()
    {
        return isset($this->pageNum) || isset($this->pageSize);
    }

    public function setPagination(int $pageSize, int $pageNum)
    {
        $this->pageSize = $pageSize;
        $this->pageNum = $pageNum;
    }

    private function getPaginationForArray(): ?stdClass
    {
        $page = null;

        if (isset($this->pageNum) && isset ($this->pageSize)) {
            $page = new stdClass;
            $page->size = $this->pageSize;
            $page->current = $this->pageNum;
        }

        return $page;
    }

    private function getResultFieldsForArray(): ?stdClass
    {
        $resultFields = null;

        if (isset($this->resultFields)) {
            $resultFields = new stdClass;

            // Ensure we include the default fields so we can map these documents back to Silverstripe DataObjects
            $resultFields->record_base_class = new stdClass;
            $resultFields->record_base_class->raw = new stdClass;
            $resultFields->record_id = $resultFields->record_base_class;

            foreach ($this->resultFields as $field => $options) {
                $type = $options['type'];
                $size = $options['size'];

                $resultFields->$field = new stdClass;
                $resultFields->$field->$type = new stdClass;

                if ($size) {
                    $resultFields->$field->$type->size = $size;
                }
            }
        }

        return $resultFields;
    }

    private function getSearchFieldsForArray(): ?stdClass
    {
        $searchFields = null;

        if (isset($this->searchFields)) {
            $searchFields = new stdClass;

            foreach ($this->searchFields as $field => $weight) {
                $searchFields->$field = new stdClass;

                // Add optional weight but only if it's specified and valid
                if (is_numeric($weight) && $weight > 0) {
                    $searchFields->$field->weight = $weight;
                }
            }
        }

        return $searchFields;
    }

    public function getSortForArray(): ?array
    {
        $sort = null;

        if (!isset($this->sort)) {
            return null;
        }

        // finally sort by score as a fallback
        if (empty(array_column($this->sort, '_score'))) {
            $this->sort[] = ['_score' => 'desc'];
        }

        return $this->sort;
    }
}
