<?php
namespace SeTaco;


use Facebook\WebDriver\WebDriverSearchContext;
use Facebook\WebDriver\Exception\WebDriverException;

use SeTaco\Query\ISelector;
use SeTaco\Repeat\Repeater;
use SeTaco\Config\QueryConfig;

use SeTaco\Exceptions\Element\ElementException;
use SeTaco\Exceptions\Element\ElementObstructedException;
use SeTaco\Exceptions\Element\ElementNotEditableException;
use SeTaco\Exceptions\Element\DomElementNotVisibleException;

use SeTaco\Exceptions\QueryException;
use SeTaco\Exceptions\Query\ElementNotFoundException;
use SeTaco\Exceptions\Query\ElementStillExistsException;
use SeTaco\Exceptions\Query\MultipleElementsExistException;
use SeTaco\Exceptions\Query\QueriedElementNotEditableException;
use SeTaco\Exceptions\Query\QueriedElementNotClickableException;

use Structura\Arrays;


class Query implements IQuery
{
	/** @var BrowserSetup */
	private $setup;
	
	/** @var WebDriverSearchContext */
	private $context;
	
	/** @var QueryConfig */
	private $config;
	
	
	private function getSelector(string $query, bool $isCaseSensitive = false): ISelector
	{
		return $this->config->resolve($query, $isCaseSensitive);
	}
	
	/**
	 * @param string|string[] $query
	 * @param bool $isCaseSensitive
	 * @return ISelector[]
	 */
	private function getSelectors($query, bool $isCaseSensitive = false): array
	{
		$selectors = [];
		
		foreach (Arrays::toArray($query) as $item)
		{
			$selectors[] = $this->getSelector($item, $isCaseSensitive);
		}
		
		return $selectors;
	}
	
	/**
	 * @param ISelector|ISelector[] $selectors
	 * @return array
	 */
	private function getAllElements($selectors): array
	{
		$allElements = [];
		
		foreach ($selectors as $selector)
		{
			$driverSelector = $selector->getDriverSelector();
			$elements = $this->context->findElements($driverSelector);
			
			$allElements = array_merge($allElements, $elements);
		}
		
		return $allElements;
	}
	
