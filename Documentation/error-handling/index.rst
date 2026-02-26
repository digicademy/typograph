..  _error_handling:

==============
Error Handling
==============

TypoGraph logs helpful warnings and errors when relations are misconfigured:

* **Missing configuration**: "Relation Taxonomy.disciplines is not configured in the site configuration"
* **Missing targetType**: "Relation Taxonomy.disciplines is missing targetType configuration"
* **Unmapped target**: "Target type Discipline is not mapped to a table in tableMapping"
* **Missing MM table**: "Relation Expert.disciplines with storageType=mmTable is missing mmTable configuration"
* **Missing foreign key field**: "Relation Taxonomy.disciplines with storageType=foreignKey is missing foreignKeyField configuration"

Check your TYPO3 logs if relations return `null` or empty arrays unexpectedly.
