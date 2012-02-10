<?php
/**
 * Crud component
 *
 * Handles the automatic transformation of HTTP requests to API responses
 *
 * Copyright 2010-2012, Nodes ApS. (http://www.nodesagency.com/)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @see https://wiki.ournodes.com/display/platform/Api+Plugin
 * @see http://book.cakephp.org/2.0/en/controllers/components.html#Component
 * @copyright Nodes ApS, 2012
 */
class CrudComponent extends Component {

	/**
	* Components used internally
	*
	* @var array
	*/
	public $components = array('Session');

	/**
	* A map of the controller action and what CRUD action we should call
	*
	* By default it supports non-prefix and admin_ prefixed routes
	*
	* @platform
	* @var array
	*/
	protected $actionMap = array(
		'index'			=> 'index',
		'add'			=> 'add',
		'edit'			=> 'edit',
		'view'			=> 'view',
		'delete'		=> 'delete',

		'admin_index'	=> 'index',
		'admin_add'		=> 'add',
		'admin_edit'	=> 'edit',
		'admin_view'	=> 'view',
		'admin_delete'	=> 'delete'
	);

	/**
	* A map of the controller action and the view to render
	*
	* By default it supports non-prefix and admin_ prefixed routes
	*
	* @platform
	* @var array
	*/
	protected $viewMap = array(
		'index'			=> 'index',
		'add'			=> 'form',
		'edit'			=> 'form',
		'view'			=> 'view',

		'admin_index'	=> 'admin_index',
		'admin_add'		=> 'admin_form',
		'admin_edit'	=> 'admin_form',
		'admin_view'	=> 'admin_view'
	);

	/**
	* Make sure to update the list of known controller methods before startup is called
	*
	* The reason for this is that if we don't, the Auth component won't execute any callbacks on the controller
	* like isAuthorized
	*
	* @param Controller $controller
	* @return void
	*/
	public function initialize(Controller $controller) {
		$controller->methods = array_keys(array_flip($controller->methods) + array_flip($this->settings['actions']));

		// Create some easy accessible class properties
		$this->action 		= $controller->request->action;
		$this->controller	= $controller;
		$this->request 		= $controller->request;
		$this->eventManager = $controller->getEventManager();
	}

	/**
	* The startup method is called after the controller’s beforeFilter method
	* but before the controller executes the current action handler.
	*
	* We catch our CRUD actions here before Cake complains about them
	* not existing in the Controller
	*
	* @cakephp
	* @param Controller $controller
	* @return void
	*/
	public function startup(Controller $controller) {
		if ($controller->name == 'CakeError') {
			return true;
		}

		// Don't do anything if the action is defined in the controller already
		if (method_exists($controller, $this->action)) {
			return;
		}

		// Don't do anything if the action haven't been marked as CRUD
		if (!in_array($this->action, $this->settings['actions'])) {
			return;
		}

		// Don't do anything if the action isn't mapped
		if (!array_key_exists($this->action, $this->actionMap)) {
			return;
		}

		$this->executeAction();
	}

	/**
	* Execute a Crud action
	*
	* @platform
	* @param string $action		The CRUD action
	* @param array $arguments	List of arguments to pass to the CRUD action (Usually an ID to edit / delete)
	* @return void
	*/
	public function executeAction($action = null, $args = array()) {
		// Always attach the default callback object
		$this->eventManager->attach(new Crud\Event\Base());
		$this->eventManager->dispatch(new CakeEvent('Crud.init', $this, $this->getSubject()));

		$this->modelName = $this->controller->modelClass;
		$this->model 	 = $this->controller->{$this->modelName};

		$action = $action ?: $this->action;

		// Execute the default action, inside this component
		call_user_func_array(array($this, $this->actionMap[$this->action] . 'Action'), $args);

		$view = $this->action;
		if (array_key_exists($this->action, $this->viewMap)) {
			$view = $this->viewMap[$this->action];
		}

		// Render the file based on action name
		$content = $this->controller->render($view);

		// Send the content to the browser
		$content->send();

		// Stop the request
		$this->_stop();
	}

	/**
	* Enable a CRUD action
	*
	* @platform
	* @param string $action The action to enable
	* @return void
	*/
	public function enableAction($action) {
		$pos = array_search($action, $this->settings['actions']);
		if (false === $pos) {
			$this->settings['actions'][] = $action;
		}

		$pos = array_search($action, $this->controller->methods);
		if (false === $pos) {
			$this->controller->methods[] = $action;
		}
	}

	/**
	* Disable a CRUD action
	*
	* @platform
	* @param string $action The action to disable
	* @return void
	*/
	public function disableAction($action) {
		$pos = array_search($action, $this->settings['actions']);
		if (false !== $pos) {
			unset($this->settings['actions'][$pos]);
		}

		$pos = array_search($action, $this->controller->methods);
		if (false !== $pos) {
			unset($this->controller->methods[$post]);
		}
	}

	/**
	* Map the view file to use for a controller action
	*
	* To map multiple action views in one go pass an array as first argument and no second argument
	*
	* @platform
	* @param string|array $action
	* @param string $view
	* @return void
	*/
	public function mapActionView($action, $view = null) {
		if (is_array($action)) {
			$this->viewMap = $this->viewMap + $action;
			return;
		}

		$this->viewMap[$action] = $view;
	}

