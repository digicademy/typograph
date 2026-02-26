..  _configuration:

=============
Configuration
=============

TypoGraph is configured via the TYPO3 site configuration file
(`config/sites/<site-identifier>/config.yaml`), under a top-level `typograph:`
key.

#.  `schemaFiles`: Define the locations of the GraphQL schema files to use (more
    about schema files below). It makes sense for them to be located somewhere in
    the `Resources/Private` folder of your sitepackage but can really be located
    anywhere in your filesystem as long as TYPO3 can access that location.
#.  `tableMapping`: The TypoGraph extension fetches data for its responses from
    your database tables and therefore needs to know which type gets data from which
    table. This is configured as `<type name>: <table name>` pairs.

Here is an example for an application that uses the types `Taxonomy`,
`Discipline` and `Expert`. For better maintainability, the schema is split into
four files, one for the Query schema and one for each type schema (all of this
could also be the content of one single file). These are listed in
`schemaFiles`. Note that the TypoGraph resolver will concatenate the content of
the schema files in the order they are listed in the configuration.

A GraphQL request will provide one or more type names. The data for these types
can come from any TYPO3 database table, even though you will usually want to
re-use the tables serving your conventional Extbase domain models. For the
TypoGraph resolver to know which table to query for which entity, you need to
provide a list of pairs of entity names and table names, as seen below.

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:

      # Schema files
      schemaFiles:
        - 'EXT:sitepackage/Resources/Private/Schemas/Query.graphql'
        - 'EXT:sitepackage/Resources/Private/Schemas/Taxonomy.graphql'
        - 'EXT:sitepackage/Resources/Private/Schemas/Discipline.graphql'
        - 'EXT:sitepackage/Resources/Private/Schemas/Expert.graphql'

      # Types to tables mapping
      tableMapping:
        taxonomies: tx_myextension_domain_model_taxonomies
        disciplines: tx_myextension_domain_model_disciplines
        experts: tx_myextension_domain_model_experts

However, most of the time you will want the option to query relations between
entries from different tables, or data types. For this to happen, you need to
configure the relations between different tables for TypoGraph.

.. toctree::
   :maxdepth: 5
   :titlesonly:
   :glob:

   relations/index
