..  _relations:

=========
Configuring Relations
=========

..  contents::
    :local:

When your GraphQL types reference other types (e.g., a `Taxonomy` has multiple
`Discipline` objects), you need to configure how TypoGraph should resolve these
relations from your database.

..  _h2-configuration-relations-why:

Why Relations Need Configuration
================================

Unlike scalar fields (strings, integers), object-type fields require the
TypoGraph extension to:

#. Identify which database column stores the relation reference
#. Know which table to query for the related records
#. Understand the storage format (single UID, comma-separated UIDs, or MM table)

Without explicit configuration, TypoGraph will log a warning and return `null`
for unconfigured relations.

..  _h2-configuration-relations-structure:

Relation Configuration Structure
================================

Relations are configured under the `typograph.relations` key in the site
configuration, nested as `<TypeName>: <fieldName>: <config>`:

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      relations:
        TypeName:
          fieldName:
            sourceField: database_column_name
            targetType: RelatedTypeName
            storageType: uid  # uid | commaSeparated | mmTable | foreignKey

            # Additional fields for mmTable storage type:
            mmTable: tx_some_mm_table
            mmSourceField: uid_local
            mmTargetField: uid_foreign
            mmSortingField: sorting

            # Additional fields for foreignKey storage type:
            foreignKeyField: column_in_target_table

..  _h2-configuration-relations-fields:

Configuration Fields
====================

..  list-table:: Configuration Fields
    :widths: 22 * 20 *
    :header-rows: 1

    * - Field
      - Required
      - Default
      - Description
    * - `sourceField`
      - No
      - Field name in snake_case
      - Database column in the source table containing the relation reference
        (not used for `foreignKey` type)
    * - `targetType`
      - Yes
      - <none>
      - GraphQL type name of the related entity (must exist in `tableMapping`)
    * - `storageType`
      - No
      - `uid`
      - How the relation is stored: `uid`, `commaSeparated`, `mmTable`, or
        `foreignKey`
    * - `mmTable`
      - For `mmTable` only
      - <none>
      - Name of the MM (many-to-many) intermediary table
    * - `mmSourceField`
      - For `mmTable` only
      - `uid_local`
      - Column in MM table referencing source record
    * - `mmTargetField`
      - For `mmTable` only
      - `uid_foreign`
      - Column in MM table referencing target record
    * - `mmSortingField`
      - For `mmTable` only
      - `sorting`
      - Column in MM table for sorting order
    * - `foreignKeyField`
      - For `foreignKey` only
      - <none>
      - Column in target table that references the source record UID

..  _h2-configuration-relations-storage-types:

Storage Types
=============

The following variants are available for the `storageType` field.

..  _h3-configuration-relations-storage-type-single-uid:

1. Single UID (`uid`)
------------------

Use when a database column contains a single UID reference.

..  code-block:: text
    :caption: Database structure

    tx_taxonomy table:
      uid: 1
      name: "Computer Science"
      main_discipline: 42  ← Single UID

..  code-block:: text
    :caption: GraphQL schema

    type Taxonomy {
      name: String
      mainDiscipline: Discipline
    }

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      relations:
        Taxonomy:
          mainDiscipline:
            sourceField: main_discipline
            targetType: Discipline
            storageType: uid

..  _h3-configuration-relations-storage-type-comma-separated:

2. Comma-Separated UIDs (`commaSeparated`)
------------------------------------------

Use when a database column contains multiple UIDs as a comma-separated string.

..  code-block:: text
    :caption: Database structure

    tx_taxonomy table:
      uid: 1
      name: "Computer Science"
      disciplines: "12,45,78"  ← Comma-separated UIDs


..  code-block:: text
    :caption: GraphQL schema

    type Taxonomy {
      name: String
      disciplines: [Discipline]
    }

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      relations:
        Taxonomy:
          disciplines:
            sourceField: disciplines
            targetType: Discipline
            storageType: commaSeparated

..  _h3-configuration-relations-storage-type-mm-table:

3. MM Table (`mmTable`)
-----------------------

Use for many-to-many relations stored via an intermediary MM table.

