# TypoGraph

TYPO3 extension for providing access to TYPO3 database table data via a GraphQL endpoint. Under the bonnet, it uses https://github.com/webonyx/graphql-php.

If you want to use this extension, you need to be sufficiently familiar with the basic concepts of GraphQL. You also need to know how to create GraphQL schemas. If you want to learn more about GraphQL or need a refresher, read the docs at https://graphql.org/learn/.

⚠️ This extension is still in development stage and while it currently works for us, it may break in cases we haven not tested yet. Use with care!

## Integration

Add a new page for the endpoint. The slug for the page must be `/graphql`. Add the *TypoGraph GraphQL Endpoint* plugin as the only content element to this page. That's it!

Now you can configure your plugin.

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

## Relation Configuration

When your GraphQL types reference other types (e.g., a `Taxonomy` has multiple `Discipline` objects), you need to configure how TypoGraph should resolve these relations from your database.

### Why Relations Need Configuration

Unlike scalar fields (strings, integers), object-type fields require TypoGraph to:
1. Identify which database column stores the relation reference
2. Know which table to query for the related records
3. Understand the storage format (single UID, comma-separated UIDs, or MM table)

Without explicit configuration, TypoGraph will log a warning and return `null` for unconfigured relations.

### Relation Configuration Structure

Relations are configured under `plugin.tx_typograph.settings.relations` using the format `{TypeName}.{fieldName}`:

```typoscript
plugin.tx_typograph.settings {
  relations {
    TypeName.fieldName {
      sourceField = database_column_name
      targetType = RelatedTypeName
      storageType = uid|commaSeparated|mmTable|foreignKey

      # Additional fields for mmTable storage type:
      mmTable = tx_some_mm_table
      mmSourceField = uid_local
      mmTargetField = uid_foreign
      mmSortingField = sorting

      # Additional fields for foreignKey storage type:
      foreignKeyField = column_in_target_table
    }
  }
}
```

### Configuration Fields

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `sourceField` | No | Field name in snake_case | Database column in the source table containing the relation reference (not used for `foreignKey` type) |
| `targetType` | Yes | - | GraphQL type name of the related entity (must exist in `tableMapping`) |
| `storageType` | No | `uid` | How the relation is stored: `uid`, `commaSeparated`, `mmTable`, or `foreignKey` |
| `mmTable` | For `mmTable` only | - | Name of the MM (many-to-many) intermediary table |
| `mmSourceField` | For `mmTable` only | `uid_local` | Column in MM table referencing source record |
| `mmTargetField` | For `mmTable` only | `uid_foreign` | Column in MM table referencing target record |
| `mmSortingField` | For `mmTable` only | `sorting` | Column in MM table for sorting order |
| `foreignKeyField` | For `foreignKey` only | - | Column in target table that references the source record UID |

### Storage Types

#### 1. Single UID (`uid`)

Use when a database column contains a single UID reference.

**Database structure:**
```
tx_taxonomy table:
  uid: 1
  name: "Computer Science"
  main_discipline: 42  ← Single UID
```

**GraphQL schema:**
```graphql
type Taxonomy {
  name: String
  mainDiscipline: Discipline
}
```

**Configuration:**
```typoscript
relations {
  Taxonomy.mainDiscipline {
    sourceField = main_discipline
    targetType = Discipline
    storageType = uid
  }
}
```

#### 2. Comma-Separated UIDs (`commaSeparated`)

Use when a database column contains multiple UIDs as a comma-separated string.

**Database structure:**
```
tx_taxonomy table:
  uid: 1
  name: "Computer Science"
  disciplines: "12,45,78"  ← Comma-separated UIDs
```

**GraphQL schema:**
```graphql
type Taxonomy {
  name: String
  disciplines: [Discipline]
}
```

**Configuration:**
```typoscript
relations {
  Taxonomy.disciplines {
    sourceField = disciplines
    targetType = Discipline
    storageType = commaSeparated
  }
}
```

#### 3. MM Table (`mmTable`)

Use for many-to-many relations stored via an intermediary MM table.

**Database structure:**
```
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
```

**GraphQL schema:**
```graphql
type Expert {
  name: String
  disciplines: [Discipline]
}
```

**Configuration:**
```typoscript
relations {
  Expert.disciplines {
    targetType = Discipline
    storageType = mmTable
    mmTable = tx_expert_discipline_mm
    mmSourceField = uid_local
    mmTargetField = uid_foreign
    mmSortingField = sorting
  }
}
```

#### 4. Foreign Key / Inverse Relation (`foreignKey`)

