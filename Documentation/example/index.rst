..  _example:

=============
Example Setup
=============

For testing the TypoGraph extension in your system without hooking up any of
your actual data, you can set up some example tables and seed them with a number
of related entries:

..  code-block:: shell
    :caption: Example setup command

    vendor/bin/typo3 typograph:example

The command accepts two parameters:

* `--site`: If you install the extension in a multisite instance, you can
  specify for which site the GraphQL endpoint is configures. Defaults to `main` if
  omitted.
* `--no-seed`: If you only want the example schemas and tables rolled out but
  fill them with data yourself, you can skip the entry seeding with this parameter.

The command creates the following tables and (unless you skip the seeding part)
fills them with a handful of entries:

* `tx_typograph_example_taxonomies`
* `tx_typograph_example_disciplines`
* `tx_typograph_example_experts`
* `tx_typograph_example_experts_disciplines_mm`

It will also create the following schema files withing the TypoGraph package
folder:

* `Resources\Private\Schemas\ExampleQuery.graphql`
* `Resources\Private\Schemas\ExampleTypes.graphql`

Finally, the command also adds the necessary configuration entries in
`config/sites/<site-name>/config.yaml`.

Once the commmand has finished, you need to manually clear the TYPO3 and PHP
caches via the TYPO3 Backend Maintenance Tool. This is recommended because due
to your setup (e.g., if you are working with PHP-FPM), OPcache for worker
threads will not be cleared unless done via the web interface (see, e.g.,
`this discussion on TYPO3 cache flushing via the command line <https://github.com/TYPO3-Console/TYPO3-Console/issues/983#issuecomment-824619309>`_).

Now you can query the GraphQL endpoint available at the path `/graphql` for the
example data. You can also run the Codeception API tests from `tests\Api`
against the endpoint, if needed.


