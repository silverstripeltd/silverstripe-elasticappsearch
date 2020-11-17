<?php

namespace Madmatt\ElasticAppSearch\Query;

use stdClass;

class SearchQuery
{
    /**
     * @var string
     */
    private $query;

    /**
     * @var stdClass
     */
    private $rawFilters;

    /**
     * @var array
     */
    private $resultFields;

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
     * Returns the string representation of the seach params of this query, ready for sending to Elastic.
     *
     * @return array
     */
    public function getSearchParamsAsArray(): array
    {
        $query = [];

        if (isset($this->rawFilters)) {
            $query['filters'] = $this->rawFilters;
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
}
