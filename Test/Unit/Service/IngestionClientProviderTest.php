<?php

declare(strict_types=1);

namespace Algolia\Ingestion\Test\Unit\Service;

use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Helper\ConfigHelper;
use Algolia\AlgoliaSearch\Service\AlgoliaCredentialsManager;
use Algolia\AlgoliaSearch\Test\TestCase;
use Algolia\Ingestion\Helper\IngestionConfigHelper;
use Algolia\Ingestion\Service\IngestionClientProvider;
use PHPUnit\Framework\MockObject\Stub;

class IngestionClientProviderTest extends TestCase
{
    protected function createObjectToTest(
        ?ConfigHelper $config = null,
        ?AlgoliaCredentialsManager $credentialsManager = null,
        ?IngestionConfigHelper $ingestionConfigHelper = null,
    ): IngestionClientProvider {
        return new IngestionClientProvider(
            $config ?? $this->defaultConfig(),
            $credentialsManager ?? $this->createStub(AlgoliaCredentialsManager::class),
            $ingestionConfigHelper ?? $this->defaultIngestionConfigHelper(),
        );
    }

    public function testGetClientThrowsWhenCredentialsInvalid(): void
    {
        $credentialsManager = $this->createStub(AlgoliaCredentialsManager::class);
        $credentialsManager->method('checkCredentials')->willReturn(false);

        $this->expectException(AlgoliaException::class);
        $this->expectExceptionMessage('Algolia credentials were not provided');

        $this->createObjectToTest(credentialsManager: $credentialsManager)->getClient(1);
    }

    public function testGetClientWithNullStoreIdDefaultsToZero(): void
    {
        $credentialsManager = $this->createMock(AlgoliaCredentialsManager::class);
        $credentialsManager->expects($this->once())
            ->method('checkCredentials')
            ->with(0)
            ->willReturn(false);

        $this->expectException(AlgoliaException::class);

        $this->createObjectToTest(credentialsManager: $credentialsManager)->getClient(null);
    }

    public function testGetClientCachesPerStore(): void
    {
        $credentialsManager = $this->createMock(AlgoliaCredentialsManager::class);
        $credentialsManager->expects($this->once())
            ->method('checkCredentials')
            ->with(1)
            ->willReturn(true);

        $provider = $this->createObjectToTest(credentialsManager: $credentialsManager);

        $client1 = $provider->getClient(1);
        $client1Again = $provider->getClient(1);

        $this->assertSame($client1, $client1Again);
    }

    public function testGetClientReturnsDifferentClientsPerStore(): void
    {
        $storeIds = [];
        $credentialsManager = $this->createMock(AlgoliaCredentialsManager::class);
        $credentialsManager->expects($this->exactly(2))
            ->method('checkCredentials')
            ->with($this->callback(function (int $storeId) use (&$storeIds) {
                $storeIds[] = $storeId;
                return true;
            }))
            ->willReturn(true);

        $provider = $this->createObjectToTest(credentialsManager: $credentialsManager);

        $client1 = $provider->getClient(1);
        $client2 = $provider->getClient(2);

        $this->assertNotSame($client1, $client2);
        $this->assertEquals([1, 2], $storeIds);
    }

    public function testGetClientWithWrongRegion(): void
    {
        $ingestionConfigHelper = $this->createStub(IngestionConfigHelper::class);
        $ingestionConfigHelper->method('getRegion')->willReturn('jp');

        $credentialsManager = $this->createMock(AlgoliaCredentialsManager::class);
        $credentialsManager->expects($this->once())
            ->method('checkCredentials')
            ->with(1)
            ->willReturn(true);

        $provider = $this->createObjectToTest(
            credentialsManager: $credentialsManager,
            ingestionConfigHelper: $ingestionConfigHelper,
        );

        $this->expectException(AlgoliaException::class);
        $this->expectExceptionMessage('region` is required and must be one of the following: eu, us');

        $provider->getClient(1);
    }

    public function testGetClientWithEmptyRegion(): void
    {
        $ingestionConfigHelper = $this->createStub(IngestionConfigHelper::class);
        $ingestionConfigHelper->method('getRegion')->willReturn('');

        $credentialsManager = $this->createMock(AlgoliaCredentialsManager::class);
        $credentialsManager->expects($this->once())
            ->method('checkCredentials')
            ->with(1)
            ->willReturn(true);

        $provider = $this->createObjectToTest(
            credentialsManager: $credentialsManager,
            ingestionConfigHelper: $ingestionConfigHelper,
        );

        $this->expectException(AlgoliaException::class);
        $this->expectExceptionMessage('region` is required and must be one of the following: eu, us');

        $provider->getClient(1);
    }

    private function defaultConfig(): ConfigHelper&Stub
    {
        $config = $this->createStub(ConfigHelper::class);
        $config->method('getExtensionVersion')->willReturn('3.19.0');
        $config->method('getMagentoVersion')->willReturn('2.4.8');
        $config->method('getMagentoEdition')->willReturn('Community');
        $config->method('getApplicationID')->willReturn('test-app-id');
        $config->method('getAPIKey')->willReturn('test-api-key');
        $config->method('getConnectionTimeout')->willReturn(5);
        $config->method('getReadTimeout')->willReturn(10);
        $config->method('getWriteTimeout')->willReturn(30);
        return $config;
    }

    private function defaultIngestionConfigHelper(): IngestionConfigHelper&Stub
    {
        $ingestionConfigHelper = $this->createStub(IngestionConfigHelper::class);
        $ingestionConfigHelper->method('getRegion')->willReturn('us');
        return $ingestionConfigHelper;
    }
}
