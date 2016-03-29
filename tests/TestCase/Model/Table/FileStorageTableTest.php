<?php
namespace Burzum\FileStorage\Test\TestCase\Model\Table;

use Burzum\FileStorage\Test\TestCase\FileStorageTestCase;
use Cake\Core\Plugin;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;

/**
 * File Storage Test
 *
 * @author Florian Krämer
 * @copyright 2012 - 2016 Florian Krämer
 * @license MIT
 */
class FileStorageTableTest extends FileStorageTestCase {

	/**
	 * Fixtures
	 *
	 * @var array
	 */
	public $fixtures = [
		'plugin.Burzum\FileStorage.FileStorage'
	];

	/**
	 * startTest
	 *
	 * @return void
	 */
	public function setUp() {
		parent::setUp();
		$this->FileStorage = TableRegistry::get('Burzum/FileStorage.FileStorage');
	}

	/**
	 * endTest
	 *
	 * @return void
	 */
	public function tearDown() {
		parent::tearDown();
		unset($this->FileStorage);
		unset($this->FileStorageBehavior);
		TableRegistry::clear();
	}

	/**
	 * testInitialization
	 *
	 * @return void
	 */
	public function testInitialize() {
		$this->assertEquals($this->FileStorage->table(), 'file_storage');
		$this->assertEquals($this->FileStorage->displayField(), 'filename');
	}

	/**
	 * Testing a complete save call
	 *
	 * @link https://github.com/burzum/cakephp-file-storage/issues/85
	 * @return void
	 */
	public function testFileSaving() {
		$listenersToTest = [
			'LocalListener',
		];
		$results = [];
		foreach ($listenersToTest as $listener) {
			$this->_removeListeners();
			EventManager::instance()->on($this->listeners[$listener]);
			$entity = $this->FileStorage->newEntity([
				'model' => 'Document',
				'adapter' => 'Local',
				'file' => [
					'error' => UPLOAD_ERR_OK,
					'size' => filesize($this->fileFixtures . 'titus.jpg'),
					'type' => 'image/jpeg',
					'name' => 'tituts.jpg',
					'tmp_name' => $this->fileFixtures . 'titus.jpg'
				]
			], ['accessibleFields' => ['*' => true]]);
			$this->FileStorage->configureUploadValidation([
				'allowedExtensions' => ['jpg'],
				'validateUploadArray' => true,
				'localFile' => true,
				'validateUploadErrors' => true
			]);
			$this->FileStorage->save($entity);
			$this->assertEquals($entity->errors(), []);
			$results[] = $entity;
		}
	}

}
