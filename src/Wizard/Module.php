<?php
namespace Wizard;

use Wizard\Service\WizardInitializer;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\ModuleManager\Feature\ServiceProviderInterface;
use Zend\Session\SessionManager;

class Module implements
    ConfigProviderInterface,
    AutoloaderProviderInterface,
    ServiceProviderInterface
{
    public function getConfig()
    {
        return include __DIR__ . '/../../config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function getServiceConfig()
    {
        return array(
            'initializers' => array(
                new WizardInitializer(),
            ),
            'factories' => array(
                'Wizard\Factory' => function($sm) {
                    $initializer = new WizardInitializer();
                    return new WizardFactory($sm, $initializer);
                },
                'Session\Manager' => function($sm) {
                    $sessionStorage = $sm->get('Session\Storage');
                    return new SessionManager(null, $sessionStorage);
                },
            ),
        );
    }
}
