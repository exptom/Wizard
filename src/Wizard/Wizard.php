<?php
namespace Wizard;

use Wizard\Exception;
use Zend\EventManager\EventManager;
use Zend\EventManager\Event;
use Zend\Form\Form;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\ServiceManager\ServiceManagerAwareInterface;
use Zend\ServiceManager\ServiceManager;
use Zend\Session\Container as SessionContainer;
use Zend\Session\ManagerInterface as SessionManager;

class Wizard implements WizardInterface, ServiceManagerAwareInterface
{
    const STEP_FORM_NAME = 'step';
    const SESSION_CONTAINER_PREFIX = 'wizard';
    const TOKEN_PARAM_NAME = 'uid';

    const EVENT_COMPLETE = 'wizard-complete';
    const EVENT_PRE_PROCESS_STEP = 'step-pre-process';
    const EVENT_POST_PROCESS_STEP = 'step-post-process';

    /**
     * @var string
     */
    protected $uid;

    /**
     * @var SessionManager
     */
    protected $sessionManager;

    /**
     * @var SessionContainer
     */
    protected $sessionContainer;

    /**
     * @var ServiceManager
     */
    protected $serviceManager;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var EventManager
     */
    protected $eventManager;

    /**
     * @var StepCollection
     */
    protected $steps;

    /**
     * @var Form
     */
    protected $form;

    /**
     * @var string
     */
    protected $redirectUrl;

    /**
     * {@inheritDoc}
     */
    public function setServiceManager(ServiceManager $serviceManager)
    {
        $this->serviceManager = $serviceManager;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setResponse(Response $response)
    {
        $this->response = $response;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setSessionManager(SessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getEventManager()
    {
        if (null === $this->eventManager) {
            $this->eventManager = new EventManager();
            $this->eventManager->attach(self::EVENT_COMPLETE, array($this, 'complete'));
        }

        return $this->eventManager;
    }

    /**
     * @return SessionContainer
     */
    protected function getSessionContainer()
    {
        if (null === $this->sessionContainer) {
            $this->sessionContainer = new SessionContainer(
                $this->getSessionContainerName(),
                $this->sessionManager
            );
        }

        return $this->sessionContainer;
    }

    /**
     * @return string
     */
    protected function getSessionContainerName()
    {
        return sprintf('%s_%s', self::SESSION_CONTAINER_PREFIX, $this->getUniqueid());
    }

    /**
     * @return string
     */
    protected function getUniqueid()
    {
        if (null === $this->uid) {
            if ($this->request->getQuery(self::TOKEN_PARAM_NAME)) {
                $this->uid = $this->request->getQuery(self::TOKEN_PARAM_NAME);
            } else {
                $this->uid = md5(uniqid(rand(), true));
            }
        }

        return $this->uid;
    }

    /**
     * @param  string|StepInterface $step
     * @return Wizard
     */
    protected function setCurrentStep($step)
    {
        if ($step instanceof StepInterface) {
            $step = $step->getName();
        }

        if (!$this->getSteps()->has($step)) {
            return $this;
        }
        
        $this->getSessionContainer()->currentStep = $step;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentStep()
    {
        $steps = $this->getSteps();
        if (count($steps) == 0) {
            return null;
        }

        $sessionContainer = $this->getSessionContainer();
        if (!isset($sessionContainer->currentStep) || !$steps->has($sessionContainer->currentStep)) {
            return $steps->getFirst();
        }

        return $steps->get($sessionContainer->currentStep);
    }

    /**
     * @return int
     */
    public function getCurrentStepNumber()
    {
        $currentStep = $this->getCurrentStep();
        $steps = $this->getSteps();

        $i = 1;
        foreach ($steps as $step) {
            if ($step->getName() == $currentStep->getName()) {
                break;
            }
            $i++;
        }

        return $i;
    }

    /**
     * {@inheritDoc}
     */
    public function getForm()
    {
        if (null === $this->form) {
            $currentStep = $this->getCurrentStep();
            if (!$currentStep) {
                return null;
            }

            $this->form = $this->serviceManager->get('Wizard\Form');
            $this->form->setAttribute('action', sprintf(
                '?%s=%s',
                self::TOKEN_PARAM_NAME,
                $this->getUniqueid()
            ));

            $stepForm = $currentStep->getForm();
            if ($stepForm instanceof Form) {
                $stepForm->setName(self::STEP_FORM_NAME);
                $stepForm->populateValues($currentStep->getData());
                $this->form->add($stepForm);
            }

            if (!$this->getSteps()->getPrevious($currentStep)) {
                $this->form->remove('previous');
            }

            if (!$this->getSteps()->getNext($currentStep)) {
                $this->form->remove('next');
            } else {
                $this->form->remove('valid');
            }
        }

        return $this->form;
    }

    /**
     * {@inheritDoc}
     */
    public function setSteps(StepCollection $steps)
    {
        $this->steps = $steps;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getSteps()
    {
        if (null === $this->steps) {
            $this->setSteps(new StepCollection());

            $sessionContainer = $this->getSessionContainer();
            $this->steps->getEventManager()->attach(StepCollection::EVENT_ADD_STEP, function(Event $e) use ($sessionContainer) {
                if (!isset($sessionContainer->steps)) {
                    return;
                }

                $step = $e->getTarget();
                $stepName = $step->getName();
                if (!isset($sessionContainer->steps->{$stepName})) {
                    return;
                }

                $step->setFromArray($sessionContainer->steps->{$stepName});
            });
        }

        return $this->steps;
    }

    /**
     * {@inheritDoc}
     */
    public function setRedirectUrl($url)
    {
        $this->redirectUrl = (string) $url;
        return $this;
    }

    /**
     * @return void
     */
    protected function doRedirect()
    {
        if (null === $this->redirectUrl) {
            throw new Exception\RuntimeException('You must provide url to redirect when wizard is complete.');
        }

        $this->response->getHeaders()->addHeaderLine('Location', $this->redirectUrl);
        $this->response->setStatusCode(302);
    }

    /**
     * {@inheritDoc}
     */
    public function process()
    {
        if (!$this->request->isPost()) {
            return;
        }

        $steps = $this->getSteps();
        $currentStep = $this->getCurrentStep();

        $post = $this->request->getPost();
        if (isset($post['previous']) && !$steps->isFirst($currentStep)) {
            $previousStep = $steps->getPrevious($currentStep);
            $this->setCurrentStep($previousStep);
        } else {
            $values = isset($post[self::STEP_FORM_NAME]) ? $post[self::STEP_FORM_NAME] : array();

            $this->getEventManager()->trigger(self::EVENT_PRE_PROCESS_STEP, $currentStep, array(
                'values' => $values,
            ));

            $complete = $currentStep->process($values);
            if (null !== $complete) {
                $currentStep->setComplete($complete);
            }

            $this->getEventManager()->trigger(self::EVENT_POST_PROCESS_STEP, $currentStep);

            if ($currentStep->isComplete()) {
                $currentStep->setData($values);

                if ($steps->isLast($currentStep)) {
                    $this->getEventManager()->trigger(self::EVENT_COMPLETE, $this);
                    $this->doRedirect();
                } else {
                    $nextStep = $steps->getNext($currentStep);
                    $this->setCurrentStep($nextStep);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function complete()
    {

    }

    public function __destruct()
    {
        if (empty($this->sessionContainer->steps)) {
            $this->sessionContainer->steps = array();
        }

        $steps = $this->getSteps();
        foreach ($steps as $step) {
            $this->sessionContainer->steps[$step->getName()] = $step->toArray();
        }
    }
}