<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2026 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace Tests\Api;

use Tests\Support\ApiTester;

/**
 * API tests for the Taxonomy type.
 *
 * Covers plain lists, name filtering, paginated connections,
 * and the foreignKey relation from Taxonomy to Disciplines.
 */
class TaxonomyCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    // =========================================================================
    // Plain list
    // =========================================================================

    public function returnsTaxonomyList(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid name description } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomies[0].uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomies[0].name');
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomies[0].description');
    }

    public function returnsTaxonomyListWithMinimumFieldSelection(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomies[0].uid');
    }

    public function returnsTaxonomyListWithAtLeastFiveEntries(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        // The seeder creates 5-15 entries; verify at least 5 exist
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomies[4].uid');
    }

    // =========================================================================
    // Single record by UID
    // =========================================================================

    public function returnsSingleTaxonomyByUid(ApiTester $I): void
    {
        // First grab a valid UID from the list
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid name } }',
        ]));
        $uids = $I->grabDataFromResponseByJsonPath('$.data.taxonomies[0].uid');
        $name = $I->grabDataFromResponseByJsonPath('$.data.taxonomies[0].name');

        $I->sendPost('/', json_encode([
            'query' => '{ taxonomy(uid: ' . $uids[0] . ') { uid name } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['data' => ['taxonomy' => ['uid' => $uids[0], 'name' => $name[0]]]]);
    }

    // =========================================================================
    // Name filter
    // =========================================================================

    public function filtersTaxonomiesByName(ApiTester $I): void
    {
        // Grab a known name
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid name } }',
        ]));
        $name = $I->grabDataFromResponseByJsonPath('$.data.taxonomies[0].name');

        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies(name: "' . $name[0] . '") { uid name } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['data' => ['taxonomies' => [['name' => $name[0]]]]]);
    }

    public function filterByNonExistentNameReturnsEmptyList(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies(name: "Does Not Exist XYZ") { uid name } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['data' => ['taxonomies' => []]]);
    }

    // =========================================================================
    // Paginated connection
    // =========================================================================

    public function returnsTaxonomyConnection(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{
                taxonomyConnection(first: 2) {
                    edges { cursor node { uid name } }
                    pageInfo { hasNextPage hasPreviousPage startCursor endCursor }
                    totalCount
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomyConnection.edges[0].cursor');
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomyConnection.edges[0].node.uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomyConnection.edges[0].node.name');
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomyConnection.totalCount');
    }

    public function taxonomyConnectionFirstPageHasNoHasPreviousPage(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{
                taxonomyConnection(first: 2) {
                    edges { cursor node { uid } }
                    pageInfo { hasNextPage hasPreviousPage }
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['taxonomyConnection' => ['pageInfo' => ['hasPreviousPage' => false]]],
        ]);
    }

    public function taxonomyConnectionPaginatesThroughPages(ApiTester $I): void
    {
        // Page 1: small page size to guarantee a second page
        $I->sendPost('/', json_encode([
            'query' => '{
                taxonomyConnection(first: 2) {
                    edges { cursor node { uid name } }
                    pageInfo { hasNextPage endCursor }
                    totalCount
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.taxonomyConnection.totalCount');
        $endCursor = $I->grabDataFromResponseByJsonPath('$.data.taxonomyConnection.pageInfo.endCursor');

        // The seeder creates at least 5, so there must be a next page
        $I->seeResponseContainsJson([
            'data' => ['taxonomyConnection' => ['pageInfo' => ['hasNextPage' => true]]],
        ]);

        // Page 2: continue from endCursor
        $I->sendPost('/', json_encode([
            'query' => '{
                taxonomyConnection(first: 2, after: "' . $endCursor[0] . '") {
                    edges { cursor node { uid name } }
                    pageInfo { hasNextPage hasPreviousPage }
                    totalCount
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        // Second page should report hasPreviousPage
        $I->seeResponseContainsJson([
            'data' => ['taxonomyConnection' => ['pageInfo' => ['hasPreviousPage' => true]]],
        ]);
        // totalCount should be the same across pages
        $I->seeResponseContainsJson([
            'data' => ['taxonomyConnection' => ['totalCount' => $totalCount[0]]],
        ]);
    }

    public function taxonomyConnectionTotalCountMatchesListCount(ApiTester $I): void
    {
        // Get the full list
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid } }',
        ]));
        $allUids = $I->grabDataFromResponseByJsonPath('$.data.taxonomies[*].uid');

        // Get the connection totalCount
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomyConnection(first: 1) { totalCount } }',
        ]));
        $I->seeResponseContainsJson([
            'data' => ['taxonomyConnection' => ['totalCount' => count($allUids)]],
        ]);
    }

    // =========================================================================
    // Relation: Taxonomy -> Disciplines (foreignKey)
    // =========================================================================

    public function returnsDisciplinesForTaxonomy(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid name disciplines { uid name } } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        // At least one taxonomy should have disciplines assigned by the seeder
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomies[*].disciplines');
    }

    public function taxonomyDisciplinesContainValidUids(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid disciplines { uid name } } }',
        ]));
        $I->seeResponseCodeIs(200);

        // Grab a discipline UID from the nested relation
        $disciplineUids = $I->grabDataFromResponseByJsonPath('$.data.taxonomies[*].disciplines[0].uid');
        // Verify at least one taxonomy has disciplines
        $I->seeNotEmpty($disciplineUids);
    }
}
