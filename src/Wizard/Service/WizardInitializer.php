<?php
namespace Wizard\Service;

use Wizard\WizardInterface;
use Zend\Session\SessionManager;
use Zend\ServiceManager\InitializerInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

class WizardInitializer implements InitializerInterface
{
    /**
     * @param  object $instance
     * @param  ServiceLocatorInterface $serviceLocator
     * @return void
     */
    public function initialize($instance, ServiceLocatorInterface $serviceLocator)
    {
        if ($instance instanceof WizardInterface) {
            $application = $serviceLocator->get('Application');

            $request = $application->getRequest();
            $response = $application->getResponse();

            $instance
                ->setServiceManager($serviceLocator)
                ->setRequest($request)
                ->setResponse($response);

            $renderer = new PhpRenderer;
            $renderer->setResolver($serviceLocator->get('ViewResolver'));
            $instance->setRenderer($renderer);
        }
    }
}