	/**
	* Helper method to get the passed ID to an action
	*
	* @platform
	* @return string
	*/
	public function getIdFromRequest() {
		if (empty($this->request->params['pass'][0])) {
			return null;
		}
		return $this->request->params['pass'][0];
	}

	/**
	* Create a CakeEvent subject with the required properties
	*
	* @param array $additional Additional properties for the subject
	* @return \Crud\Subject
	*/
	protected function getSubject($additional = array()) {
		$subject = new Crud\Subject();
		$subject->self			= $this;
		$subject->controller	= $this->controller;
		$subject->model 		= $this->model;
		$subject->action		= $this->action;
		$subject->request		= $this->request;
		$subject->set($additional);

		return $subject;
	}

	/**
	* Generic index action
	*
	* Triggers the following callbacks
	*  - Crud.init
	*  - Crud.beforePaginate
	*  - Crud.afterPaginate
	*  - Crud.beforeRender
	*
	* @platform
	* @param string $id
	* @return void
	*/
	protected function indexAction() {
		$this->eventManager->dispatch(new CakeEvent('Crud.beforePaginate', $this->getSubject()));

		$items = $this->controller->paginate();

		$this->eventManager->dispatch(new CakeEvent('Crud.afterPaginate', $subject = $this->getSubject(compact('items'))));
		$items = $subject->items;

		$this->controller->set(compact('items'));
		$this->eventManager->dispatch(new CakeEvent('Crud.beforeRender', $this->getSubject()));
	}

	/**
	* Generic add action
	*
	* Triggers the following callbacks
	*  - Crud.init
	*  - Crud.beforeSave
	*  - Crud.afterSave
	*  - Crud.beforeRender
	*
	* @platform
	* @param string $id
	* @return void
	*/
	protected function addAction() {
		if ($this->request->is('post')) {
			$this->eventManager->dispatch(new CakeEvent('Crud.beforeSave', $this->getSubject()));
			if ($this->model->saveAll($this->request->data, array('validate' => 'first', 'atomic' => true))) {
				$this->eventManager->dispatch(new CakeEvent('Crud.afterSave', $this->getSubject(array('success' => true, 'id' => $this->model->id))));
				$this->Session->setFlash(__d('common', 'Succesfully created %s', Inflector::humanize($this->modelName)), 'flash/success');
				$this->redirect(array('action' => 'index'));
			} else {
				$this->eventManager->dispatch(new CakeEvent('Crud.afterSave', $this->getSubject(array('success' => false))));
				$this->Session->setFlash(__d('common', 'Could not create %s', Inflector::humanize($this->modelName)), 'flash/error');
				// Make sure to merge any changed data in the model into the post data
				$this->request->data = Set::merge($this->request->data, $this->model->data);
			}
		}

		$this->eventManager->dispatch(new CakeEvent('Crud.beforeRender', $this->getSubject()));
	}

	/**
	* Generic edit action
	*
	* Triggers the following callbacks
	*  - Crud.init
	*  - Crud.beforeSave
	*  - Crud.afterSave
	*  - Crud.beforeFind
	*  - Crud.recordNotFound
	*  - Crud.afterFind
	*  - Crud.beforeRender
	*
	* @platform
	* @param string $id
	* @return void
	*/
	protected function editAction($id = null) {
		if (empty($id)) {
			$id = $this->getIdFromRequest();
		}
		$this->validateId($id);

		if ($this->request->is('put')) {
			$this->eventManager->dispatch(new CakeEvent('Crud.beforeSave', $this->getSubject(compact('id'))));
			if ($this->model->saveAll($this->request->data, array('validate' => 'first', 'atomic' => true))) {
				$this->eventManager->dispatch(new CakeEvent('Crud.afterSave', $this->getSubject(array('id' => $id, 'success' => true))));
				$this->Session->setFlash(__d('common', '%s was succesfully updated', ucfirst(Inflector::humanize($this->modelName))), 'flash/success');
				$this->redirect(array('action' => 'index'));
			} else {
				$this->eventManager->dispatch(new CakeEvent('Crud.afterSave', $this->getSubject(array('id' => $id, 'success' => false))));
				$this->Session->setFlash(__d('common', 'Could not update %s', Inflector::humanize($this->modelName)), 'flash/error');
			}
		} else {
			$query = array();
			$query['conditions'] = array($this->model->escapeField() => $id);
			$this->eventManager->dispatch(new CakeEvent('Crud.beforeFind', $subject = $this->getSubject(compact('query'))));
			$query = $subject->query;

			$this->request->data = $this->model->find('first', $query);
			if (empty($this->request->data)) {
				$this->eventManager->dispatch(new CakeEvent('Crud.recordNotFound', $this->getSubject(compact('id'))));
				$this->Session->setFlash(__d('common', 'Could not find %s', Inflector::humanize($this->modelName)), 'flash/error');
				$this->redirect(array('action' => 'index'));
			}

			$this->eventManager->dispatch(new CakeEvent('Crud.afterFind', $this->getSubject(compact('id'))));

			// Make sure to merge any changed data in the model into the post data
			$this->request->data = Set::merge($this->request->data, $this->model->data);
		}

		// Trigger a beforeRender
		$this->eventManager->dispatch(new CakeEvent('Crud.beforeRender', $this->getSubject()));
	}

