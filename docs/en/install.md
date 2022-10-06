# Installing the module
<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->
**Table of Contents**

- [Install steps](#install-steps)
- [Next steps](#next-steps)

<!-- END doctoc generated TOC please keep comment here to allow auto update -->

# Install steps
Add the following to your composer.json:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:silverstripeltd/silverstripe-elasticappsearch.git"
        }
    ]
}
```

You will need to add the following environment variables at a minimium:
* `ENTERPRISE_SEARCH_ENDPOINT`: This should already be defined if you are using the [silverstripe/silverstripe-search-service](https://github.com/silverstripe/silverstripe-search-service/) module. This is the API endpoint for your Elastic App Search interface.
* `ENTERPRISE_SEARCH_API_SEARCH_KEY`: This is the public search API key that you've configured in the Credentials section of Elastic App Search. It should begin with `search-`.

Then run the following:
```shell script
cd /path/to/your/project-root
composer require silverstripeltd/silverstripe-elasticappsearch
vendor/bin/sake dev/build flush=1
``` 
You may also need to visit `https://your-website.example.com/?flush=1` if you have separate cache sources for cli and web users.

# Next steps
* [View sample-usage.md for a simple example usage](simple-usage.md)
    * [View usage.md for how to use other features](usage.md)
* [View configuration.md for additional configuration options](configuration.md)