Use when the target table has a foreign key column pointing back to the source record. This handles "sloppy MM" scenarios where multiple target records can reference the same source record, potentially with duplicate data.

**Database structure:**
```
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
```

**GraphQL schema:**
```graphql
type Taxonomy {
  name: String
  disciplines: [Discipline]
}

type Discipline {
  name: String
}
```

**Configuration:**
```typoscript
relations {
  Taxonomy.disciplines {
    targetType = Discipline
    storageType = foreignKey
    foreignKeyField = discipline_taxonomy
  }
}
```

**How it works:**
- Queries the target table (`tx_discipline`)
- WHERE `discipline_taxonomy` = source UID (5)
- Returns all matching records (including duplicates)

**Use cases:**
- Legacy databases with denormalized data
- "Sloppy MM" relations without proper MM tables
- Inverse relations where child records point to parent

### Complete Configuration Example

Here's a complete example with multiple relation types:

```typoscript
# TypoGraph extension configuration
plugin.tx_typograph.settings {

  # Schema files
  schemaFiles {
    0 = EXT:pkf_website/Resources/Private/Schemas/Query.graphql
    1 = EXT:pkf_website/Resources/Private/Schemas/Taxonomy.graphql
    2 = EXT:pkf_website/Resources/Private/Schemas/Discipline.graphql
    3 = EXT:pkf_website/Resources/Private/Schemas/Expert.graphql
  }

  # Root elements to tables mapping
  tableMapping {
    disciplines = tx_dmdb_domain_model_discipline
    experts = tx_academy_domain_model_persons
    taxonomies = tx_dmdb_domain_model_discipline_taxonomy
  }

  # Relation configuration
  relations {

    # Single UID relation
    Taxonomy.mainDiscipline {
      sourceField = main_discipline
      targetType = Discipline
      storageType = uid
    }

    # Comma-separated UIDs relation
    Taxonomy.disciplines {
      sourceField = disciplines
      targetType = Discipline
      storageType = commaSeparated
    }

    # MM table relation
    Expert.disciplines {
      targetType = Discipline
      storageType = mmTable
      mmTable = tx_academy_persons_discipline_mm
      mmSourceField = uid_local
      mmTargetField = uid_foreign
      mmSortingField = sorting
    }

    # Foreign key relation (inverse/sloppy MM)
    Taxonomy.relatedDisciplines {
      targetType = Discipline
      storageType = foreignKey
      foreignKeyField = discipline_taxonomy
    }
  }
}
```

### GraphQL Query Example

With the configuration above, you can now query nested relations:

```graphql
{
  taxonomies {
    name
    disciplines {
      name
    }
  }

  experts(limit: 10) {
    familyName
    givenName
    disciplines {
      name
    }
  }
}
```

### Performance Characteristics

TypoGraph implements a **DataLoader pattern** to prevent N+1 query problems:

1. **Batch Loading**: All related records are fetched in a single optimized query per relation type
2. **Request-Scoped Caching**: Each record is loaded only once per GraphQL request
3. **Field Selection**: Only fields requested in the GraphQL query are fetched from the database
4. **Order Preservation**: Related records are returned in the same order as stored (respects MM table sorting)

**Example**: Querying 100 taxonomies with disciplines results in only **2 database queries**:
- 1 query for all taxonomies
- 1 query for all unique disciplines referenced by those taxonomies

### Error Handling

TypoGraph logs helpful warnings and errors when relations are misconfigured:

- **Missing configuration**: "Relation Taxonomy.disciplines is not configured in TypoScript"
- **Missing targetType**: "Relation Taxonomy.disciplines is missing targetType configuration"
- **Unmapped target**: "Target type Discipline is not mapped to a table in tableMapping"
- **Missing MM table**: "Relation Expert.disciplines with storageType=mmTable is missing mmTable configuration"
- **Missing foreign key field**: "Relation Taxonomy.disciplines with storageType=foreignKey is missing foreignKeyField configuration"

Check your TYPO3 logs if relations return `null` or empty arrays unexpectedly.

## AI Disclaimer

The initial basic extension design has been completely done by humans. However, developers of this extension use Claude Code (Sonnet 4.5) to streamline routine tasks, upgrades, improve code quality etc. All changes depending on AI (as far as we are aware) are confirmed by a qualified human software developer before merged into the `main` branch.

Initially, we were planning to document our prompts for increased transparency. Due to the iterative process of responsible software development with AI support, we soon found this to be unsustainable in terms of documentation load and abandoned this part of our workflow.