	/**
	* Generic view action
	*
	* Triggers the following callbacks
	*  - Crud.init
	*  - Crud.beforeFind
	*  - Crud.recordNotFound
	*  - Crud.afterFind
	*  - Crud.beforeRender
	*
	* @platform
	* @param string $id
	* @return void
	*/
	protected function viewAction($id = null) {
		if (empty($id)) {
			$id = $this->getIdFromRequest();
		}

		$this->validateId($id);

		// Build conditions
		$query = array();
		$query['conditions'] = array($this->model->escapeField() => $id);
		$this->eventManager->dispatch(new CakeEvent('Crud.beforeFind', $subject = $this->getSubject(compact('id', 'query'))));
		$query = $subject->query;

		// Try and find the database record
		$item = $this->model->find('first', $query);

		// We could not find any record match the conditions in query
		if (empty($item)) {
			$this->eventManager->dispatch(new CakeEvent('Crud.recordNotFound', $this->getSubject(compact('id'))));
			$this->Session->setFlash(__d('common', 'Could not find %s', Inflector::humanize($this->modelName)), 'flash/error');
			$this->redirect(array('action' => 'index'));
		}

		// We found a record, trigger an afterFind
		$this->eventManager->dispatch(new CakeEvent('Crud.afterFind', $subject = $this->getSubject(compact('id', 'item'))));
		$item = $subject->item;

		// Push it to the view
		$this->controller->set(compact('item'));

		// Trigger a beforeRender
		$this->eventManager->dispatch(new CakeEvent('Crud.beforeRender', $this->getSubject(compact('id', 'item'))));
	}

	/**
	* Generic delete action
	*
	* Triggers the following callbacks
	*  - beforeFind
	*  - recordNotFound
	*  - beforeDelete
	*  - afterDelete
	*
	* @platform
	* @param string $id
	* @return void
	*/
	protected function deleteAction($id = null) {
		if (empty($id)) {
			$id = $this->getIdFromRequest();
		}

		$this->validateId($id);
		$query = array();
		$query['conditions'] = array($this->model->escapeField() => $id);
		$this->eventManager->dispatch(new CakeEvent('Crud.beforeFind', $subject = $this->getSubject(compact('id', 'query'))));
		$query = $subject->query;

		$count = $this->model->find('count', $query);
		if (empty($count)) {
			$this->eventManager->dispatch(new CakeEvent('Crud.recordNotFound', $this->getSubject(compact('id'))));
			$this->Session->setFlash(__d('common', 'Could not find %s', Inflector::humanize($this->modelName)), 'flash/error');
			$this->redirect(array('action' => 'index'));
		}

		$this->eventManager->dispatch($event = new CakeEvent('Crud.beforeDelete', $this->getSubject(compact('id'))));
		if ($event->isStopped()) {
			$this->Session->setFlash(__d('common', 'Could not delete %s', Inflector::humanize($this->modelName)), 'flash/error');
			$this->redirect(array('action' => 'index'));
			return;
		}

		if ($this->request->is('delete')) {
			if ($this->model->delete($id)) {
				$this->Session->setFlash(__d('common', 'Successfully deleted %s', Inflector::humanize($this->modelName)), 'flash/success');
				$this->eventManager->dispatch(new CakeEvent('Crud.afterDelete', $this->getSubject(array('id' => $id, 'success' => true))));
			} else {
				$this->Session->setFlash(__d('common', 'Could not delete %s', Inflector::humanize($this->modelName)), 'flash/error');
				$this->eventManager->dispatch(new CakeEvent('Crud.afterDelete', $this->getSubject(array('id' => $id, 'success' => false))));
			}
		} else {
			$this->Session->setFlash(__d('common', 'Invalid HTTP request', Inflector::humanize($this->modelName)), 'flash/error');
		}

		$this->redirect(array('action' => 'index'));
	}

	/**
	* Called for all redirects inside CRUD
	*
	* @param array|null $url
	* @return void
	*/
	protected function redirect($url = null) {
		$this->eventManager->dispatch(new CakeEvent('Crud.beforeRedirect', $subject = $this->getSubject(compact('url'))));
		$url = $subject->url;

		$this->controller->redirect($url);
	}

	/**
	* Is the passed ID valid ?
	*
	* By default we asume you want to validate an UUID string
	*
	* Change the validateId settings key to "integer" for is_numeric check instead
	*
	* @return boolean
	*/
	protected function validateId($id) {
		if (empty($this->settings['validateId']) || $this->settings['validateId'] === 'uuid') {
			$valid = Validation::uuid($id);
		} else {
			$valid = is_numeric($id);
		}

		if (!$valid) {
			$this->eventManager->dispatch(new CakeEvent('Crud.invalidId', $this->getSubject(compact('id'))));
			$this->redirect($this->controller->referer());
		}
	}
}