<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2026 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>, Academy of Sciences and Literature | Mainz
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

namespace Digicademy\TypoGraph\Middleware;

use Digicademy\TypoGraph\Service\ResolverService;
use Psr\Http\Message\{
    ResponseFactoryInterface,
    ResponseInterface,
    ServerRequestInterface,
    StreamFactoryInterface
};
use Psr\Http\Server\{
    MiddlewareInterface,
    RequestHandlerInterface
};
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * PSR-15 Middleware that provides a GraphQL API endpoint.
 * Bypasses TYPO3 frontend rendering for better performance.
 *
 * @author Frodo Podschwadek <frodo.podschwadek@adwmainz.de>
 */
class ApiMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected ResolverService $resolver,
        protected ResponseFactoryInterface $responseFactory,
        protected StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Process the request and return a GraphQL response.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (preg_match('#^(/graphql)/?#', $path)) {
            $site = $request->getAttribute('site');
            $typographConfig = $site instanceof Site
                ? ($site->getConfiguration()['typograph'] ?? [])
                : [];
            $this->resolver->configure($typographConfig);

            $output = $this->resolver->process($request->getBody()->getContents());
            return $this->responseFactory->createResponse()
                ->withHeader('Content-Type', 'application/graphql-response+json; charset=utf-8')
                ->withBody($this->streamFactory->createStream((string)$output));
        }

        // Not a GraphQL API request - continue middleware chain
        return $handler->handle($request);
    }
}
