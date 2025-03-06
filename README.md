# silverstripeltd/silverstripe-elasticappsearch

> [!WARNING]  
> Elastic Enterprise Search is now reaching End of Life. Elastic has announced that it will not be supported beyond version 8, and that maintenance support will be halted 21 months after the release of version 9 (the date of which is still TBC, but current thinking is early/mid 2025).\
> https://www.elastic.co/support/eol
>
> For any new projects, we would strongly recommend that you use the [Discoverer](https://github.com/silverstripeltd/silverstripe-discoverer) module over this one. The APIs provided by the Discoverer module should feel familiar and intuitive (if you have used this module in the past). We will also start working on documentation to help explain how you could move existing projects to the Discoverer module.
>
> One of the key reasons why we're recommend the Discoverer module is because it has been designed to be service agnostic - so, even if you can't drop your implementation of Enterprise Search immediately, you will be setting yourself up for an easier migration path when you eventually do need to move to another search service.

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
