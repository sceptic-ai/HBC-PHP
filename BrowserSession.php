<?php
namespace SeTaco;


use SeTaco\Config\TargetConfig;
use SeTaco\Session\IOpenBrowserHandler;
use SeTaco\Exceptions\SeTacoException;
use Structura\URL;


class BrowserSession implements IBrowserSession
{
	public static $start_selenium = true;
	
	
	private const DEFAULT_BROWSER_NAME = 'defaultBrowser';
	
	
	private static $selenium_started = false;
	
	
	/** @var TacoConfig */
	private $config;
	
	/** @var IOpenBrowserHandler */
	private $handler;
	
	/** @var IBrowser|null */
	private $current = null;
	
	/** @var IBrowser[] */
	private $browsers = [];
	
	
	private function getTarget(string $targetName): ?TargetConfig
	{
		return $this->config->hasTarget($targetName) ? $this->config->Targets[$targetName] : null;
	}
	
	private function openBrowser(string $browserName, TargetConfig $targetConfig, ?string $targetName = null): IBrowser
	{
		if (self::$start_selenium && !self::$selenium_started)
		{
			self::$selenium_started = true;
			SeTaco::startSeleniumIfNotRunning_CLI();
		}
		
		if ($this->hasBrowser($browserName))
		{
			$this->close($browserName);
		}
		
		$driver = $this->config()->createDriver();
		
		$setup = new BrowserSetup();
		$setup->RemoteWebDriver = $driver;
		$setup->TargetConfig = $targetConfig;
		$setup->TargetName = $targetName;
		$setup->BrowserName = $browserName;
		$setup->QueryConfig = $this->config->Query;
		
		$browser = new Browser($setup);
		
		if ($this->handler)
			$this->handler->onOpened($browser);
		
		$this->browsers[$browserName] = $browser;
		$this->current = $browser;
		
		return $browser;
	}
	
	private function openBrowserForURL(string $url, string $browserName): IBrowser
	{
		$parsedUrl = new URL($url);
		
		if (!$parsedUrl->Scheme)
		{
			if ($this->current)
				return $this->current->goto($parsedUrl->url());
			
			throw new SeTacoException('Failed to parse target and no current browser is selected');
		}
		
		$targetConfig = new TargetConfig();
		$targetConfig->URL = $parsedUrl->Scheme . '://' . $parsedUrl->Host;
		
		if ($parsedUrl->Port)
			$targetConfig->Port = $parsedUrl->Port;
		
		$browser = $this->openBrowser($browserName, $targetConfig);
		
		return $browser->goto($parsedUrl->url());
	}
	
	
	public function __construct(?TacoConfig $config = null)
	{
		$this->config = $config ?: TacoConfig::parse([]);
	}
	
	public function __destruct()
	{
		$this->close();
	}
	
	
	public function setOpenBrowserHandler(IOpenBrowserHandler $handler): void
	{
		$this->handler = $handler;
	}
	
	public function open(string $target = 'default', ?string $browserName = null): IBrowser
	{
		if (!$browserName)
			$browserName = self::DEFAULT_BROWSER_NAME;
		
		if ($this->hasBrowser($browserName))
		{
			$this->close($browserName);
			return $this->open($target, $browserName);
		}
		
		if (!$this->config->hasTarget($target))
			return $this->openBrowserForURL($target, $browserName);
		
		$targetConfig = $this->getTarget($target);
		
		return $this->openBrowser($browserName, $targetConfig, $target)->goto($targetConfig->URL);
	}
	
	public function getBrowser(string $browserName): ?IBrowser
	{
		if (!$this->hasBrowser($browserName))
			return null;

		$this->current = $this->browsers[$browserName];
		
		return $this->current;
	}
	
	public function hasBrowser(string $browserName): bool
	{
		return isset($this->browsers[$browserName]) && !$this->browsers[$browserName]->isClosed();
	}
	
	public function hasBrowsers(): bool
	{
		return (bool)$this->browsers;
	}
	
	public function current(): ?IBrowser
	{
		return $this->current;
	}
	
	/**
	 * @param string|IBrowser $browserName
	 * @return IBrowser
	 */
	public function select($browserName): IBrowser
	{
		if ($browserName instanceof IBrowser)
		{
			$browser = $browserName;
			$browserName = $browser->getBrowserName();
		}
		else
		{
			$browser = $this->getBrowser($browserName);
		}
		
		if (!$browser || $browser->isClosed())
		{
			unset($this->browsers[$browserName]);
			throw new SeTacoException("Browser with name '$browserName' does not exist in this session!");
		}
		
		$this->current = $browser;
		
		return $browser;
	}
	
	public function closeUnused(): void
	{
		foreach ($this->browsers as $browserName => $browser)
		{
			/** @noinspection PhpNonStrictObjectEqualityInspection */
			if ($browser != $this->current)
			{
				$browser->close();
				unset($this->browsers[$browserName]);
			}
		}
	}
	
	public function close($browserName = null): void
	{
		if (!$browserName)
		{
			foreach ($this->browsers as $browserName => $browser)
			{
				$this->close($browserName);
			}
			
			return;
		}
		
		
		if ($browserName instanceof IBrowser)
		{
			$browser = $browserName;
			$browserName = $browser->getBrowserName();
		}
		else
		{
			if (!$this->hasBrowser($browserName))
				return;
			
			$browser = $this->getBrowser($browserName);
		}
		
		
		if ($browser == $this->current)
			$this->current = null;
		
		$browser->close();
		unset($this->browsers[$browserName]);
	}
	
	public function config(): TacoConfig
	{
		return $this->config;
	}
}