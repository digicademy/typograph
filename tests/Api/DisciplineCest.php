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
 * API tests for the Discipline type.
 *
 * Covers plain lists, name filtering, paginated connections,
 * and the uid relation from Discipline to Taxonomy.
 */
class DisciplineCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    // =========================================================================
    // Plain list
    // =========================================================================

    public function returnsDisciplineList(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines { uid name description } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplines[0].uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplines[0].name');
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplines[0].description');
    }

    public function returnsDisciplineListWithAtLeastFiveEntries(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines { uid } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplines[4].uid');
    }

    // =========================================================================
    // Single record by UID
    // =========================================================================

    public function returnsSingleDisciplineByUid(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines { uid name description } }',
        ]));
        $uid = $I->grabDataFromResponseByJsonPath('$.data.disciplines[0].uid');
        $name = $I->grabDataFromResponseByJsonPath('$.data.disciplines[0].name');

        $I->sendPost('/', json_encode([
            'query' => '{ discipline(uid: ' . $uid[0] . ') { uid name description } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'data' => ['discipline' => ['uid' => $uid[0], 'name' => $name[0]]],
        ]);
    }

    // =========================================================================
    // Name filter
    // =========================================================================

    public function filtersDisciplinesByName(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines { uid name } }',
        ]));
        $name = $I->grabDataFromResponseByJsonPath('$.data.disciplines[0].name');

        $I->sendPost('/', json_encode([
            'query' => '{ disciplines(name: "' . $name[0] . '") { uid name } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['data' => ['disciplines' => [['name' => $name[0]]]]]);
    }

    public function filterByNonExistentNameReturnsEmptyList(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines(name: "Does Not Exist XYZ") { uid } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => ['disciplines' => []]]);
    }

    // =========================================================================
    // Paginated connection
    // =========================================================================

    public function returnsDisciplineConnection(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{
                disciplineConnection(first: 2) {
                    edges { cursor node { uid name description } }
                    pageInfo { hasNextPage hasPreviousPage startCursor endCursor }
                    totalCount
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplineConnection.edges[0].cursor');
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplineConnection.edges[0].node.uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplineConnection.totalCount');
    }

    public function disciplineConnectionFirstPageHasNoHasPreviousPage(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{
                disciplineConnection(first: 2) {
                    edges { cursor node { uid } }
                    pageInfo { hasPreviousPage }
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['disciplineConnection' => ['pageInfo' => ['hasPreviousPage' => false]]],
        ]);
    }

    public function disciplineConnectionPaginatesThroughPages(ApiTester $I): void
    {
        // Page 1
        $I->sendPost('/', json_encode([
            'query' => '{
                disciplineConnection(first: 2) {
                    edges { cursor node { uid } }
                    pageInfo { hasNextPage endCursor }
                    totalCount
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['disciplineConnection' => ['pageInfo' => ['hasNextPage' => true]]],
        ]);
        $endCursor = $I->grabDataFromResponseByJsonPath(
            '$.data.disciplineConnection.pageInfo.endCursor'
        );
        $totalCount = $I->grabDataFromResponseByJsonPath(
            '$.data.disciplineConnection.totalCount'
        );

        // Page 2
        $I->sendPost('/', json_encode([
            'query' => '{
                disciplineConnection(first: 2, after: "' . $endCursor[0] . '") {
                    edges { cursor node { uid } }
                    pageInfo { hasPreviousPage }
                    totalCount
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['disciplineConnection' => ['pageInfo' => ['hasPreviousPage' => true]]],
        ]);
        $I->seeResponseContainsJson([
            'data' => ['disciplineConnection' => ['totalCount' => $totalCount[0]]],
        ]);
    }

    public function disciplineConnectionTotalCountMatchesListCount(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines { uid } }',
        ]));
        $allUids = $I->grabDataFromResponseByJsonPath('$.data.disciplines[*].uid');

        $I->sendPost('/', json_encode([
            'query' => '{ disciplineConnection(first: 1) { totalCount } }',
        ]));
        $I->seeResponseContainsJson([
            'data' => ['disciplineConnection' => ['totalCount' => count($allUids)]],
        ]);
    }

    public function disciplineConnectionEdgesDoNotOverlapBetweenPages(ApiTester $I): void
    {
        // Page 1
        $I->sendPost('/', json_encode([
            'query' => '{
                disciplineConnection(first: 2) {
                    edges { node { uid } }
                    pageInfo { endCursor }
                }
            }',
        ]));
        $page1Uids = $I->grabDataFromResponseByJsonPath(
            '$.data.disciplineConnection.edges[*].node.uid'
        );
        $endCursor = $I->grabDataFromResponseByJsonPath(
            '$.data.disciplineConnection.pageInfo.endCursor'
        );

        // Page 2
        $I->sendPost('/', json_encode([
            'query' => '{
                disciplineConnection(first: 2, after: "' . $endCursor[0] . '") {
                    edges { node { uid } }
                }
            }',
        ]));
        $page2Uids = $I->grabDataFromResponseByJsonPath(
            '$.data.disciplineConnection.edges[*].node.uid'
        );

        // No UID should appear on both pages
        $overlap = array_intersect($page1Uids, $page2Uids);
        $I->seeEmpty($overlap, 'Pages must not contain overlapping records');
    }

    // =========================================================================
    // Relation: Discipline -> Taxonomy (uid)
    // =========================================================================

    public function returnsTaxonomyForDiscipline(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines { uid name taxonomy { uid name } } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        // Every discipline is assigned a taxonomy by the seeder
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplines[0].taxonomy.uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplines[0].taxonomy.name');
    }

    public function disciplineTaxonomyMatchesTaxonomyList(ApiTester $I): void
    {
        // Get all taxonomy UIDs
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid } }',
        ]));
        $taxonomyUids = $I->grabDataFromResponseByJsonPath('$.data.taxonomies[*].uid');

        // Get first discipline's taxonomy UID
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines { uid taxonomy { uid } } }',
        ]));
        $relatedTaxonomyUid = $I->grabDataFromResponseByJsonPath(
            '$.data.disciplines[0].taxonomy.uid'
        );

        // The related taxonomy UID must exist in the taxonomy list
        $I->seeContains($relatedTaxonomyUid[0], $taxonomyUids);
    }
}
