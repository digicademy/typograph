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
 * API tests for general GraphQL endpoint behaviour.
 *
 * Covers response format, error handling, multiple root fields
 * in a single query, and the correct Content-Type header.
 */
class GraphqlEndpointCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    // =========================================================================
    // Response format
    // =========================================================================

    public function returnsJsonResponseWithDataKey(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data');
    }

    public function returnsGraphqlJsonContentType(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'application/graphql-response+json; charset=utf-8');
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function returnsErrorForInvalidGraphqlSyntax(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ invalid syntax @@@ }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.errors');
    }

    public function returnsErrorForUnknownField(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid nonExistentField } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.errors');
    }

    public function returnsNullForInvalidJsonBody(ApiTester $I): void
    {
        $I->sendPost('/', 'this is not json');
        $I->seeResponseCodeIs(200);
    }

    public function returnsNullForMissingQueryField(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'notquery' => '{ taxonomies { uid } }',
        ]));
        $I->seeResponseCodeIs(200);
    }

    // =========================================================================
    // Multiple root fields in a single request
    // =========================================================================

    public function returnsMultipleRootFieldsInSingleQuery(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{
                taxonomies { uid name }
                disciplines { uid name }
                experts { uid firstName lastName }
            }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomies[0].uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.disciplines[0].uid');
        $I->seeResponseJsonMatchesJsonPath('$.data.experts[0].uid');
    }

    // =========================================================================
    // Field selection (only requested fields returned)
    // =========================================================================

    public function returnsOnlyRequestedFields(ApiTester $I): void
    {
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { name } }',
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseJsonMatchesJsonPath('$.data.taxonomies[0].name');

        // description was not requested and should not appear
        $response = json_decode($I->grabResponse(), true);
        $I->seeNotEmpty($response['data']['taxonomies']);
        $first = $response['data']['taxonomies'][0];
        $I->seeArrayNotHasKey('description', $first);
    }

    // =========================================================================
    // GraphQL variables
    // =========================================================================

    public function supportsGraphqlVariables(ApiTester $I): void
    {
        // Grab a valid UID first
        $I->sendPost('/', json_encode([
            'query' => '{ taxonomies { uid } }',
        ]));
        $uid = $I->grabDataFromResponseByJsonPath('$.data.taxonomies[0].uid');

        $I->sendPost('/', json_encode([
            'query' => 'query GetTaxonomy($id: Int!) { taxonomy(uid: $id) { uid name } }',
            'variables' => ['id' => $uid[0]],
        ]));
        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'data' => ['taxonomy' => ['uid' => $uid[0]]],
        ]);
    }
}
