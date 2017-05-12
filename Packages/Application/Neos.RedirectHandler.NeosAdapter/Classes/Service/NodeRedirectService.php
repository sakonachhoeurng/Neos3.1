<?php
namespace Neos\RedirectHandler\NeosAdapter\Service;

/*
 * This file is part of the Neos.RedirectHandler.NeosAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Request;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Flow\Mvc\Routing\RouterCachingService;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Neos\Routing\Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;

/**
 * Service that creates redirects for moved / deleted nodes.
 *
 * Note: This is usually invoked by a signal emitted by Workspace::publishNode()
 *
 * @Flow\Scope("singleton")
 */
class NodeRedirectService implements NodeRedirectServiceInterface
{
    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @Flow\Inject
     * @var RedirectStorageInterface
     */
    protected $redirectStorage;

    /**
     * @Flow\Inject
     * @var RouterCachingService
     */
    protected $routerCachingService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\InjectConfiguration(path="statusCode", package="Neos.RedirectHandler")
     * @var array
     */
    protected $defaultStatusCode;

    /**
     * {@inheritdoc}
     */
    public function createRedirectsForPublishedNode(NodeInterface $node, Workspace $targetWorkspace)
    {
        $nodeType = $node->getNodeType();
        if ($targetWorkspace->getName() !== 'live' || !$nodeType->isOfType('Neos.Neos:Document')) {
            return;
        }

        $context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'dimensions' => $node->getContext()->getDimensions()
        ]);

        $targetNode = $context->getNodeByIdentifier($node->getIdentifier());
        if ($targetNode === null) {
            // The page has been added
            return;
        }

        $targetNodeUriPath = $this->buildUriPathForNodeContextPath($targetNode->getContextPath());
        if ($targetNodeUriPath === null) {
            throw new Exception('The target URI path of the node could not be resolved', 1451945358);
        }

        $hosts = $this->getHostnames($node->getContext());

        // The page has been removed
        if ($node->isRemoved()) {
            $this->flushRoutingCacheForNode($targetNode);
            $statusCode = (integer)$this->defaultStatusCode['gone'];
            $this->redirectStorage->addRedirect($targetNodeUriPath, '', $statusCode, $hosts);
            return;
        }

        // compare the "old" node URI to the new one
        $nodeUriPath = $this->buildUriPathForNodeContextPath($node->getContextPath());
        // use the same regexp than the ContentContextBar Ember View
        $nodeUriPath = preg_replace('/@[A-Za-z0-9;&,\-_=]+/', '', $nodeUriPath);
        if ($nodeUriPath === null || $nodeUriPath === $targetNodeUriPath) {
            // The page node path has not been changed
            return;
        }

        $this->flushRoutingCacheForNode($targetNode);
        $statusCode = (integer)$this->defaultStatusCode['redirect'];
        $this->redirectStorage->addRedirect($targetNodeUriPath, $nodeUriPath, $statusCode, $hosts);

        $q = new FlowQuery([$node]);
        foreach ($q->children('[instanceof Neos.Neos:Document]') as $childrenNode) {
            $this->createRedirectsForPublishedNode($childrenNode, $targetWorkspace);
        }
    }

    /**
     * Collects all hostnames from the Domain entries attached to the current site.
     *
     * @param ContentContext $contentContext
     * @return array
     */
    protected function getHostnames(ContentContext $contentContext)
    {
        $site = $contentContext->getCurrentSite();
        $domains = [];
        if ($site !== null) {
            foreach ($site->getActiveDomains() as $domain) {
                /** @var Domain $domain */
                $domains[] = $domain->getHostname();
            }
        }
        return $domains;
    }

    /**
     * Removes all routing cache entries for the given $nodeData
     *
     * @param NodeInterface $node
     * @return void
     */
    protected function flushRoutingCacheForNode(NodeInterface $node)
    {
        $nodeData = $node->getNodeData();
        $nodeDataIdentifier = $this->persistenceManager->getIdentifierByObject($nodeData);
        if ($nodeDataIdentifier === null) {
            return;
        }
        $this->routerCachingService->flushCachesByTag($nodeDataIdentifier);
    }

    /**
     * Creates a (relative) URI for the given $nodeContextPath removing the "@workspace-name" from the result
     *
     * @param string $nodeContextPath
     * @return string the resulting (relative) URI or NULL if no route could be resolved
     */
    protected function buildUriPathForNodeContextPath($nodeContextPath)
    {
        try {
            return $this->getUriBuilder()
                ->uriFor('show', ['node' => $nodeContextPath], 'Frontend\\Node', 'Neos.Neos');
        } catch (NoMatchingRouteException $exception) {
            return null;
        }
    }

    /**
     * Creates an UriBuilder instance for the current request
     *
     * @return UriBuilder
     */
    protected function getUriBuilder()
    {
        if ($this->uriBuilder === null) {
            $httpRequest = Request::createFromEnvironment();
            $actionRequest = new ActionRequest($httpRequest);
            $this->uriBuilder = new UriBuilder();
            $this->uriBuilder
                ->setRequest($actionRequest);
            $this->uriBuilder
                ->setFormat('html')
                ->setCreateAbsoluteUri(false);
        }
        return $this->uriBuilder;
    }
}
