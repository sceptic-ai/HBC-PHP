<?php
namespace SeTaco\Config;


use SeTaco\IQueryResolver;
use SeTaco\Query\Selector;
use SeTaco\Query\ISelector;
use SeTaco\Query\Resolvers\CallbackQueryResolver;
use SeTaco\Exceptions\SeTacoException;
use SeTaco\Exceptions\FatalSeTacoException;

use Structura\Strings;


class QueryConfig
{
	private $defaultTimeout = 2.5;
	
	/** @var IQueryResolver[] */
	private $queryResolvers = [];
	
	/** @var IQueryResolver[] */
	private $genericResolvers = [];
	
	
	private function toSelector($selector, ?IQueryResolver $resolver = null): ISelector
	{
		if ($selector instanceof ISelector || is_null($selector))
		{
			$result = $selector;
		}
		else if (!is_string($selector))
		{
			throw new FatalSeTacoException('Resolver must return string or ISelector object');
		}
		else if (Strings::isStartsWith($selector, '//'))
		{
			$result = Selector::byXPath($selector);
		}
		else
		{
			$result = Selector::byCSS($selector);
		}
		
		if ($resolver)
		{
			$result->setResolver($resolver);
		}
		
		return $result;
	}
	
	private function runSingleResolver(IQueryResolver $resolver, string $query, bool $isCaseSensitive): ?ISelector
	{
		$result = $resolver->resolve($query, $isCaseSensitive);
		
		if (is_null($result))
			return null;
		
		$selector = $this->toSelector($result, $resolver);
		$selector->setOriginal($query);
		
		return $selector;
	}
	
	private function resolveByKeyword(string $query, bool $isCaseSensitive): ?ISelector
	{
		if (!Strings::contains($query, ':'))
			return null;
		
		[$key, $queryString] = explode(':', $query, 2);
		$resolver = $this->queryResolvers[$key] ?? null;
		
		if (!$resolver)
			return null;
		
		return $this->runSingleResolver($resolver, $queryString, $isCaseSensitive);
	}
	
	private function resolveGenerics(string $query, bool $isCaseSensitive): ?ISelector
	{
		foreach ($this->genericResolvers as $resolver)
		{
			$result = $this->runSingleResolver($resolver, $query, $isCaseSensitive);
			
			if (!is_null($result))
			{
				return $result;
			}
		}
		
		return null;
	}
	
	private function getResolver($resolver): IQueryResolver
	{
		if ($resolver instanceof IQueryResolver)
		{
			return $resolver;
		}
		else if (is_string($resolver))
		{
			return new $resolver();
		}
		else if (is_callable($resolver))
		{
			return new CallbackQueryResolver($resolver);
		}
		else
		{
			throw new FatalSeTacoException('Unexpected type for resolver');
		}
	}
	
	
	/**
	 * @param string|string[] $key
	 * @param IQueryResolver|callable|string $resolver
	 */
	public function addResolver($key, $resolver): void
	{
		if (is_array($key))
		{
			foreach ($key as $k)
			{
				$this->addResolver($k, $resolver);
			}
		}
		else
		{
			if (isset($this->queryResolvers[$key]))
				throw new SeTacoException("Resolver for the key $key, is already defined");
			
			$resolver = $this->getResolver($resolver);
			$this->queryResolvers[$key] = $resolver;
		}
	}
	
	/**
	 * @param IQueryResolver|callable|string $resolver
	 */
	public function addGenericResolver($resolver): void
	{
		$this->genericResolvers[] = $this->getResolver($resolver);
	}
	
	
	public function resolve(string $query, bool $isCaseSensitive = false): ISelector
	{
		$selector = $this->resolveByKeyword($query, $isCaseSensitive);
		
		if (!$selector)
		{
			$this->resolveGenerics($query, $isCaseSensitive);
		}
		
		if (!$selector)
		{
			$selector = $this->toSelector($query);
			$selector->setOriginal($query);
		}
			
		return $selector;
	}
	
	public function setDefaultTimeout(float $default): void
	{
		if ($default < 0.0)
			throw new SeTacoException("Default timeout must be 0 or greater. Got $default");
		
		$this->defaultTimeout = $default;
	}
	
	public function getTimeout(?float $given): float
	{
		return is_null($given) ? $this->defaultTimeout : $given;
	}
	
	public function getWaitUntil(?float $given): float
	{
		return microtime(true) + (is_null($given) ? $this->defaultTimeout : $given);
	}
	
	public function callbacksInvoker(): ?callable
	{
		return null;
	}
	
	/**
	 * @param callable $callback
	 * @return mixed
	 */
	public function invokeCallback(callable $callback)
	{
		$invoker = $this->callbacksInvoker();
		
		if ($invoker)
		{
			return $invoker($callback);
		}
		else
		{
			return $callback();
		}
	}
}