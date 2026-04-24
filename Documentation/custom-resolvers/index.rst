..  _custom-resolvers:

================
Custom Resolvers
================

..  contents::
    :local:

TypoGraph's default resolver dispatches root Query fields by looking them
up in ``typograph.tableMapping``: a root field name is mapped to a
database table, the resolver fetches rows from that table, applies
configured filters and relations, and hands the result to the GraphQL
layer. This works for everything that can be expressed as "list rows
from table *X*".

Some root fields cannot be expressed that way. Examples include computed
aggregates (e.g., pivoted totals across many joined tables), data
assembled from multiple tables under a single logical query, or any
result whose rows are not a straightforward projection of one table. For
these cases TypoGraph exposes a small extension point, the
``CustomResolverInterface``, that lets a consuming extension supply a
dedicated resolver for a given root field.

..  _h2-custom-resolvers-how-it-works:

How It Works
============

Custom resolving depends on two components:

#.  A **CustomResolverInterface** implementation that handles one
    specific root field name. It receives the GraphQL arguments,
    ``ResolveInfo``, and (optionally) the current PSR-7 request, and
    returns the resolved value in whatever shape the schema expects.
#.  A **CustomResolverRegistry** that collects every implementation
    registered in the DI container via a tagged iterator. Its
    ``get(string $fieldName)`` returns the handler for a given field, or
    ``null`` if none is registered.

TypoGraph's ``ResolverService`` consults the registry at the start of
every Query-level resolve call. When a custom resolver matches the
current field name, its return value is used directly and the default
``tableMapping`` dispatch is skipped for that field. When no custom
resolver matches, resolution falls through to the regular tableMapping
path and there is no behaviour change for existing fields.

The ``customResolverRegistry`` constructor argument of ``ResolverService``
is nullable; when absent (e.g., in projects that do not register any
custom resolver), the feature has zero runtime cost.

..  _h2-custom-resolvers-when-to-use:

When to Use a Custom Resolver
=============================

Use a custom resolver when a root field:

*   Aggregates across multiple tables in a way ``tableMapping`` cannot
    express (joins, sums, pivots).
*   Delegates to an existing service whose result shape already matches
    the GraphQL type (no reason to duplicate the logic in the schema).
*   Needs arguments or behaviour that do not fit the standard equality
    filters TypoGraph derives from each root field's arguments (e.g.,
    enum-valued partitioning parameters).

Prefer plain ``tableMapping`` when the field really is "list rows from
one table, optionally filter and paginate". That path is declarative
and gets cursor pagination, ``sortBy``, and field transforms for free.

..  _h2-custom-resolvers-interface:

Implementing the Interface
==========================

``CustomResolverInterface`` has two methods:

..  code-block:: php
    :caption: EXT:typograph/Classes/CustomResolver/CustomResolverInterface.php

    namespace Digicademy\TypoGraph\CustomResolver;

    use GraphQL\Type\Definition\ResolveInfo;
    use Psr\Http\Message\ServerRequestInterface;

    interface CustomResolverInterface
    {
        public function getFieldName(): string;

        public function resolve(
            array $args,
            ResolveInfo $info,
            ?ServerRequestInterface $request
        ): mixed;
    }

``getFieldName()`` must return the exact name of the Query root field
this resolver handles. It is used as the lookup key in the registry, so
a field name collision between two resolvers is a configuration error (see the section on collisions below).

``resolve()`` receives the GraphQL arguments (associative array keyed by
the argument name from the schema), the ``ResolveInfo`` object
(exposing the selection set and return type), and the current PSR-7
request if one is attached to the resolve call.

The return value must match the schema's declared return type. Once it
is returned, TypoGraph's nested resolution logic walks the structure as
usual, so associative arrays and plain objects with matching properties
both work.

..  _h2-custom-resolvers-registration:

Registering a Resolver
======================

Every ``CustomResolverInterface`` implementation is auto-tagged with
``typograph.custom_resolver`` by the ``_instanceof`` rule in
``EXT:typograph/Configuration/Services.yaml``. The ``CustomResolverRegistry``
receives a ``!tagged_iterator`` argument populated from that tag.

Important Symfony DI detail: ``_instanceof`` is *file-local*. A rule
declared in one extension's ``Services.yaml`` does not apply to
services declared in a different extension's ``Services.yaml``.
Consuming extensions must repeat the rule in their own
``Services.yaml`` so their resolver services pick up the tag:

..  code-block:: yaml
    :caption: Configuration/Services.yaml (sitepackage)

    services:
      _defaults:
        autowire: true
        autoconfigure: true
        public: false

      # Repeat TypoGraph's tagging rule so our CustomResolverInterface
      # implementations become part of the CustomResolverRegistry's
      # tagged iterator.
      _instanceof:
        Digicademy\TypoGraph\CustomResolver\CustomResolverInterface:
          tags: ['typograph.custom_resolver']

      Vendor\Sitepackage\:
        resource: '../Classes/*'

No explicit service entry for the resolver class is required — the
``resource:`` glob picks it up, and the ``_instanceof`` rule tags it.

..  _h2-custom-resolvers-schema:

Registering the Field in the Schema
===================================

A custom resolver only dispatches for fields declared in the GraphQL
schema. Add the field to ``Query.graphql`` (or wherever your root type
lives) like any other:

..  code-block:: text
    :caption: Resources/Private/Schemas/Query.graphql

    type Query {
      # …regular tableMapping-backed fields…

      disciplineStats: [DisciplineStat!]!
    }

