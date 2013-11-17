<?php
App::uses('CakeEventListener', 'Event');
/**
 * Image Processor Event Listener for the CakePHP FileStorage plugin
 *
 * This listener currently supports the following adapters
 * - Local
 * - AmazonS3
 *
 * @todo I don't like this file very much, find a better way to deal with the handling of all the different adapters in this code
 * @author Florian Krämer
 * @copy 2013 Florian Krämer
 * @license MIT
 */
class ImageProcessingListener extends Object implements CakeEventListener {

/**
 * The adapter class
 *
 * @param null|string
 */
	public $adapterClass = null;

	public $options = array();

/**
 * Constructor
 *
 * @param array $options
 * @return ImageProcessingListener
 */
	public function __construct($options = array()) {
		$defaults = array(
			'preserveFilename' => false
		);
		$this->options = array_merge($defaults, $options);
	}

/**
 * Implemented Events
 *
 * @return array
 */
	public function implementedEvents() {
		return array(
			'ImageVersion.createVersion' => 'createVersions',
			'ImageVersion.removeVersion' => 'removeVersions',
			'ImageStorage.afterSave' => 'afterSave',
			'ImageStorage.afterDelete' => 'afterDelete',
			'FileStorage.ImageHelper.imagePath' => 'imagePath',
		);
	}

/**
 * Creates the different versions of images that are configured
 *
 * @param Model $Model
 * @param array $record
 * @param array $operations
 * @throws Exception
 * @return void
 */
	protected function _createVersions($Model, $record, $operations) {
		$Storage = StorageManager::adapter($record['adapter']);
		$path = $this->_buildPath($record, true);
		$tmpFile = $this->_tmpFile($Storage, $path);

		foreach ($operations as $version => $imageOperations) {
			$hash = $Model->hashOperations($imageOperations);
			$string = $this->_buildPath($record, true, $hash);

			if ($this->adapterClass === 'AmazonS3') {
				$string = str_replace('\\', '/', $string);
			}

			if ($Storage->has($string)) {
				return false;
			}

			try {
				$image = $Model->processImage($tmpFile, null, array('format' => $record['extension']), $imageOperations);
				$result = $Storage->write($string, $image->get($record['extension']), true);
			} catch (Exception $e) {
				$this->log($e->getMessage(), 'file_storage');
				unlink($tmpFile);
				throw $e;
			}
		}

		unlink($tmpFile);
	}

/**
 * Creates versions for a given image record
 *
 * @param CakeEvent $Event
 * @return void
 */
	public function createVersions($Event) {
		if ($this->_checkEvent($Event)) {
			$Model = $Event->subject();

			$record = $Event->data['record'][$Model->alias];
			$this->_createVersions($Model, $record, $Event->data['operations']);

			$Event->stopPropagation();
		}
	}

/**
 * Removes versions for a given image record
 *
 * @param CakeEvent $Event
 * @return void
 */
	protected function _removeVersions($Event) {
		if ($this->_checkEvent($Event)) {
			$Model = $Event->subject();
			$Storage = $Event->data['storage'];
			$record = $Event->data['record'][$Model->alias];

			foreach ($Event->data['operations'] as $version => $operations) {
				$hash = $Model->hashOperations($operations);
				$string = $this->_buildPath($record, true, $hash);

				if ($this->adapterClass === 'AmazonS3') {
					$string = str_replace('\\', '/', $string);
				}

				try {
					if ($Storage->has($string)) {
						$Storage->delete($string);
					}
				} catch (Exception $e) {
					$this->log($e->getMessage(), 'file_storage');
				}
			}

			$Event->stopPropagation();
		}
	}

/**
 * afterDelete
 *
 * @param CakeEvent $Event
 * @return void
 */
	public function afterDelete($Event) {
		if ($this->_checkEvent($Event)) {
			$Model = $Event->subject();
			$record = $Event->data['record'][$Model->alias];

			$string = $this->_buildPath($record, true, null);

			if ($this->adapterClass === 'AmazonS3') {
				$string = str_replace('\\', '/', $string);
			}

			try {
				$Storage = StorageManager::adapter($record['adapter']);
				if (!$Storage->has($string)) {
					return false;
				}
				$Storage->delete($string);
			} catch (Exception $e) {
				$this->log($e->getMessage(), 'file_storage');
				return false;
			}

			$operations = Configure::read('Media.imageSizes.' . $record['model']);

			if (!empty($operations)) {
				$Event->data['operations'] = $operations;
				$this->_removeVersions($Event);
			}

			return true;
		}
	}

/**
 * afterSave
 *
 * @param CakeEvent $Event
 * @return void
 */
	public function afterSave($Event) {
		if ($this->_checkEvent($Event)) {
			$Model = $Event->subject();
			$Storage = StorageManager::adapter($Model->data[$Model->alias]['adapter']);
			$record = $Model->data[$Model->alias];

			try {
				$id = $record[$Model->primaryKey];
				$filename = $Model->stripUuid($id);
				$file = $record['file'];
				$record['path'] = $Model->fsPath('images' . DS . $record['model'], $id);

				if ($this->options['preserveFilename'] === true) {
					$path = $record['path'] . $record['filename'];
				} else {
					$path = $record['path'] . $filename . '.' . $record['extension'];
				}

				if ($this->adapterClass === 'AmazonS3') {
					$path = str_replace('\\', '/', $path);
					$record['path'] = str_replace('\\', '/', $record['path']);
				}

				$result = $Storage->write($path, file_get_contents($file['tmp_name']), true);

				$data = $Model->save(array($Model->alias => $record), array(
					'validate' => false,
					'callbacks' => false));

				$operations = Configure::read('Media.imageSizes.' . $record['model']);
				if (!empty($operations)) {
					$this->_createVersions($Model, $record, $operations);
				}

				$Model->data = $data;
			} catch (Exception $e) {
				$this->log($e->getMessage(), 'file_storage');
			}
		}
	}

/**
 * Generates the path the image url / path for viewing it in a browser depending on the storage adapter
 *
 * @param CakeEvent $Event
 * @throws RuntimeException
 * @return void
 */
	public function imagePath($Event) {
		extract($Event->data);

		if (!isset($Event->data['image']['adapter'])) {
			throw new \RuntimeException(__d('file_storage', 'No adapter config key passed!'));
		}

		$adapterClass = $this->getAdapterClassName($Event->data['image']['adapter']);
		$buildMethod = '_build' . $adapterClass . 'Path';

		if (method_exists($this, $buildMethod)) {
			return $this->$buildMethod($Event);
		}

		throw new \RuntimeException(__d('file_storage', 'No callback image url callback implemented for adapter %s', $adapterClass));
	}

/**
 * Builds an url to the given image
 *
 * @param CakeEvent $Event
 * @return void
 */
	protected function _buildLocalPath($Event) {
		extract($Event->data);
		$path = $this->_buildPath($image, true, $hash);
		$Event->data['path'] = '/' . $path;
		$Event->stopPropagation();
	}

/**
 * Builds an url to the given image for the amazon s3 adapter
 *
 * http(s)://<bucket>.s3.amazonaws.com/<object>
 * http(s)://s3.amazonaws.com/<bucket>/<object>
 *
 * @param CakeEvent $Event
 * @return void
 */
	protected function _buildAmazonS3Path($Event) {
		extract($Event->data);

		$path = $this->_buildPath($image, true, $hash);
		$image['path'] = '/' . $path;

		$config = StorageManager::config($Event->data['image']['adapter']);
		$bucket = $config['adapterOptions'][1];
		$cfDist = $config['cloudFrontUrl'];

		$http = 'http';
		if (!empty($Event->data['options']['ssl']) && $Event->data['options']['ssl'] === true) {
			$http = 'https';
		}

		$image['path'] = str_replace('\\', '/', $image['path']);
		$bucketPrefix = !empty($Event->data['options']['bucketPrefix']) && $Event->data['options']['bucketPrefix'] === true;

		$Event->data['path'] = $this->_buildCloudFrontDistributionUrl($http, $image['path'], $bucket, $bucketPrefix, $cfDist);
		$Event->stopPropagation();
	}

/**
 * Builds an url to serve content from cloudfront
 *
 * @param string $protocol
 * @param string $image
 * @param string $bucket
 * @param string null $bucketPrefix
 * @param string $cfDist
 * @return string
 */
	protected function _buildCloudFrontDistributionUrl($protocol, $image, $bucket, $bucketPrefix = null, $cfDist = null){
		$path = $protocol . '://';
		if ($cfDist){
			$path .= $cfDist;
		} else {
			if($bucketPrefix){
				$path .= $bucket . '.s3.amazonaws.com';
			}
			else{
				$path .= 's3.amazonaws.com/' . $bucket;
			}
		}
		$path .= $image;

		return $path;
	}

/**
 * It is required to get the file first and write it to a tmp file
 *
 * The adapter might not be one that is using a local file system, so we first
 * get the file from the storage system, store it locally in a tmp file and
 * later load the new file that was generated based on the tmp file into the
 * storage adapter. This method here just generates the tmp file.
 *
 * @param $Storage
 * @param $path
 * @throws Exception
 * @return bool|string
 */
	protected function _tmpFile($Storage, $path) {
		try {
			if (!is_dir(TMP . 'image-processing')) {
				mkdir(TMP . 'image-processing');
			}

			$tmpFile = TMP . 'image-processing' . DS . String::uuid();
			$imageData = $Storage->read($path);

			file_put_contents($tmpFile, $imageData);
			return $tmpFile;
		} catch (Exception $e) {
			throw $e;
		}
	}

/**
 * Builds a path to a file
 *
 * @param array $image
 * @param boolean $extension
 * @param string $hash
 * @return string
 */
	protected function _buildPath($image, $extension = true, $hash = null) {
		if ($this->options['preserveFilename'] === true) {
			if (!empty($hash)) {
				$path = $image['path'] .  preg_replace('/\.[^.]*$/', '', $image['filename']) . '.' . $hash . '.' . $image['extension'];
			} else {
				$path = $image['path'] . $image['filename'];
			}
		} else {
			$path = $image['path'] . str_replace('-', '', $image['id']);
			if (!empty($hash)) {
				$path .= '.' . $hash;
			}
			if ($extension == true) {
				$path .= '.' . $image['extension'];
			}
		}
		return $path;
	}

/**
 * Check if the event is of a type / model we want to process with this listener
 *
 * @param CakeEvent $Event
 * @return boolean
 */
	protected function _checkEvent($Event) {
		$Model = $Event->subject();
		if (!$Model instanceOf ImageStorage || (!isset($Event->data['record'][$Model->alias]['adapter']) && !isset($Event->data['record']['adapter']))) {
			return false;
		}

		if ($this->getAdapterClassName($Event->data['record'][$Model->alias]['adapter'])) {
			return true;
		}
		return false;
	}

/**
 * Gets the adapter class name from the adapter configuration key
 *
 * @param string
 * @return void
 */
	public function getAdapterClassName($adapterConfigName) {
		$config = StorageManager::config($adapterConfigName);

		switch ($config['adapterClass']) {
			case '\Gaufrette\Adapter\Local':
				$this->adapterClass = 'Local';
				return $this->adapterClass;
			case '\Gaufrette\Adapter\AmazonS3':
				$this->adapterClass = 'AmazonS3';
				return $this->adapterClass;
			default:
				return false;
		}
	}

}
