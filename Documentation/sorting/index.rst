..  _sorting:

=======
Sorting
=======

..  contents::
    :local:

TypoGraph supports custom sorting of query results via the
``ComparatorInterface``. This allows external packages to inject
locale-aware or domain-specific sorting logic without modifying
TypoGraph itself.

..  _h2-sorting-how-it-works:

How It Works
============

Sorting is based on two components:

#.  A **ComparatorInterface** implementation injected via dependency injection.
    This defines *how* strings are compared (e.g. locale-aware collation).
#.  A **sortBy argument** on the GraphQL query that specifies *which field*
    to sort by.

When both are present, TypoGraph applies the comparator to sort result sets.
When either is absent, results are returned in their default order (UID for
connection queries, database default for plain lists).

..  _h2-sorting-behaviour:

Sorting Behaviour
=================

Sorting behaviour differs depending on the query type:

**Plain list queries** (e.g. ``[Expert]``)
    The full result set is sorted by the comparator before being returned.

**Connection queries** (e.g. ``ExpertConnection``)
    Cursor-based pagination relies on UID ordering for stable pagination across
    pages. The comparator sorts records *within the current page only* after the
    database query and pagination slicing. This means the overall page boundaries
    are determined by UID order, but the records within each page are reordered
    by the comparator.

..  _h2-sorting-sortby-argument:

Using the ``sortBy`` Argument
=============================

Clients specify which field to sort by using the ``sortBy`` argument on a
query field. To enable this, add ``sortBy: String`` to the relevant query
fields in your GraphQL schema:

..  code-block:: text
    :caption: Schema with sortBy argument

    type Query {
      experts(sortBy: String): [Expert]
      expertsConnection(sortBy: String, first: Int, after: String): ExpertConnection
    }

The ``sortBy`` value should be the **camelCase GraphQL field name** (e.g.
``familyName``). TypoGraph converts it to the snake_case database column
name automatically.

..  code-block:: text
    :caption: Query with sort field

    {
      experts(sortBy: "familyName") {
        familyName
        givenName
      }
    }

When ``sortBy`` is omitted, no comparator-based sorting is applied and
results are returned in their default database order.

**Nonexistent fields:** If the ``sortBy`` value refers to a field that does
not exist in the result records, sorting is silently skipped and the original
order is preserved.

..  _h2-sorting-implementing-comparator:

Implementing a Custom Comparator
=================================

TypoGraph ships a ``ComparatorInterface`` with a single method:

..  code-block:: php
    :caption: EXT:typograph/Classes/Comparator/ComparatorInterface.php

    namespace Digicademy\TypoGraph\Comparator;

    interface ComparatorInterface
    {
        public function compare(string $a, string $b): int;
    }

The method follows PHP's standard comparison contract: return a negative
integer if ``$a < $b``, zero if equal, or a positive integer if ``$a > $b``.

To provide a custom comparator, create a class implementing this interface
in your sitepackage or extension:

..  code-block:: php
    :caption: EXT:my_sitepackage/Classes/Comparator/LocalizedComparator.php

    namespace Vendor\MySitepackage\Comparator;

    use Digicademy\TypoGraph\Comparator\ComparatorInterface;

    class LocalizedComparator implements ComparatorInterface
    {
        private static array $collators = [];

        public function compare(string $a, string $b): int
        {
            if (!isset(self::$collators['de_DE'])) {
                self::$collators['de_DE'] = \Collator::create('de_DE');
            }

            return self::$collators['de_DE']->compare($a, $b);
        }
    }

Then register your implementation as the ``ComparatorInterface`` service in
your extension's ``Services.yaml``:

..  code-block:: yaml
    :caption: EXT:my_sitepackage/Configuration/Services.yaml

    services:
      Digicademy\TypoGraph\Comparator\ComparatorInterface:
        class: Vendor\MySitepackage\Comparator\LocalizedComparator

TypoGraph's ``ResolverService`` accepts the comparator as an optional
constructor dependency. When no implementation is registered, the parameter
defaults to ``null`` and no custom sorting is applied.

..  _h2-sorting-example:

Complete Example
================

Given this schema and configuration:

..  code-block:: text
    :caption: Schema

    type Query {
      experts(sortBy: String): [Expert]
      expertsConnection(sortBy: String, first: Int, after: String): ExpertConnection
    }

    type Expert {
      familyName: String
      givenName: String
    }

A plain list query sorted by family name:

..  code-block:: text
    :caption: Query sorted by familyName

    {
      experts(sortBy: "familyName") {
        familyName
        givenName
      }
    }

The same type sorted by a different field:

..  code-block:: text
    :caption: Query sorted by givenName

    {
      experts(sortBy: "givenName") {
        familyName
        givenName
      }
    }

A connection query sorts records within each page:

..  code-block:: text
    :caption: Paginated query with sorting

    {
      expertsConnection(sortBy: "familyName", first: 10) {
        edges {
          node {
            familyName
            givenName
          }
        }
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
