<?php

namespace Madmatt\ElasticAppSearch\Tests\Service;

use InvalidArgumentException;
use LogicException;
use Madmatt\ElasticAppSearch\Service\SearchResult;
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
}