Types referenced by a custom-resolved field must be declared in a
schema file loaded **before** the one that references them. TypoGraph
concatenates the files listed in ``typograph.schemaFiles`` in order, so
put the types' schema file earlier in the list than ``Query.graphql`` (the ``Stats`` schema in this example):

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      schemaFiles:
        - 'EXT:typograph/Resources/Private/Schemas/Pagination.graphql'
        - 'EXT:sitepackage/Resources/Private/Schemas/Stats.graphql'
        - 'EXT:sitepackage/Resources/Private/Schemas/Query.graphql'
        # …other type files…

The root field does *not* need a ``tableMapping`` entry. Fields handled
by a custom resolver never reach the tableMapping dispatch path.

..  _h2-custom-resolvers-example:

Complete Example
================

A sitepackage wants to expose a ``disciplineStats`` root field that
returns one row per discipline along with the number of experts
attached to it. The count crosses two tables (``discipline`` and
``expert``) joined by a foreign key, so the default ``tableMapping``
dispatch cannot express it: each ``DisciplineStat`` row is a
projection of an aggregate, not of a single database row. A custom
resolver is the right fit.

..  code-block:: text
    :caption: Resources/Private/Schemas/Stats.graphql

    type DisciplineStat {
      discipline: String!
      expertCount: Int!
    }

..  code-block:: text
    :caption: Resources/Private/Schemas/Query.graphql

    type Query {
      # …regular tableMapping-backed fields (experts, disciplines, …)…

      disciplineStats: [DisciplineStat!]!
    }

..  code-block:: php
    :caption: Classes/GraphQL/Resolver/DisciplineStatsResolver.php

    namespace Vendor\Sitepackage\GraphQL\Resolver;

    use Digicademy\TypoGraph\CustomResolver\CustomResolverInterface;
    use GraphQL\Type\Definition\ResolveInfo;
    use Psr\Http\Message\ServerRequestInterface;
    use TYPO3\CMS\Core\Database\ConnectionPool;

    final class DisciplineStatsResolver implements CustomResolverInterface
    {
        public function __construct(
            private readonly ConnectionPool $connectionPool,
        ) {}

        public function getFieldName(): string
        {
            return 'disciplineStats';
        }

        public function resolve(array $args, ResolveInfo $info, ?ServerRequestInterface $request): array
        {
            // One query joining discipline and expert, grouped per
            // discipline — the kind of shape tableMapping cannot express.
            $queryBuilder = $this->connectionPool
                ->getQueryBuilderForTable('tx_myextension_domain_model_discipline');
            $rows = $queryBuilder
                ->select('d.name AS discipline')
                ->addSelectLiteral('COUNT(e.uid) AS expertCount')
                ->from('tx_myextension_domain_model_discipline', 'd')
                ->leftJoin('d', 'tx_myextension_domain_model_expert', 'e', 'e.discipline = d.uid')
                ->groupBy('d.uid', 'd.name')
                ->orderBy('d.name', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            return array_map(
                static fn(array $row): array => [
                    'discipline'  => (string)$row['discipline'],
                    'expertCount' => (int)$row['expertCount'],
                ],
                $rows,
            );
        }
    }

With the ``_instanceof`` rule in the sitepackage's ``Services.yaml``
(shown in the previous section), nothing further is needed. The
resolver is discovered at container build time and dispatched on every
request to ``disciplineStats``.

..  _h2-custom-resolvers-dispatch:

Dispatch Order and Precedence
=============================

At the root level of a Query, ``ResolverService::resolve()`` runs in
this order:

#.  Check the ``CustomResolverRegistry`` for a handler matching the
    current ``ResolveInfo::$fieldName``. If one exists, call it and
    return its value.
#.  Otherwise fall through to ``tableMapping`` dispatch.

Inside the resolved value (nested fields on returned objects), standard
resolution continues to apply: relation fields declared in
``typograph.relations`` still resolve through the default path, and
field transforms declared in ``typograph.fieldTransforms`` still run
against the records returned by your resolver. Your resolver does not
have to re-implement those features; it only has to produce a value
whose shape matches the schema.

..  _h2-custom-resolvers-collisions:

Field Name Uniqueness and Collisions
====================================

The registry stores at most one resolver per field name. If two
services both implement ``CustomResolverInterface`` and return the same
``getFieldName()`` value, the last one registered wins. Symfony DI's
iteration order is deterministic within a single container build but is
not guaranteed across refactors, so colliding field names should be
treated as a configuration error.

If you need to ship multiple alternative resolvers for the same field
(e.g. behind a feature toggle), pick which one to register in your
``Services.yaml`` rather than relying on iteration order.

..  _h2-custom-resolvers-error-behaviour:

Error Behaviour
===============

*   If the current field has no matching custom resolver, dispatch falls
    through silently to ``tableMapping``. Fields that are neither
    mapped to a table nor handled by a custom resolver return
    ``null`` — exactly the default behaviour from before the hook was
    introduced.
*   Exceptions thrown inside ``resolve()`` are not swallowed by the
    registry. They propagate to ``ResolverService::process()`` where
    GraphQL-level errors are logged and surfaced as a structured error
    response. Prefer returning empty arrays or ``null`` for
    domain-level "no data" conditions so they do not pollute the log.
*   Consuming projects that never register a custom resolver are not
    affected by anything in this document; the registry is injected as
    an optional dependency and the hook is a no-op when it is absent.
