<?php
namespace Neos\RedirectHandler\Tests\Functional;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\RedirectHandler\DatabaseStorage\Domain\Repository\RedirectRepository;
use Neos\RedirectHandler\RedirectService;
use Neos\Flow\Tests\FunctionalTestCase;

/**
 * Functional tests for the RedirectService and dependant classes
 */
class RedirectTests extends FunctionalTestCase
{
    /**
     * @var boolean
     */
    protected static $testablePersistenceEnabled = true;

    /**
     * @var RedirectService
     */
    protected $redirectService;

    /**
     * @var RedirectRepository
     */
    protected $redirectRepository;

    /**
     *
     */
    public function setUp()
    {
        parent::setUp();
        $this->redirectService = $this->objectManager->get(RedirectService::class);
        $this->redirectRepository = $this->objectManager->get(RedirectRepository::class);
    }

    /**
     * @test
     */
    public function addRedirectTrimsLeadingAndTrailingSlashesOfSourceAndTargetPath()
    {
        $this->assertEquals(0, $this->redirectRepository->countAll());
        $this->redirectService->addRedirect('/some/source/path/', '/some/target/path/');

        $this->persistenceManager->persistAll();
        $redirect = $this->redirectRepository->findAll()->getFirst();

        $this->assertSame('some/source/path', $redirect->getSourceUriPath());
        $this->assertSame('some/target/path', $redirect->getTargetUriPath());
    }

    /**
     * @test
     */
    public function addRedirectSetsTheCorrectDefaultStatusCode()
    {
        $this->assertEquals(0, $this->redirectRepository->countAll());
        $this->redirectService->addRedirect('some/source/path', 'some/target/path');

        $this->persistenceManager->persistAll();
        $redirect = $this->redirectRepository->findAll()->getFirst();

        $this->assertSame(301, $redirect->getStatusCode());
    }

    /**
     * @test
     */
    public function addRedirectRespectsTheGivenStatusCode()
    {
        $this->assertEquals(0, $this->redirectRepository->countAll());
        $this->redirectService->addRedirect('some/source/path', 'some/target/path', 123);

        $this->persistenceManager->persistAll();
        $redirect = $this->redirectRepository->findAll()->getFirst();

        $this->assertSame(123, $redirect->getStatusCode());
    }

    /**
     * @test
     * @expectedException \Neos\RedirectHandler\Exception
     */
    public function addRedirectThrowsExceptionIfARedirectExistsForTheGivenSourceUriPath()
    {
        $this->redirectService->addRedirect('a', 'b');
        $this->redirectService->addRedirect('c', 'd');
        $this->persistenceManager->persistAll();

        $this->redirectService->addRedirect('c', 'e');
    }

    /**
     * @test
     * @expectedException \Neos\RedirectHandler\Exception
     */
    public function addRedirectThrowsExceptionIfARedirectExistsForTheGivenTargetUriPath()
    {
        $this->redirectService->addRedirect('a', 'b');
        $this->redirectService->addRedirect('c', 'd');
        $this->persistenceManager->persistAll();

        $this->redirectService->addRedirect('b', 'c');
    }

    /**
     * @test
     */
    public function addRedirectDoesNotThrowAnExceptionIfARedirectReversesAnExistingRedirect()
    {
        $this->redirectService->addRedirect('a', 'b');
        $this->redirectService->addRedirect('c', 'd');
        $this->persistenceManager->persistAll();

        $this->redirectService->addRedirect('d', 'c');
        $this->persistenceManager->persistAll();

        $expectedRedirects = ['a' => 'b', 'd' => 'c'];

        $resultingRedirects = [];
        foreach ($this->redirectRepository->findAll() as $redirect) {
            $resultingRedirects[$redirect->getSourceUriPath()] = $redirect->getTargetUriPath();
        }
        $this->assertSame($expectedRedirects, $resultingRedirects);
    }

    /**
     * Data provider for addRedirectTests()
     */
    public function addRedirectDataProvider()
    {
        return [
            // avoid redundant redirects (c -> d gets updated to c -> e)
            [
                'existingRedirects' => [
                    'a' => 'b',
                    'c' => 'd',
                ],
                'newRedirects' => [
                    'd' => 'e',
                ],
                'expectedRedirects' => [
                    'a' => 'b',
                    'c' => 'e',
                    'd' => 'e',
                ],
            ],
            // avoid redundant redirects, recursively (c -> d gets updated to c -> e)
            [
                'existingRedirects' => [
                    'a' => 'b',
                    'c' => 'b',
                ],
                'newRedirects' => [
                    'b' => 'd',
                ],
                'expectedRedirects' => [
                    'a' => 'd',
                    'b' => 'd',
                    'c' => 'd',
                ],
            ],
            // avoid circular redirects (c -> d is replaced by d -> c)
            [
                'existingRedirects' => [
                    'a' => 'b',
                    'c' => 'd',
                ],
                'newRedirects' => [
                    'd' => 'c',
                ],
                'expectedRedirects' => [
                    'a' => 'b',
                    'd' => 'c',
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider addRedirectDataProvider
     *
     * @param array $existingRedirects
     * @param array $newRedirects
     * @param array $expectedRedirects
     */
    public function addRedirectTests(array $existingRedirects, array $newRedirects, array $expectedRedirects)
    {
        foreach ($existingRedirects as $sourceUriPath => $targetUriPath) {
            $this->redirectService->addRedirect($sourceUriPath, $targetUriPath);
        }
        $this->persistenceManager->persistAll();

        foreach ($newRedirects as $sourceUriPath => $targetUriPath) {
            $this->redirectService->addRedirect($sourceUriPath, $targetUriPath);
        }
        $this->persistenceManager->persistAll();

        $resultingRedirects = [];
        foreach ($this->redirectRepository->findAll() as $redirect) {
            $resultingRedirects[$redirect->getSourceUriPath()] = $redirect->getTargetUriPath();
        }
        $this->assertSame($expectedRedirects, $resultingRedirects);
    }
}