	/**
	 * @param string|string[]|ISelector|ISelector[] $query
	 * @param float|null $timeout
	 * @param bool $isCaseSensitive
	 * @return IDomElementsCollection
	 */
	private function queryAll($query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElementsCollection
	{
		$selectors = $this->getSelectors($query, $isCaseSensitive);
		$endTime = $this->config->getWaitUntil($timeout);
		
		$elements = $this->getAllElements($selectors);
		
		while (!$elements && microtime(true) < $endTime)
		{
			$elements = $this->getAllElements($selectors);
		}
		
		$domElements = DomElement::convertAll($elements, $this->setup);
		
		return new DomElementsCollection($domElements);
	}
	
	/** @noinspection PhpInconsistentReturnPointsInspection */
	private function executeRetryCallback(callable $c, ?float $timeout = null)
	{
		$endTime = $this->config->getWaitUntil($timeout);
		
		while (true)
		{
			try
			{
				return $c(max(0.0, $endTime - microtime(true)));
			}
			catch (WebDriverException $wde)
			{
				$e = $wde;
			}
			catch (ElementException $ee)
			{
				$e = $ee;
			}
			catch (QueryException $qe)
			{
				$e = $qe;
			}
			
			if (microtime(true) > $endTime)
				throw $e;
			
			usleep(50000);
		}
	}
	
	/**
	 * @param string|string[] $query
	 * @param bool $isCaseSensitive
	 * @param IDomElement|IDomElement[] $e
	 * @throws QueriedElementNotClickableException
	 * @return IDomElement
	 */
	private function unsafeClickElement($query, bool $isCaseSensitive, $e): IDomElement
	{
		/** @var IDomElement[] $elements */
		$elements = Arrays::toArray($e);
		$selector = $this->getSelectors($query, $isCaseSensitive);
		
		$error = new ElementNotFoundException($selector);
		
		foreach ($elements as $e)
		{
			try
			{
				return $e->click();
			}
			catch (DomElementNotVisibleException $ev)
			{
				$error = new QueriedElementNotClickableException($selector, $ev->getMessage());
			}
			catch (ElementObstructedException $eo)
			{
				$error = new QueriedElementNotClickableException($selector, $eo->getMessage());
			}
		}
		
		throw $error;
	}
	
	private function unsafeInputElement(string $query, bool $isCaseSensitive,
		IDomElement $e, $value, bool $clear = false): IDomElement
	{
		$selector = $this->getSelector($query, $isCaseSensitive);
		
		try
		{
			$e->input($value, $clear);
		}
		catch (ElementNotEditableException $eee)
		{
			throw new QueriedElementNotEditableException($selector, $eee->getMessage());
		}
		catch (DomElementNotVisibleException $ev)
		{
			throw new QueriedElementNotEditableException($selector, $ev->getMessage());
		}
		catch (ElementObstructedException $eo)
		{
			throw new QueriedElementNotEditableException($selector, $eo->getMessage());
		}
		
		return $e;
	}
	
	private function unsafeClick(callable $search, $query, ?float $timeout, bool $isCaseSensitive)
	{
		return $this->executeRetryCallback(
			function(float $timeout)
				use ($search, $query, $isCaseSensitive, &$result)
			{
				$el = $search($query, $timeout, $isCaseSensitive);
				return $this->unsafeClickElement($query, $isCaseSensitive, $el);
			},
			$timeout);
	}
	
	private function execInput(string $query, string $value, ?float $timeout = null, bool $isCaseSensitive = false,
		bool $clear = false): IDomElement
	{
		return $this->executeRetryCallback(
			function(float $timeout)
				use ($query, $isCaseSensitive, $value, $clear)
			{
				$el = $this->find($query, $timeout, $isCaseSensitive);
				return $this->unsafeInputElement($query, $isCaseSensitive, $el, $value, $clear);
			},
			$timeout);
	}
	
	
	public function __construct(BrowserSetup $setup, WebDriverSearchContext $searchContext)
	{
		$this->setup	= $setup;
		$this->config	= $setup->QueryConfig;
		$this->context	= $searchContext;
	}
	
	
	public function exists(string $query, ?float $timeout = null, bool $isCaseSensitive = false): bool
	{
		return $this->existsAll([$query], $timeout, $isCaseSensitive);
	}
	
	public function existsAny(array $query, ?float $timeout = null, bool $isCaseSensitive = false): bool
	{
		$endTime = $this->config->getWaitUntil($timeout);
		
		while (true)
		{
			foreach ($query as $item)
			{
				if (!$this->findAll($item, 0.0, $isCaseSensitive)->isEmpty())
				{
					return true;
				}
			}
			
			if (microtime(true) >= $endTime)
			{
				break;
			}
			
			usleep(50000);
		}
		
		return false;
	}
	
	public function existsAll(array $query, ?float $timeout = null, bool $isCaseSensitive = false): bool
	{
		$endTime = $this->config->getWaitUntil($timeout);
		
		foreach ($query as $item)
		{
			$timeout = max(0.0, $endTime - microtime(true));
			
			if ($this->findAll($item, $timeout, $isCaseSensitive)->isEmpty())
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function count($query, ?float $timeout = null, bool $isCaseSensitive = false): int
	{
		return $this->findAll($query, $timeout, $isCaseSensitive)->count();
	}
	
	public function text(string $query, ?float $timeout = null, bool $isCaseSensitive = false): string
	{
		return $this->find($query, $timeout, $isCaseSensitive)->getText();
	}
	
	public function find(string $query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		$selector = $this->getSelector($query, $isCaseSensitive);
		$all = $this->queryAll($query, $timeout, $isCaseSensitive);
		
		if ($all->isOne())
		{
			return $all->first();
		}
		else if ($all->count() > 1)
		{
			throw new MultipleElementsExistException($selector);
		}
		else
		{
			throw new ElementNotFoundException($selector);
		}
	}
	
	public function findFirst($query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		$selectors = $this->getSelectors($query, $isCaseSensitive);
		$all = $this->queryAll($query, $timeout, $isCaseSensitive);
		
		if (!$all->hasAny())
			throw new ElementNotFoundException($selectors);
		
		return $all->first();
	}
	
	public function findAll($query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElementsCollection
	{
		return $this->queryAll($query, $timeout, $isCaseSensitive);
	}
	
	public function tryFind(string $query, ?float $timeout = null, bool $isCaseSensitive = false): ?IDomElement
	{
		$all = $this->queryAll($query, $timeout, $isCaseSensitive);
		return $all->isOne() ? $all->first() : null; 
	}
	
	public function tryFindFirst($query, ?float $timeout = null, bool $isCaseSensitive = false): ?IDomElement
	{
		return $this->queryAll($query, $timeout, $isCaseSensitive)->first();
	}

	public function waitForElement(string $query, ?float $timeout = null, bool $isCaseSensitive = false): IQuery
	{
		$this->find($query, $timeout, $isCaseSensitive);
		return $this;
	}
	
	public function waitForAnyElements($query, ?float $timeout = null, bool $isCaseSensitive = false): IQuery
	{
		$this->findFirst($query, $timeout, $isCaseSensitive);
		return $this;
	}
	
	public function waitForElements($query, ?float $timeout = null, bool $isCaseSensitive = false): IQuery
	{
		$query = Arrays::toArray($query);
		$endTime = $this->config->getWaitUntil($timeout);
		
		foreach ($query as $item)
		{
			$this->findFirst($item, max(0.0, $endTime - microtime(true)), $isCaseSensitive);
		}
		
		return $this;
	}
	
	public function waitToDisappear(string $query, ?float $timeout = null, bool $isCaseSensitive = false): IQuery
	{
		$this->waitAllToDisappear($query, $timeout, $isCaseSensitive);
		return $this;
	}
	
	public function waitAllToDisappear($query, ?float $timeout = null, bool $isCaseSensitive = false): IQuery
	{
		$timeout = $this->config->getTimeout($timeout);
		$endTime = $this->config->getWaitUntil($timeout);
		
		$originalSelectors = $this->getSelectors($query, $isCaseSensitive);
		$selectors = $originalSelectors;
		$selector = array_shift($selectors);
		
		while ($selector)
		{
			while ($selector)
			{
				if (!$selector->searchIn($this->context))
				{
					$selector = array_shift($selectors);
				}
				else
				{
					usleep(50000);
					break;
				}
			}
			
			if (microtime(true) >= $endTime)
			{
				break;
			}
		}
		
		if ($selector && microtime(true) >= $endTime)
		{
			throw new ElementStillExistsException($originalSelectors, $timeout);
		}
		
		return $this;
	}
	
	public function waitAnyToDisappear($query, ?float $timeout = null, bool $isCaseSensitive = false): IQuery
	{
		$timeout = $this->config->getTimeout($timeout);
		$endTime = $this->config->getWaitUntil($timeout);
		
		$selectors = $this->getSelectors($query, $isCaseSensitive);
		
		while (true)
		{
			foreach ($selectors as $selector)
			{
				if (!$selector->searchIn($this->context))
				{
					return $this;
				}
			}
			
			usleep(50000);
			
			if (microtime(true) >= $endTime)
			{
				break;
			}
		}
		
		throw new ElementStillExistsException($selectors, $timeout);
	}
	
	public function input(string $query, string $value, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		return $this->execInput($query, $value, $timeout, $isCaseSensitive, false);
	}
	
	public function clearAndInput(string $query, string $value, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		return $this->execInput($query, $value, $timeout, $isCaseSensitive, true);
	}
	
	public function click(string $query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		return $this->unsafeClick(
			function (string $query, ?float $timeout = null, bool $isCaseSensitive = false)
			{
				return $this->find($query, $timeout, $isCaseSensitive);
			},
			$query, $timeout, $isCaseSensitive
		);
	}
	
	public function clickAny($query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		return $this->unsafeClick(
			function ($query, ?float $timeout = null, bool $isCaseSensitive = false)
			{
				return $this->findAll($query, $timeout, $isCaseSensitive)->get();
			},
			$query, $timeout, $isCaseSensitive
		);
	}
	
	public function hover(string $query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		return $this->find($query, $timeout, $isCaseSensitive)->hover();
	}
	
	public function hoverAny($query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		return $this->findFirst($query, $timeout, $isCaseSensitive)->hover();
	}
	
	public function hoverAndClick(string $query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		return $this->unsafeClick(
			function (string $query, ?float $timeout = null, bool $isCaseSensitive = false)
			{
				$el = $this->find($query, $timeout, $isCaseSensitive)->hover();
				return $el;
			},
			$query, $timeout, $isCaseSensitive
		);
	}
	
	public function hoverAndClickAny($query, ?float $timeout = null, bool $isCaseSensitive = false): IDomElement
	{
		return $this->unsafeClick(
			function ($query, ?float $timeout = null, bool $isCaseSensitive = false)
			{
				$el = $this->findFirst($query, $timeout, $isCaseSensitive)->hover();
				return $el;
			},
			$query, $timeout, $isCaseSensitive
		);
	}
	
	public function while(callable $callback, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->while($callback, $delay, $timeout); 
	}
	
	public function whileNull(callable $callback, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileNull($callback, $delay, $timeout);
	}
	
	public function whileNotNull(callable $callback, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileNotNull($callback, $delay, $timeout);
	}
	
	public function whileEquals(callable $callback, $value, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileEquals($callback, $value, $delay, $timeout);
	}
	
	public function whileNotEquals(callable $callback, $value, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileNotEquals($callback, $value, $delay, $timeout);
	}
	
	public function whileSame(callable $callback, $value, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileSame($callback, $value, $delay, $timeout);
	}
	
	public function whileNotSame(callable $callback, $value, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileNotSame($callback, $value, $delay, $timeout);
	}
	
	public function whileThrowing(callable $callback, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileThrowing($callback, $delay, $timeout);
	}
	
	public function whileElementExists(string $selector, bool $isCaseSensitive = false, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileElementExists($selector, $isCaseSensitive, $delay, $timeout);
	}
	
	public function whileElementMissing(string $selector, bool $isCaseSensitive = false, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileElementMissing($selector, $isCaseSensitive, $delay, $timeout);
	}
	
	public function whileAnyElementExists(array $selector, bool $isCaseSensitive = false, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileAnyElementExists($selector, $isCaseSensitive, $delay, $timeout);
	}
	
	public function whileAnyElementMissing(array $selector, bool $isCaseSensitive = false, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileAnyElementMissing($selector, $isCaseSensitive, $delay, $timeout);
	}
	
	public function whileAllElementsExist(array $selector, bool $isCaseSensitive = false, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileAllElementsExist($selector, $isCaseSensitive, $delay, $timeout);
	}
	
	public function whileAllElementsMissing(array $selector, bool $isCaseSensitive = false, float $delay = 0.1, ?float $timeout = null): IRepeatAction
	{
		return (new Repeater($this, $this->config))->whileAllElementsMissing($selector, $isCaseSensitive, $delay, $timeout);
	}
}