..  code-block:: text
    :caption: Database structure

    tx_expert table:
      uid: 5
      name: "Dr. Smith"

    tx_expert_discipline_mm table:
      uid_local: 5      ← References expert
      uid_foreign: 12   ← References discipline
      sorting: 1

    tx_discipline table:
      uid: 12
      name: "Physics"

..  code-block:: text
    :caption: GraphQL schema

    type Expert {
      name: String
      disciplines: [Discipline]
    }

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      relations:
        Expert:
          disciplines:
            targetType: Discipline
            storageType: mmTable
            mmTable: tx_expert_discipline_mm
            mmSourceField: uid_local
            mmTargetField: uid_foreign
            mmSortingField: sorting

..  _h3-configuration-relations-storage-type-foreign-key:

4. Foreign Key / Inverse Relation (`foreignKey`)
------------------------------------------------

Use when the target table has a foreign key column pointing back to the source
record. This handles 'sloppy MM' scenarios where multiple target records can
reference the same source record, potentially with duplicate data. While this
ideally should not happen, sometimes you may have to work with legacy databases
containing denormalized data, inverse relations where child records point to
the parent, or just with cases where somebody couldn't be bothered to set up
proper MM tables (every software project has at least one former team member
like this).

..  code-block:: text
    :caption: Database structure

    tx_taxonomy table:
      uid: 5
      name: "Applied Sciences"

    tx_discipline table:
      uid: 1
      name: "Physics"
      discipline_taxonomy: 5  ← Foreign key pointing to taxonomy

    tx_discipline table:
      uid: 2
      name: "Physics"          ← Same name, different record
      discipline_taxonomy: 5  ← Same taxonomy reference

    tx_discipline table:
      uid: 3
      name: "Chemistry"
      discipline_taxonomy: 5  ← Another discipline for same taxonomy


..  code-block:: text
    :caption: GraphQL schema

    type Taxonomy {
      name: String
      disciplines: [Discipline]
    }

    type Discipline {
      name: String
    }


..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:
      relations:
        Taxonomy:
          disciplines:
            targetType: Discipline
            storageType: foreignKey
            foreignKeyField: discipline_taxonomy

**How it works:** In this case, TypoGraph queries the target table (`tx_discipline`) with a `WHERE discipline_taxonomy = <source-uid>` query and returns all matching records (including duplicates).

..  _h2-configuration-relations-complete-config-example:

Complete Configuration Example
==============================

This is a complete example with multiple relation types:

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/config.yaml

    typograph:

      # Schema files
      schemaFiles:
        - 'EXT:pkf_website/Resources/Private/Schemas/Query.graphql'
        - 'EXT:pkf_website/Resources/Private/Schemas/Taxonomy.graphql'
        - 'EXT:pkf_website/Resources/Private/Schemas/Discipline.graphql'
        - 'EXT:pkf_website/Resources/Private/Schemas/Expert.graphql'

      # Root elements to tables mapping
      tableMapping:
        disciplines: tx_dmdb_domain_model_discipline
        experts: tx_academy_domain_model_persons
        taxonomies: tx_dmdb_domain_model_discipline_taxonomy

      # Relation configuration
      relations:

        Taxonomy:
          # Single UID relation
          mainDiscipline:
            sourceField: main_discipline
            targetType: Discipline
            storageType: uid

          # Comma-separated UIDs relation
          disciplines:
            sourceField: disciplines
            targetType: Discipline
            storageType: commaSeparated

          # Foreign key relation (inverse/legacy MM)
          relatedDisciplines:
            targetType: Discipline
            storageType: foreignKey
            foreignKeyField: discipline_taxonomy

        Expert:
          # MM table relation
          disciplines:
            targetType: Discipline
            storageType: mmTable
            mmTable: tx_academy_persons_discipline_mm
            mmSourceField: uid_local
            mmTargetField: uid_foreign
            mmSortingField: sorting

..  _h2-configuration-relations-query-example:

GraphQL Query Example
=====================

With the configuration above, you can now query nested relations:

..  code-block:: text
    :caption: GraphQL query

    {
      experts {
        familyName
        givenName
        disciplines {
          name
        }
      }
    }

