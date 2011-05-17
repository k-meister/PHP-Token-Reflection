<?php
/**
 * PHP Token Reflection
 *
 * Version 1.0beta1
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this library in the file license.txt.
 *
 * @author Ondřej Nešpor <andrew@andrewsville.cz>
 * @author Jaroslav Hanslík <kukulich@kukulich.cz>
 */

namespace TokenReflection\Broker\Backend;
use TokenReflection;

use TokenReflection\Exception, TokenReflection\Broker, TokenReflection\Php, TokenReflection\Dummy;

/**
 * Memory broker backend.
 *
 * Stores parsed reflection objects in memory.
 */
class Memory implements Broker\Backend
{
	/**
	 * Namespaces storage.
	 *
	 * @var array
	 */
	private $namespaces = array();

	/**
	 * All tokenized classes cache.
	 *
	 * @var array
	 */
	private $allClasses;

	/**
	 * Token streams storage.
	 *
	 * @var array
	 */
	private $tokenStreams = array();

	/**
	 * Reflection broker.
	 *
	 * @var \TokenReflection\Broker
	 */
	private $broker;

	/**
	 * Determines if token streams are stored within the backend.
	 *
	 * @var boolean
	 */
	private $storingTokenStreams;

	/**
	 * Returns a reflection object of the given namespace.
	 *
	 * @param string $namespaceName Namespace name
	 * @return \TokenReflection\IReflectionNamespace
	 * @throws \TokenReflection\Exception\Runtime If the requested namespace does not exist
	 */
	public function getNamespace($namespaceName)
	{
		if (!isset($this->namespaces[TokenReflection\ReflectionNamespace::NO_NAMESPACE_NAME])) {
			$this->namespaces[TokenReflection\ReflectionNamespace::NO_NAMESPACE_NAME] = new TokenReflection\ReflectionNamespace(TokenReflection\ReflectionNamespace::NO_NAMESPACE_NAME, $this->broker);
		}

		$namespaceName = ltrim($namespaceName, '\\');
		if (!isset($this->namespaces[$namespaceName])) {
			throw new Exception\Runtime(sprintf('Namespace %s does not exist.', $namespaceName), TokenReflection\Exception::DOES_NOT_EXIST);
		}

		return $this->namespaces[$namespaceName];
	}

	/**
	 * Returns a reflection object of the given class (FQN expected).
	 *
	 * @param string $className CLass bame
	 * @return \TokenReflection\IReflectionClass
	 */
	public function getClass($className)
	{
		static $declared = array();
		if (empty($declared)) {
			$declared = array_flip(array_merge(get_declared_classes(), get_declared_interfaces()));
		}

		$className = ltrim($className, '\\');
		try {
			$ns = $this->getNamespace(
				($boundary = strrpos($className, '\\'))
					? substr($className, 0, $boundary)        // Class within a namespace
					: TokenReflection\ReflectionNamespace::NO_NAMESPACE_NAME  // Class without a namespace
			);

			return $ns->getClass($className);
		} catch (TokenReflection\Exception $e) {
			if (isset($declared[$className])) {
				$reflection = new Php\ReflectionClass($className, $this->broker);
				if ($reflection->isInternal()) {
					return $reflection;
				}
			}

			return new Dummy\ReflectionClass($className, $this->broker);
		}
	}

	/**
	 * Returns a reflection object of a function (FQN expected).
	 *
	 * @param string $functionName Function name
	 * @return \TokenReflection\IReflectionFunction
	 * @throws \TokenReflection\Exception\Runtime If the requested function does not exist
	 */
	public function getFunction($functionName)
	{
		static $declared = array();
		if (empty($declared)) {
			$functions = get_defined_functions();
			$declared = array_flip($functions['internal']);
		}

		$functionName = ltrim($functionName, '\\');
		try {
			$ns = $this->getNamespace(
				($boundary = strrpos($functionName, '\\'))
					? substr($functionName, 0, $boundary)     // Function within a namespace
					: TokenReflection\ReflectionNamespace::NO_NAMESPACE_NAME  // Function wihout a namespace
			);

			return $ns->getFunction($functionName);
		} catch (TokenReflection\Exception $e) {
			if (isset($declared[$functionName])) {
				return new Php\ReflectionFunction($functionName, $this->broker);
			}

			throw new Exception\Runtime(sprintf('Function %s does not exist.', $functionName), 0, $e);
		}
	}

