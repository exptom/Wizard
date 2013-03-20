<?php
namespace Wizard;

use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Session\Container as SessionContainer;
use Zend\Session\SessionManager;
use Zend\Session\Storage\ArrayStorage as SessionStorage;

class WizardTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Wizard
     */
    protected $wizard;

    /**
     * @var SessionContainer
     */
    protected $sessionContainer;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    public function setUp()
    {
        $this->request = new Request;
        $this->response = new Response;
        
        $sessionManager = $this->getSessionManager();

        $this->wizard = $this->getMock('Wizard\Wizard', array('getSessionContainer'));
        $this->wizard
            ->setServiceManager($this->getServiceManagerMock())
            ->setRequest($this->request)
            ->setResponse($this->response)
            ->setSessionManager($sessionManager);

        $this->sessionContainer = new SessionContainer('foo', $sessionManager);

        $this->wizard
            ->expects($this->any())
            ->method('getSessionContainer')
            ->will($this->returnValue($this->sessionContainer));
    }

    public function testGetCurrentStep()
    {
        $this->assertNull($this->wizard->getCurrentStep());

        $steps = $this->wizard->getSteps();
        for ($i = 1; $i <= 3; $i++) {
            $step = $this->getStepMock('step' . $i);
            $steps->add($step);
        }

        $this->assertInstanceOf('Wizard\StepInterface', $this->wizard->getCurrentStep());
    }

    public function testGetSteps()
    {
        $this->assertInstanceOf('Wizard\StepCollection', $this->wizard->getSteps());
    }

    public function testGetFormWithoutSteps()
    {
        $this->assertNull($this->wizard->getForm());
    }

    public function testGetFormOfFirstStep()
    {
        $steps = $this->wizard->getSteps();
        $steps->add($this->getStepMock('foo'));
        $steps->add($this->getStepMock('bar'));

        $form = $this->wizard->getForm();
        $this->assertInstanceOf('Zend\Form\Form', $form);

        $this->assertFalse($form->has('previous'));
        $this->assertTrue($form->has('next'));
        $this->assertFalse($form->has('valid'));
    }

    public function testGetFormOfMiddleStep()
    {
        $this->sessionContainer->currentStep = 'bar';

        $steps = $this->wizard->getSteps();
        $steps->add($this->getStepMock('foo'));
        $steps->add($this->getStepMock('bar'));
        $steps->add($this->getStepMock('baz'));

        $form = $this->wizard->getForm();
        $this->assertInstanceOf('Zend\Form\Form', $form);

        $this->assertTrue($form->has('previous'));
        $this->assertTrue($form->has('next'));
        $this->assertFalse($form->has('valid'));
    }

    public function testGetFormOfLastStep()
    {
        $this->sessionContainer->currentStep = 'bar';

        $steps = $this->wizard->getSteps();
        $steps->add($this->getStepMock('foo'));
        $steps->add($this->getStepMock('bar'));

        $form = $this->wizard->getForm();
        $this->assertInstanceOf('Zend\Form\Form', $form);

        $this->assertTrue($form->has('previous'));
        $this->assertFalse($form->has('next'));
        $this->assertTrue($form->has('valid'));
    }

    public function testFormActionAttribute()
    {
        $steps = $this->wizard->getSteps();
        $steps->add($this->getStepMock('foo'));

        $form = $this->wizard->getForm();
        $action = $form->getAttribute('action');

        $this->assertStringMatchesFormat('?%s=%s', $action);
    }

    public function testSetStepDataDuringProcess()
    {
        $params = new \Zend\Stdlib\Parameters(array(
            'step' => array(
                'foo' => 123,
                'bar' => 456,
            ),
        ));
        $this->request
            ->setMethod(Request::METHOD_POST)
            ->setPost($params);
        
        $this->sessionContainer->currentStep = 'foo';

        $fooStep = $this->getStepMock('foo');
        $fooStep
            ->expects($this->any())
            ->method('isComplete')
            ->will($this->returnValue(true));

        $steps = $this->wizard->getSteps();
        $steps->add($fooStep);
        $steps->add($this->getStepMock('bar'));

        $this->wizard->process();

        $stepData = $fooStep->getData();
        $this->assertArrayHasKey('foo', $stepData);
        $this->assertArrayHasKey('bar', $stepData);
    }

    public function testCanGoToPreviousStep()
    {
        $params = new \Zend\Stdlib\Parameters(array('previous' => true));
        $this->request
            ->setMethod(Request::METHOD_POST)
            ->setPost($params);

        $this->sessionContainer->currentStep = 'bar';

        $steps = $this->wizard->getSteps();
        $steps->add($this->getStepMock('foo'));
        $steps->add($this->getStepMock('bar'));

        $this->wizard->process();

        $this->assertEquals('foo', $this->sessionContainer->currentStep);
    }

    public function testCanGoToNextStep()
    {
        $params = new \Zend\Stdlib\Parameters(array('step' => array()));
        $this->request
            ->setMethod(Request::METHOD_POST)
            ->setPost($params);

        $this->sessionContainer->currentStep = 'foo';

        $fooStep = $this->getStepMock('foo');
        $fooStep
            ->expects($this->any())
            ->method('isComplete')
            ->will($this->returnValue(true));

        $steps = $this->wizard->getSteps();
        $steps->add($fooStep);
        $steps->add($this->getStepMock('bar'));

        $this->wizard->process();

        $this->assertEquals('bar', $this->sessionContainer->currentStep);
    }

    public function testCanRedirectAfterLastStep()
    {
        $params = new \Zend\Stdlib\Parameters(array('step' => array()));
        $this->request
            ->setMethod(Request::METHOD_POST)
            ->setPost($params);

        $uri = '/foo';
        $this->wizard->setRedirectUrl($uri);

        $fooStep = $this->getStepMock('foo');
        $fooStep
            ->expects($this->any())
            ->method('isComplete')
            ->will($this->returnValue(true));

        $steps = $this->wizard->getSteps();
        $steps->add($fooStep);

        $this->wizard->process();

        $this->assertEquals(302, $this->response->getStatusCode());

        $headers = $this->response->getHeaders();
        /* @var $locationHeader \Zend\Http\Header\Location */
        $locationHeader = $headers->get('Location');

        $this->assertEquals($uri, $locationHeader->getUri());
    }

    public function testCurrentStepNumber()
    {
        $steps = $this->wizard->getSteps();
        $steps->add($this->getStepMock('foo'));
        $steps->add($this->getStepMock('bar'));        
        $this->assertEquals(1, $this->wizard->getCurrentStepNumber());

        $this->sessionContainer->currentStep = 'bar';
        $this->assertEquals(2, $this->wizard->getCurrentStepNumber());
    }

    public function testSetAndGetStepCollection()
    {
        $this->assertInstanceOf('Wizard\StepCollection', $this->wizard->getSteps());
    }

    /**
     * @param  string $name
     * @return StepInterface
     */
    protected function getStepMock($name)
    {
        $mock = $this->getMockForAbstractClass('Wizard\AbstractStep', array(), '', true, true, true, array('getName', 'isComplete'));
        $mock
            ->expects($this->any())
            ->method('getName')
            ->will($this->returnValue($name));

        return $mock;
    }

    /**
     * @return \Zend\ServiceManager\ServiceManager
     */
    public function getServiceManagerMock()
    {
        $form = new \Zend\Form\Form();
        $form
            ->add(new \Wizard\Form\Element\Button\Previous())
            ->add(new \Wizard\Form\Element\Button\Next())
            ->add(new \Wizard\Form\Element\Button\Valid());

        $serviceManager = $this->getMock('Zend\ServiceManager\ServiceManager');
        $serviceManager
            ->expects($this->any())
            ->method('get')
            ->will($this->returnValue($form));

        return $serviceManager;
    }

    /**
     * @return \Zend\Session\SessionManager
     */
    public function getSessionManager()
    {
        $sessionStorage = new SessionStorage;
        $sessionManager = new SessionManager(null, $sessionStorage);

        return $sessionManager;
    }
}