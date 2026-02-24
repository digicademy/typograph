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
 * API tests for the Expert type.
 *
 * Covers plain lists, name filtering, paginated connections,
 * the uid relation to primaryDiscipline, and the mmTable relation
 * to disciplines.
 */
class ExpertCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    // =========================================================================
    // Plain list
    // =========================================================================

    public function returnsExpertList(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid firstName lastName email biography } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].firstName');
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].lastName');
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].email');
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].biography');
    }

    public function returnsExpertListWithAtLeastFiveEntries(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[4].uid');
    }

    // =========================================================================
    // Single record by UID
    // =========================================================================

    public function returnsSingleExpertByUid(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid firstName lastName } }',
        ]));
        $uid = $I->grabDataFromResponseByJsonPath('$.data.experts[0].uid');
        $firstName = $I->grabDataFromResponseByJsonPath('$.data.experts[0].firstName');
        $lastName = $I->grabDataFromResponseByJsonPath('$.data.experts[0].lastName');

        $I->sendPost('/', json_encode([
            'query' => '{ expert(uid: ' . $uid[0] . ') { uid firstName lastName } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['expert' => [
                'uid' => $uid[0],
                'firstName' => $firstName[0],
                'lastName' => $lastName[0],
            ]],
        ]);
    }

    // =========================================================================
    // Name filters
    // =========================================================================

    public function filtersExpertsByFirstName(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid firstName } }',
        ]));
        $firstName = $I->grabDataFromResponseByJsonPath('$.data.experts[0].firstName');

        $I->sendPost('/', json_encode([
            'query' => '{ experts(firstName: "' . $firstName[0] . '") { uid firstName } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'data' => ['experts' => [['firstName' => $firstName[0]]]],
        ]);
    }

    public function filtersExpertsByLastName(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid lastName } }',
        ]));
        $lastName = $I->grabDataFromResponseByJsonPath('$.data.experts[0].lastName');

        $I->sendPost('/', json_encode([
            'query' => '{ experts(lastName: "' . $lastName[0] . '") { uid lastName } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'data' => ['experts' => [['lastName' => $lastName[0]]]],
        ]);
    }

    public function filtersExpertsByFirstNameAndLastName(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid firstName lastName } }',
        ]));
        $firstName = $I->grabDataFromResponseByJsonPath('$.data.experts[0].firstName');
        $lastName = $I->grabDataFromResponseByJsonPath('$.data.experts[0].lastName');

        $I->sendPost('/', json_encode([
            'query' => '{ experts(firstName: "' . $firstName[0] . '", lastName: "' . $lastName[0] . '") { uid firstName lastName } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['experts' => [['firstName' => $firstName[0], 'lastName' => $lastName[0]]]],
        ]);
    }

    public function filterByNonExistentFirstNameReturnsEmptyList(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts(firstName: "Nonexistent Person XYZ") { uid } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['data' => ['experts' => []]]);
    }

    // =========================================================================
    // Paginated connection
    // =========================================================================

    public function returnsExpertConnection(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{
                expertConnection(first: 2) {
                    edges { cursor node { uid firstName lastName email } }
                    pageInfo { hasNextPage hasPreviousPage startCursor endCursor }
                    totalCount
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.expertConnection.edges[0].cursor');
        $I->seeResponseJsonMatchesJsonPath('$.data.expertConnection.edges[0].node.uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.expertConnection.edges[0].node.firstName');
        $I->seeResponseJsonMatchesJsonPath('$.data.expertConnection.totalCount');
    }

    public function expertConnectionFirstPageHasNoHasPreviousPage(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{
                expertConnection(first: 2) {
                    edges { cursor node { uid } }
                    pageInfo { hasPreviousPage }
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['expertConnection' => ['pageInfo' => ['hasPreviousPage' => false]]],
        ]);
    }

    public function expertConnectionPaginatesThroughPages(ApiTester $I): void
    {
        // Page 1
        $I->sendPost('/', json_encode([
            'query' => '{
                expertConnection(first: 2) {
                    edges { cursor node { uid } }
                    pageInfo { hasNextPage endCursor }
                    totalCount
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['expertConnection' => ['pageInfo' => ['hasNextPage' => true]]],
        ]);
        $endCursor = $I->grabDataFromResponseByJsonPath(
            '$.data.expertConnection.pageInfo.endCursor'
        );
        $totalCount = $I->grabDataFromResponseByJsonPath(
            '$.data.expertConnection.totalCount'
        );

        // Page 2
        $I->sendPost('/', json_encode([
            'query' => '{
                expertConnection(first: 2, after: "' . $endCursor[0] . '") {
                    edges { cursor node { uid } }
                    pageInfo { hasPreviousPage }
                    totalCount
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'data' => ['expertConnection' => ['pageInfo' => ['hasPreviousPage' => true]]],
        ]);
        $I->seeResponseContainsJson([
            'data' => ['expertConnection' => ['totalCount' => $totalCount[0]]],
        ]);
    }

    public function expertConnectionTotalCountMatchesListCount(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid } }',
        ]));
        $allUids = $I->grabDataFromResponseByJsonPath('$.data.experts[*].uid');

        $I->sendPost('/', json_encode([
            'query' => '{ expertConnection(first: 1) { totalCount } }',
        ]));
        $I->seeResponseContainsJson([
            'data' => ['expertConnection' => ['totalCount' => count($allUids)]],
        ]);
    }

    public function expertConnectionEdgesDoNotOverlapBetweenPages(ApiTester $I): void
    {
        // Page 1
        $I->sendPost('/', json_encode([
            'query' => '{
                expertConnection(first: 2) {
                    edges { node { uid } }
                    pageInfo { endCursor }
                }
            }',
        ]));
        $page1Uids = $I->grabDataFromResponseByJsonPath(
            '$.data.expertConnection.edges[*].node.uid'
        );
        $endCursor = $I->grabDataFromResponseByJsonPath(
            '$.data.expertConnection.pageInfo.endCursor'
        );

        // Page 2
        $I->sendPost('/', json_encode([
            'query' => '{
                expertConnection(first: 2, after: "' . $endCursor[0] . '") {
                    edges { node { uid } }
                }
            }',
        ]));
        $page2Uids = $I->grabDataFromResponseByJsonPath(
            '$.data.expertConnection.edges[*].node.uid'
        );

        $overlap = array_intersect($page1Uids, $page2Uids);
        $I->seeEmpty($overlap, 'Pages must not contain overlapping records');
    }

    public function expertConnectionEdgesAreOrderedByUid(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{
                expertConnection(first: 100) {
                    edges { node { uid } }
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $uids = $I->grabDataFromResponseByJsonPath(
            '$.data.expertConnection.edges[*].node.uid'
        );

        $sorted = $uids;
        sort($sorted, SORT_NUMERIC);
        $I->seeEquals($sorted, $uids, 'Connection edges must be ordered by UID ascending');
    }

    // =========================================================================
    // Relation: Expert -> Primary Discipline (uid)
    // =========================================================================

    public function returnsPrimaryDisciplineForExpert(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid primaryDiscipline { uid name } } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].primaryDiscipline.uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].primaryDiscipline.name');
    }

    public function primaryDisciplineExistsInDisciplineList(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines { uid } }',
        ]));
        $allDisciplineUids = $I->grabDataFromResponseByJsonPath('$.data.disciplines[*].uid');

        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid primaryDiscipline { uid } } }',
        ]));
        $primaryUid = $I->grabDataFromResponseByJsonPath(
            '$.data.experts[0].primaryDiscipline.uid'
        );

        $I->seeContains($primaryUid[0], $allDisciplineUids);
    }

    // =========================================================================
    // Relation: Expert -> Disciplines (mmTable)
    // =========================================================================

    public function returnsDisciplinesForExpert(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid disciplines { uid name } } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        // Every expert has at least 1 discipline via MM (seeder guarantees this)
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].disciplines[0].uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].disciplines[0].name');
    }

    public function expertDisciplinesContainPrimaryDiscipline(ApiTester $I): void
    {
        // The seeder ensures the primary discipline is always in the MM set
        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid primaryDiscipline { uid } disciplines { uid } } }',
        ]));
        $I->seeResponseCodeIs(200);

        $primaryUid = $I->grabDataFromResponseByJsonPath(
            '$.data.experts[0].primaryDiscipline.uid'
        );
        $mmUids = $I->grabDataFromResponseByJsonPath(
            '$.data.experts[0].disciplines[*].uid'
        );

        $I->seeContains(
            $primaryUid[0],
            $mmUids,
            'MM disciplines must include the primary discipline'
        );
    }

    public function expertDisciplinesAllExistInDisciplineList(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ disciplines { uid } }',
        ]));
        $allDisciplineUids = $I->grabDataFromResponseByJsonPath('$.data.disciplines[*].uid');

        $I->sendPost('/', json_encode([
            'query' => '{ experts { uid disciplines { uid } } }',
        ]));
        $mmUids = $I->grabDataFromResponseByJsonPath(
            '$.data.experts[0].disciplines[*].uid'
        );

        foreach ($mmUids as $uid) {
            $I->seeContains($uid, $allDisciplineUids);
        }
    }

    // =========================================================================
    // Deep nesting: Expert -> Discipline -> Taxonomy
    // =========================================================================

    public function resolvesDeeplyNestedRelations(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{
                experts {
                    uid
                    firstName
                    primaryDiscipline {
                        uid
                        name
                        taxonomy { uid name }
                    }
                }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath(
            '$.data.experts[0].primaryDiscipline.taxonomy.uid'
        );
        $I->seeResponseJsonMatchesJsonPath(
            '$.data.experts[0].primaryDiscipline.taxonomy.name'
        );
    }
}
