<?php
/**
 * Upload Validation Behavior
 *
 * Validates file uploads
 *
 * PHP 5
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright 2005-2012, Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2005-2012, Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @package       Cake.Model.Behavior
 * @since         CakePHP(tm) v 2.2.0
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
App::uses('File', 'Utility');
/**
 * This behavior will validate uploaded files, nothing more, it won't take care of storage.
 *
 * @package       Cake.Model.Behavior
 */
class UploadValidatorBehavior extends ModelBehavior {

/**
 * Settings array
 *
 * @var array
 */
	public $settings = array();

/**
 * Default settings array
 *
 * @var array
 */
	protected $_defaults = array(
		'fileField' => 'file',
		'validate' => true,
		'allowNoFileError' => true,
		'allowedMime' => null,
		'allowedExtensions' => null,
		'localFile' => false
	);

/**
 * Error message
 *
 * If something fails this is populated with an error message that can be passed to the view
 *
 * @var string
 */
	public $uploadError = null;

/**
 * Behavior setup
 *
 * Merge settings with default config, then it is checking if the target directory
 * exists and if it is writeable. It will throw an error if one of both fails.
 *
 * @param \AppModel|\Model $Model
 * @param array $settings
 * @throws InvalidArgumentException
 * @return void
 */
	public function setup(Model $Model, $settings = array()) {
		if (!is_array($settings)) {
			throw new InvalidArgumentException(__d('FileStorage', 'Settings must be passed as array!'));
		}

		$this->settings[$Model->alias] = array_merge($this->_defaults, $settings);
	}

/**
 * Before validation callback
 *
 * Check if the file is really an uploaded file and run custom checks for file 
 * extensions and / or mime type if configured to do so.
 *
 * @param Model $Model
 * @return boolean True on success
 */
	public function beforeValidate(Model $Model) {
		extract($this->settings[$Model->alias]);
		if ($validate === true && isset($Model->data[$Model->alias][$fileField]) && is_array($Model->data[$Model->alias][$fileField])) {

			if ($Model->validateUploadError($Model->data[$Model->alias][$fileField]['error']) === false) {
				$Model->validationErrors[$fileField] = $this->uploadError;
				return false;
			}

			if (!empty($Model->data[$Model->alias][$fileField])) {
				if (empty($localFile) && !is_uploaded_file($Model->data[$Model->alias][$fileField]['tmp_name'])) {
					$this->uploadError = __d('FileStorage', 'The uploaded file is no valid upload.');
					$Model->invalidate($fileField, $this->uploadError);
					return false;
				}
			}

			if (is_array($allowedMime)) {
				if (!$this->validateAllowedMimeTypes($Model, $allowedMime)) {
					$Model->invalidate($fileField, $this->uploadError);
					return false;
				}
			}

			if (is_array($allowedExtensions)) {
				if (!$this->validateUploadExtension($Model, $allowedExtensions)) {
					return false;
				}
			}
		}
		return true;
	}

/**
 * Validates the extension
 *
 * @param Model $Model
 * @param $validExtensions
 * @return boolean True if the extension is allowed
 */
	public function validateUploadExtension(Model $Model, $validExtensions) {
		extract($this->settings[$Model->alias]);
		$extension = $this->fileExtension($Model, $Model->data[$Model->alias][$fileField]['name'], false);

		if (!in_array(strtolower($extension), $validExtensions)) {
			$this->uploadError = __d('FileStorage', 'You are not allowed to upload files of this type.');
			$Model->invalidate($fileField, $this->uploadError);
			return false;
		}
		return true;
	}

/**
 * Validates if the mime type of an uploaded file is allowed
 *
 * @param Model $Model
 * @param array Array of allowed mime types
 * @return boolean
 */
	public function validateAllowedMimeTypes(Model $Model, $mimeTypes = array()) {
		extract($this->settings[$Model->alias]);
		if (!empty($mimeTypes)) {
			$allowedMime = $mimeTypes;
		}

		$File = new File($Model->data[$Model->alias][$fileField]['tmp_name']);
		$mimeType = $File->mime();

		if (!in_array($mimeType, $allowedMime)) {
			$this->uploadError = __d('FileStorage', 'You are not allowed to upload files of this type.');
			$Model->invalidate($fileField, $this->uploadError);
			return false;
		}
		return true;
	}

/**
 * Valdates the error value that comes with the file input file
 *
 * @param Model $Model
 * @param integer Error value from the form input [file_field][error]
 * @return boolean True on success, if false the error message is set to the models field and also set in $this->uploadError
 */
	public function validateUploadError(Model $Model, $error = null) {
		if (!is_null($error)) {
			switch ($error) {
				case UPLOAD_ERR_OK:
					return true;
				break;
				case UPLOAD_ERR_INI_SIZE:
					$this->uploadError = __d('FileStorage', 'The uploaded file exceeds limit of (' . ini_get('upload_max_filesize') . ').');
				break;
				case UPLOAD_ERR_FORM_SIZE:
					$this->uploadError = __d('FileStorage', 'The uploaded file is to big, please choose a smaller file or try to compress it.');
				break;
				case UPLOAD_ERR_PARTIAL:
					$this->uploadError = __d('FileStorage', 'The uploaded file was only partially uploaded.');
				break;
				case UPLOAD_ERR_NO_FILE:
					if ($this->settings[$Model->alias]['allowNoFileError'] === false) {
						$this->uploadError = __d('FileStorage', 'No file was uploaded.');
					}
					return true;
				break;
				case UPLOAD_ERR_NO_TMP_DIR:
					$this->uploadError = __d('FileStorage', 'The remote server has no temporary folder for file uploads. Please contact the site admin.');
				break;
				case UPLOAD_ERR_CANT_WRITE:
					$this->uploadError = __d('FileStorage', 'Failed to write file to disk. Please contact the site admin.');
				break;
				case UPLOAD_ERR_EXTENSION:
					$this->uploadError = __d('FileStorage', 'File upload stopped by extension. Please contact the site admin.');
				break;
				default:
					$this->uploadError = __d('FileStorage', 'Unknown File Error. Please contact the site admin.');
				break;
			}
			return false;
		}
		return true;
	}

/**
 * Returns the latest error message
 *
 * @param \AppModel|\Model $Model
 * @return string
 * @access public
 */
	public function uploadError(Model $Model) {
		return $this->uploadError;
	}

/**
 * Returns an array that matches the structure of a regular upload for a local file
 *
 * @param Model $Model
 * @param $file
 * @param string File with path
 * @return array Array that matches the structure of a regular upload
 */
	public function uploadArray(Model $Model, $file, $filename = null) {
		$File = new File($file);

		if (empty($fileName)) {
			$filename = basename($file);
		}

		return array(
			'name' => $filename,
			'tmp_name' => $file,
			'error' => 0,
			'type' => $File->mime(),
			'size' => $File->size());
	}

/**
 * Return file extension from a given filename
 *
 * @param Model $Model
 * @param $name
 * @param bool $realFile
 * @internal param $string
 * @return boolean string or false
 */
	public function fileExtension(Model $Model, $name, $realFile = true) {
		if ($realFile) {
			return pathinfo($name, PATHINFO_EXTENSION);
		}
		return substr(strrchr($name,'.'), 1);
	}

}