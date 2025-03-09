<?php
namespace SeTaco;


use Facebook\WebDriver\WebDriverKeys;
use SeTaco\Config\TargetConfig;
use SeTaco\Exceptions\Browser\URLCompareException;

use Facebook\WebDriver\Cookie;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteKeyboard;

use SeTaco\Utils\ShutdownFallback;
use Structura\Strings;


class Browser extends Query implements IBrowser
{
	private $isClosed = false;
	
	/** @var BrowserSetup */
	private $config;
	
	
	public function __construct(BrowserSetup $config)
	{
		parent::__construct($config, $config->RemoteWebDriver);
		$this->config = $config;
		
		ShutdownFallback::addBrowser($this);
	}
	
	public function __destruct()
	{
		$this->close();
	}
	
	
	public function getRemoteWebDriver(): RemoteWebDriver
	{
		return $this->config->RemoteWebDriver;
	}
	
	public function getTargetName(): ?string
	{
		return $this->config->TargetName;
	}
	
	public function getTargetConfig(): TargetConfig
	{
		return $this->config->TargetConfig;
	}
	
	public function getBrowserName(): string
	{
		return $this->config->BrowserName;
	}
	
	public function goto(string $url): IBrowser
	{
		$this->getRemoteWebDriver()->navigate()->to($this->getTargetConfig()->getURL($url));
		return $this;
	}
	
	public function formInput(array $keywordValuePairs, ?string $submit = null, ?float $timeout = null): IBrowser
	{
		foreach ($keywordValuePairs as $query => $value)
		{
			if (!Strings::contains($query, ':'))
				$query = "attr:form//input name=$query";
			
			$this->input($query, $value, $timeout);
		}
		
		if ($submit)
		{
			if (!Strings::contains($submit, ':'))
				$submit = "txt:$submit";
			
			$this->click($submit, $timeout);
		}
		
		return $this;
	}
	
	public function compareURL(string $url): bool
	{
		$currentURL = $this->getURL();
		
		if ($currentURL == $url)
			return true;
		
		$pattern = '/' . str_replace('/', '\\/', $url) . '/';
		
		return preg_match($pattern, $currentURL);
	}
	
	public function waitForURL(string $url, ?float $timeout = null): void
	{
		$timeout = $this->config->QueryConfig->getTimeout($timeout);
		$endTime = microtime(true) + $timeout;
		
		while (!$this->compareURL($url))
		{
			if (microtime(true) >= $endTime)
			{
				$currentUrl = $this->getURL();
				throw new URLCompareException($url, $currentUrl, $timeout);
			}
			
			usleep(50000);
		}
	}
	
	public function getTitle(): string
	{
		return $this->getRemoteWebDriver()->getTitle();
	}
	
	public function getURL(): string
	{
		return $this->getRemoteWebDriver()->getCurrentURL();
	}
	
	public function refresh(): void
	{
		$this->getRemoteWebDriver()->navigate()->refresh();
	}
	
	public function isClosed(): bool
	{
		return $this->isClosed;
	}
	
	public function close(): void
	{
		if ($this->isClosed)
			return;
		
		$this->isClosed = true;
		$this->getRemoteWebDriver()->close();
		
		ShutdownFallback::removeBrowser($this);
	}
	
	/**
	 * @param array|string $data If string, used as cookie name. 
	 * @param null|string $value If $data is string and $value is null, delete the cookie.
	 */
	public function setCookie($data, ?string $value = null): void
	{
		if (is_string($data))
		{
			if (is_null($value))
			{
				$this->getRemoteWebDriver()->manage()->deleteCookieNamed($data);
			}
			else
			{
				$this->getRemoteWebDriver()->manage()->addCookie(['name' => $data, 'value' => $value]);
			}
		}
		else
		{
			$this->getRemoteWebDriver()->manage()->addCookie($data);
		}
	}
	
	/**
	 * @return Cookie[]
	 */
	public function cookies(): array
	{
		return $this->getRemoteWebDriver()->manage()->getCookies();
	}
	
	public function deleteCookie(string $named): void
	{
		$this->getRemoteWebDriver()->manage()->deleteCookieNamed($named);
	}
	
	public function deleteCookies(): void
	{
		$this->getRemoteWebDriver()->manage()->deleteAllCookies();
	}
	
	
	public function press(string $key): void
	{
		$this->keyboard()->pressKey($key);
	}
	
	public function pressEsc(): void
	{
		$this->press(WebDriverKeys::ESCAPE);
	}
	
	public function pressEnter(): void
	{
		$this->press(WebDriverKeys::ENTER);
	}
	
	public function keyboard(): RemoteKeyboard
	{
		return $this->getRemoteWebDriver()->getKeyboard();
	}
}