	/**
	 * Returns a reflection object of a constant (FQN expected).
	 *
	 * @param string $constantName Constant name
	 * @return \TokenReflection\IReflectionConstant
	 * @throws \TokenReflection\Exception\Runtime If the requested constant does not exist
	 */
	public function getConstant($constantName)
	{
		static $declared = array();
		if (empty($declared)) {
			$declared = get_defined_constants();
		}

		if ($boundary = strpos($constantName, '::')) {
			// Class constant
			$className = substr($constantName, 0, $boundary);
			$constantName = substr($constantName, $boundary + 2);

			try {
				return $this->getClass($className)->getConstantReflection($constantName);
			} catch (TokenReflection\Exception $e) {
				throw new Exception\Runtime(sprintf('Constant %s does not exist.', $constantName), 0, $e);
			}
		}

		try {
			$constantName = ltrim($constantName, '\\');
			if ($boundary = strrpos($constantName, '\\')) {
				$ns = $this->getNamespace(substr($constantName, 0, $boundary));
				$constantName = substr($constantName, $boundary + 1);
			} else {
				$ns = $this->getNamespace(TokenReflection\ReflectionNamespace::NO_NAMESPACE_NAME);
			}

			return $ns->getConstant($constantName);
		} catch (TokenReflection\Exception $e) {
			$reflection = new Php\ReflectionConstant($constantName, $declared[$constantName], $this->broker);
			if ($reflection->isInternal()) {
				return $reflection;
			}

			throw new Exception\Runtime(sprintf('Constant %s does not exist.', $constantName), 0, $e);
		}
	}

	/**
	 * Returns if the given file was already processed.
	 *
	 * @retun boolean
	 */
	public function isFileProcessed($fileName)
	{
		return isset($this->tokenStreams[$fileName]);
	}

	/**
	 * Returns an array of tokens for a particular file.
	 *
	 * @return \ArrayIterator
	 * @throws \TokenReflection\Exception\Runtime If the requested file was not processed
	 */
	public function getFileTokens($fileName)
	{
		if (!$this->isFileProcessed($fileName)) {
			throw new Exception\Runtime(sprintf('The requested file %s was not processed.', $fileName), Exception\Runtime::DOES_NOT_EXIST);
		}

		return $this->tokenStreams[$fileName];
	}

	/**
	 * Adds a file to the backend storage.
	 *
	 * @param \TokenReflection\ReflectionFile $file File reflection object
	 * @param boolean $storeTokenStream Store the token stream
	 */
	public function addFile(TokenReflection\ReflectionFile $file, $storeTokenStream = true)
	{
		foreach ($file->getNamespaces() as $fileNamespace) {
			$namespaceName = $fileNamespace->getName();
			if (!isset($this->namespaces[$namespaceName])) {
				$this->namespaces[$namespaceName] = new TokenReflection\ReflectionNamespace($namespaceName, $file->getBroker());
			}

			$this->namespaces[$namespaceName]->addFileNamespace($fileNamespace);
		}

		if ($this->storingTokenStreams) {
			$this->tokenStreams[$file->getName()] = $file->getTokenStream();
		}

		// Reset the all-classes-cache
		$this->allClasses = null;
		return $this;
	}

	/**
	 * Sets the reflection broker instance.
	 *
	 * @param \TokenReflection\Broker $broker Reflection broker
	 */
	public function setBroker(Broker $broker)
	{
		$this->broker = $broker;
		return $this;
	}

	/**
	 * Returns the reflection broker instance.
	 *
	 * @return \TokenReflection\Broker $broker Reflection broker
	 */
	public function getBroker()
	{
		return $this->broker;
	}

	/**
	 * Sets if token streams are stored in the backend.
	 *
	 * @param boolean $store;
	 * @return \TokenReflection\Broker\Backend
	 */
	public function setStoringTokenStreams($store)
	{
		$this->storingTokenStreams = (bool) $store;
		return $this;
	}

	/**
	 * Returns if token streams are stored in the backend.
	 *
	 * @return boolean
	 */
	public function getStoringTokenStreams()
	{
		return $this->storingTokenStreams;
	}

	/**
	 * Prepares and returns used class lists.
	 *
	 * @return array
	 */
	protected function parseClassLists()
	{
		// Initialize the all-classes-cache
		$allClasses = array(
			self::TOKENIZED_CLASSES => array(),
			self::INTERNAL_CLASSES => array(),
			self::NONEXISTENT_CLASSES => array()
		);

		foreach ($this->namespaces as $namespace) {
			foreach ($namespace->getClasses() as $class) {
				$allClasses[self::TOKENIZED_CLASSES][$class->getName()] = $class;
			}
		}

		foreach ($allClasses[self::TOKENIZED_CLASSES] as $className => $class) {
			foreach (array_merge($class->getParentClasses(), $class->getInterfaces()) as $parent) {
				if ($parent->isInternal()) {
					$allClasses[self::INTERNAL_CLASSES][$parent->getName()] = $parent;
				} elseif (!$parent->isTokenized()) {
					$allClasses[self::NONEXISTENT_CLASSES][$parent->getName()] = $parent;
				}
			}
		}

		return $allClasses;
	}

	/**
	 * Returns all classes from all namespaces.
	 *
	 * @param integer $type Returned class types (multiple values may be OR-ed)
	 * @return array
	 */
	public function getClasses($type = self::TOKENIZED_CLASSES)
	{
		if (null === $this->allClasses) {
			$this->allClasses = $this->parseClassLists();
		}

		$result = array();
		foreach ($this->allClasses as $classType => $classes) {
			if ($type & $classType) {
				$result = array_merge($result, $classes);
			}
		}
		return $result;
	}
}