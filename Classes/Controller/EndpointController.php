<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2025 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>, Academy of Sciences and Literature | Mainz
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
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

namespace Digicademy\TypoGraph\Controller;

use Digicademy\TypoGraph\Service\ResolverService;
use GraphQL\GraphQL;
use Psr\Http\Message\{
    ResponseInterface
};

use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Returns a GraphQL JSON serialisation.
 */
class EndpointController extends ActionController
{
    public function __construct(
        protected ResolverService $resolver
    ) {}

    /**
     * Serialises data for a GraphQL JSON response.
     *
     * @return ResponseInterface
     */
    public function serialiseAction(): ResponseInterface
    {
        $output = $this->resolver->process($this->request->getBody()->getContents());
        return $this->graphqlJsonResponse($output);
    }

    /**
     * Returns a response object with the given GraphQL JSON string as content.
     *
     * @param string $json
     * @return ResponseInterface
     */
    protected function graphqlJsonResponse(
        ?string $json = null
    ): ResponseInterface {
        return $this->responseFactory->createResponse()
            ->withHeader(
                'Content-Type',
                'application/graphql-response+json; charset=utf-8'
            )
            ->withBody($this->streamFactory->createStream((string)($json)));
    }
}
