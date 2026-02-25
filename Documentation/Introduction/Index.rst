..  _introduction:

============
Introduction
============

TypoGraph provides a middleware for a GraphQL endpoint. This endpoint accepts
valid GraphQL requests for related data and creates a GraphQL response for the
client.

..  note::

    This extension only supports operations of the type `query` so far. While it
    certainly would be nice to have mutations and subscriptions available, the
    technical and security-related challenges are much higher and will require
    careful consideration.

..  _h2-performance:

Performance Characteristics
===========================

TypoGraph implements a `DataLoader pattern <https://www.graphql-js.org/docs/n1-dataloader/>`_ to prevent N+1 query problems:

* **Batch Loading**: All related records are fetched in a single optimised query per relation type
* **Request-Scoped Caching**: Each record is loaded only once per GraphQL request
* **Field Selection**: Only fields requested in the GraphQL query are fetched from the database
* **Order Preservation**: Related records are returned in the same order as stored (respects MM table sorting)

For example, querying 100 research disciplines with related entries for experts in these disciplines from a research information database results in only two database queries: one query for all taxonomies and one query for all unique disciplines referenced by those taxonomies.

..  _h2-origins:

Origins
=======

This extension has been developed by the `Digital Academy of the Academy of Sciences and Literature | Mainz <https://www.adwmainz.de/forschung/digitale-akademie.html>`_ while refactoring on the research information system `Portal Kleine Fächer <https://www.kleinefaecher.de>`_, which provides detailed information on minor subjects at German universities and other institutions of higher education. The examples in this documentation still reflect this initial context.

..  _h2-ai-note:

AI Note
=======

The initial basic extension design has been completely done by humans. However, developers of this extension use Claude Code (Sonnet 4.5, 4.6; Opus 4.6) to streamline routine tasks, upgrades, improve code quality etc. All changes depending on AI (as far as we are aware) are confirmed by a qualified human software developer before merged into the `main` branch.
