# TypoGraph

TYPO3 extension for providing access to TYPO3 database table data via a GraphQL endpoint. Under the bonnet, it uses https://github.com/webonyx/graphql-php.

If you want to use this extension, you need to be sufficiently familiar with the basic concepts of GraphQL. You also need to know how to create GraphQL schemas. If you want to learn more about GraphQL or need a refresher, read the docs at https://graphql.org/learn/.

⚠️ This extension is still in development stage and while it currently works for us, it may break in cases we haven not tested yet. Use with care!

## Configuration

The TypoGraph extension requires minimal TypoScript configuration.

1. `schemaFiles`: Define the locations of the GraphQL schema files to use (more about schema files below). It makes sense for them to be located somewhere in the `Resources\Private` folder of your sitepackage but can really be located anywhere in your filesystem as long as TYPO3 can access that location.
2. `tableMapping`: The TypoGraph extension fetched data for its responses from your database table and therefore needs to know which type gets data from which table. This can be mapped as `{type name} = {table name}` pair.

Here is an example for an application that uses the types `Foo` and `Bar`. For better maintainability, the schema is split into three files, one for the Query schema and one for each type schema (all of this could also be the content of one single file, of course). These are listed in `schemaFiles`. Note that the TypoGraph resolver will concatenate the content of the schema files in the order they are listed in the configuration.

A GraphQL request will provide one or more type names. The data for these types can come from any TYPO3 database table, even though it's likely that you will want to re-use tables serving conventional Extbase domain models. For the TypoGraph resolver to know which table to query for which entity, you need to provide a list of pairs of entity names and table names, as seen below.

```
# TypoGraph extension configuration
plugin.tx_typograph.settings {

  # Schema files
  schemaFiles {
    0 = EXT:sitepackage/Resources/Private/Schemas/Query.graphql
    1 = EXT:sitepackage/Resources/Private/Schemas/Foo.graphql
    2 = EXT:sitepackage/Resources/Private/Schemas/Bar.graphql
  }

  # Types to tables mapping
  tableMapping {
    foo = tx_myextension_domain_model_foo
    bar = tx_myextension_domain_model_bar
  }
}
```

## AI Disclaimer

The initial basic extension design has been completely done by humans. However, developers of this extension use Claude Code (Sonnet 4.5) to streamline routine tasks, upgrades, improve code quality etc. All changes depending on AI (as far as we are aware) are confirmed by a qualified human software developer before merged into the `main` branch. For transparency, all our prompts are documented in the `prompts.md` file.
