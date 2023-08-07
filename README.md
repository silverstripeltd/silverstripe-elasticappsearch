# silverstripeltd/silverstripe-elasticappsearch

![github actions](https://github.com/silverstripeltd/silverstripe-elasticappsearch/actions/workflows/main.yml/badge.svg)

This is a module that provides services to connect a [SilverStripe CMS](https://www.silverstripe.org/) powered website to [Elastic App Search](https://www.elastic.co/enterprise-search).

**Note:** This module only provides the query service that allows you to query Elastic App Search and build search result pages. To index content from a SilverStripe CMS powered website, you should include and configure the [silverstripe/silverstripe-search-service](https://github.com/silverstripe/silverstripe-search-service/) module as well.

## Installing
See [install.md](docs/en/install.md) for installation instructions.

## Using
Out of the box, this module does nothing. For now, you need to build your own search results page. You'll do most of your interaction with the module via the `AppSearchService` and `SearchQuery` classes.

* See [simple-usage.md](docs/en/simple-usage.md) for what a simple search results controller and matching template could look like.
* See [usage.md](docs/en/usage.md) for more detailed usage examples.
  * See [usage-spellcheck.md](docs/en/usage-spellcheck.md) for instructions on using spellcheck / 'Did you mean?' feature.
  * See [usage-multisearch.md](docs/en/usage-multisearch.md) for details on performing multiple searches at the same time.

## Contributing to the module
If you want to contribute to this module, see [contributing.md](docs/en/contributing.md) for information on how to contribute.
