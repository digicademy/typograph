.. _start:

=========
TypoGraph
=========

:Extension Key:
    typograph

:Package name:
    digicademy/typograph

:Version:
    main

:Language:
    en

:Author:
    Frodo Podschwadek, Digital Academy, Academy of Sciences and Literature | Mainz

:License:
    This document is published under the `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`_ license.

:Rendered:
    |today|

TYPO3 extension for providing access to TYPO3 database table data via a GraphQL
endpoint. Under the bonnet, it uses
`graphql-php <https://github.com/webonyx/graphql-php>`_.

You shold be sufficiently familiar with the basic concepts of GraphQL to use
this extension. For example, you need to know how to create GraphQL schemas. If
you want to learn more about GraphQL or need a refresher, have a look at the
`GraphQL docs <https://graphql.org/learn/>`_.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        Motivation, features and limits of this extension.

    ..  card:: :ref:`Installation <installation>`

        How to install this extension.

    ..  card:: :ref:`Configuration <configuration>`

        Configuring simple and nested queries.

    ..  card:: :ref:`Pagination <pagination>`

        Using paginated queries.

    ..  card:: :ref:`Example setup <example>`

        Set up example tables, schemas and configuration with a CLI command.


..  toctree::
    :hidden:
    :maxdepth: 2
    :titlesonly:

    introduction/index
    installation/index
    configuration/index
    pagination/index
    error-handling/index
    example/index
