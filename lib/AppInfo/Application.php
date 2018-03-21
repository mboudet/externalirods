<?php

namespace OCA\ExternalIrods\AppInfo;

use OCP\AppFramework\App;
use OCP\Files\External\Config\IBackendProvider;

class Application extends App implements IBackendProvider {

  public function __construct(array $urlParams = array()) {
    parent::__construct('externalirods', $urlParams);

    $container = $this->getContainer();

    $backendService = $container->getServer()->getStoragesBackendService();
    $backendService->registerBackendProvider($this);
  }

  /**
   * @{inheritdoc}
   */
  public function getBackends() {
    $container = $this->getContainer();

    $backends = [
      $container->query('OCA\ExternalIrods\Backend\Irods'),
    ];

    return $backends;
  }
}
