..  _pagination:

==========
Pagination
==========

..  contents::
    :local:

TypoGraph supports cursor-based pagination following the
`GraphQL Cursor Connections Specification <https://relay.dev/graphql/connections.htm>`_.

..  _h2-pagination-how-it-works:

How It Works
============

When a root query field returns a **Connection type** (a type whose name ends
with `Connection`), TypoGraph automatically applies cursor-based pagination. If
the return type is a plain list (e.g. `[Discipline]`), the resolver behaves as
before and no pagination is applied.

Cursors are opaque, base64-encoded strings. Clients should treat them as opaque
tokens and never parse or construct them manually.

..  _h2-pagination-schema-setup:

Schema Setup
============

To enable pagination for a type, you need to define three types in your GraphQL
schema and include the shared `Pagination.graphql` schema file provided by the
TypoGraph extension.

..  _h3-pagination-schema-setup-include-pageinfo-type:

1. Include the shared PageInfo type
-----------------------------------

The `Pagination.graphql` file ships with TypoGraph and defines:

..  code-block:: text
    :caption: PageInfo schema

    type PageInfo {
      hasNextPage: Boolean!
      hasPreviousPage: Boolean!
      startCursor: String
      endCursor: String
    }

Add it as the first entry in `schemaFiles` in the site configuration:

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      schemaFiles:
        - 'EXT:typograph/Resources/Private/Schemas/Pagination.graphql'
        - 'EXT:sitepackage/Resources/Private/Schemas/Query.graphql'
        # ...

..  _h3-pagination-schema-setup-define-types:

2. Define Connection and Edge types for your entity
---------------------------------------------------

..  code-block:: text
    :caption: Example pagination schema for the Expert type

    type Expert {
      familyName: String
      givenName: String
    }

    type ExpertConnection {
      edges: [ExpertEdge!]!
      pageInfo: PageInfo!
      totalCount: Int!
    }

    type ExpertEdge {
      cursor: String!
      node: Expert!
    }

..  _h3-pagination-schema-setup-define-fields:

3. Define both a plain-list field and a Connection field in the Query type
--------------------------------------------------------------------------

The recommended convention is to expose two root fields per entity: a plain-list
field for unpaginated access and a `*Connection` field for paginated access.
This preserves backwards compatibility for clients that do not need pagination.

..  code-block:: text
    :caption: Query schema for plain and paginated Expert type

    type Query {
      experts(familyName: String): [Expert]
      expertsConnection(familyName: String, first: Int, after: String): ExpertConnection
    }

The `first` and `after` arguments on the Connection field are recognised as
pagination arguments and are **not** turned into `WHERE` conditions. All other
arguments (like `familyName`) continue to work as filters on both fields.

Both field names must be present in `tableMapping`.

..  _h2-pagination-configuration:

Pagination Configuration
========================

Configure default and maximum page sizes in the site configuration:

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      pagination:
        defaultLimit: 20
        maxLimit: 100

..  list-table:: Configuration Fields
    :widths: 22 * *
    :header-rows: 1

    * - Setting
      - Default
      - Description
    * - `defaultLimit`
      - `20`
      - Page size when `first` is not provided
    * - `maxLimit`
      - `100`
      - Upper bound for `first`; requests above this are clamped

..  _h2-pagination-query:

Querying with Pagination
========================

Using the pagination arguments `first` and `after`, or `last` and `before`
allows for fine-grained pagination that can be combined the the usual filter
arguments.

..  code-block:: text
    :caption: First page

    {
      expertsConnection(first: 10) {
        edges {
          cursor
          node {
            familyName
            givenName
          }
        }
        pageInfo {
          hasNextPage
          endCursor
        }
        totalCount
      }
    }

..  code-block:: text
    :caption: Next page

    {
      expertsConnection(first: 10, after: "Y3Vyc29yOjQy") {
        edges {
          cursor
          node {
            familyName
            givenName
          }
        }
        pageInfo {
          hasNextPage
          hasPreviousPage
          endCursor
        }
      }
    }

..  code-block:: text
    :caption: Combining filters with pagination

    {
      expertsConnection(familyName: "Smith", first: 5) {
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
        totalCount
      }
    }

..  _h2-pagination-response-structure:

Response Structure
==================

..  code-block:: json
    :caption: A paginated response has this shape

    {
      "data": {
        "expertsConnection": {
          "edges": [
            {
              "cursor": "Y3Vyc29yOjE=",
              "node": {
                "familyName": "Smith",
                "givenName": "Jane"
              }
            }
          ],
          "pageInfo": {
            "hasNextPage": true,
            "hasPreviousPage": false,
            "startCursor": "Y3Vyc29yOjE=",
            "endCursor": "Y3Vyc29yOjE="
          },
          "totalCount": 42
        }
      }
    }

..  list-table:: Response fields
    :widths: 30 *
    :header-rows: 1

    * - Field
      - Description
    * - `edges`
      - Array of edge objects, each containing a `cursor` and a `node` (the actual entity)
    * - `pageInfo.hasNextPage`
      - `true` if more records exist after this page
    * - `pageInfo.hasPreviousPage`
      - `true` if an `after` cursor was provided (i.e. this is not the first page)
    * - `pageInfo.startCursor`
      - Cursor of the first edge in this page (`null` if empty)
    * - `pageInfo.endCursor`
      - Cursor of the last edge in this page (`null` if empty). Pass this as the `after` argument to fetch the next page.
    * - `totalCount`
      - Total number of matching records across all pages. Only queried from the database when this field is actually requested in the GraphQL selection.
