<?php

namespace ADT;

use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Helpers;


class ErrorLogger extends \Tracy\Logger {

	/** @var \Nette\Security\User */
	protected $securityUser;

	/**
	 * Cesta k souboru s deníkem chyb
	 * @var string
	 */
	protected $logFile;

	/**
	 * Maximální počet odeslaných emailů denně
	 * @var int
	 */
	protected $maxEmailsPerDay;

	/**
	 * Maximální počet odeslaných emailů v rámci jednoho requestu
	 * @var int
	 */
	protected $maxEmailsPerRequest;

	/**
	 * Počet odeslaných emailů v rámci aktuálního requestu
	 * @var int
	 */
	protected $sentEmailsPerRequest = 0;

	/**
	 * @var \Nette\DI\Container
	 */
	protected $container;

	/**
	 * Statická instalace v bootstrap.php
	 * @param \SystemContainer|\Nette\DI\Container $container
	 */
	public static function install($container, $maxEmailsPerDay = NULL) {
		if (!Debugger::$productionMode) {
			return;
		}

		$logger = new static(
			$container, Debugger::$logDirectory, Debugger::$email, Debugger::getBlueScreen()
		);

		// nejdřív zkusíme použít argument, pokud je prázdný, tak config,
		// a pokud ani to nevyjde, tak defaultní hodnotu
		$logger->maxEmailsPerDay = $maxEmailsPerDay ?: (
			isset($container->parameters['logger']['maxEmailsPerDay'])
				? $container->parameters['logger']['maxEmailsPerDay']
				: 10
		);

		$logger->maxEmailsPerRequest = isset($container->parameters['logger']['maxEmailsPerRequest'])
			? $container->parameters['logger']['maxEmailsPerRequest']
			: 10;

		Debugger::setLogger($logger);
		Debugger::$maxLen = FALSE;

		
		/** @var \Nette\Security\User $securityUser */
		$securityUser = $container->getByType('\Nette\Security\User', FALSE);
		if ($securityUser) {
			$logger->injectSecurityUser($securityUser);
		}
		return $logger;
	}

	public function __construct($container, $directory, $email = NULL, \Tracy\BlueScreen $blueScreen = NULL)
	{
		parent::__construct($directory, $email, $blueScreen);

		$this->container = $container;
		$this->logFile = $this->directory . '/email-sent';
	}

	public function injectSecurityUser(\Nette\Security\User $securityUser) {
		$this->securityUser = $securityUser;
	}

	/**
	 * Logs message or exception to file and sends email notification.
	 * @param  string|\Exception
	 * @param  int   one of constant ILogger::INFO, WARNING, ERROR (sends email), EXCEPTION (sends email), CRITICAL (sends email)
	 * @return string logged error filename
	 */
	public function log($message, $priority = self::INFO)
	{
		if (!$this->directory) {
			throw new \LogicException('Directory is not specified.');
		} elseif (!is_dir($this->directory)) {
			throw new \RuntimeException("Directory '$this->directory' is not found or is not directory.");
		}

		$exceptionFile = $message instanceof \Exception ? $this->logException($message) : NULL;
		$line = $this->formatLogLine($message, $exceptionFile);
		$file = $this->directory . '/' . strtolower($priority ?: self::INFO) . '.log';

		if (!@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX)) {
			throw new \RuntimeException("Unable to write to log file '$file'. Is directory writable?");
		}

		if (in_array($priority, array(self::ERROR, self::EXCEPTION, self::CRITICAL), TRUE)) {

			if ($this->email && $this->mailer) {
				$messageHash = md5(preg_replace('~(Resource id #)\d+~', '$1', $message));
				$logContents = @file_get_contents($this->logFile, LOCK_SH);
				$today = (new \DateTime)->format('Y-m-d');
				$saveLog = FALSE;

				$log = json_decode($logContents, TRUE);
				if (json_last_error() && !empty($logContents)) {
					// pokud se nepovede parsování JSONu, zřejmě je log ještě ve starém formátu
					$log = [
						'hashes' => explode(PHP_EOL, $logContents),
						'counter' => 0,
						'date' => $today,
					];
					$saveLog = TRUE;
				} else if (empty($logContents)) {
					// prázdný nebo neexistující soubor
					$log = [
						'hashes' => [ ],
						'counter' => 0,
						'date' => $today,
					];
					$saveLog = TRUE;
				}

				$sendEmail = (
					// ještě se vejdeme do limitu v rámci aktuálního requestu
					$this->sentEmailsPerRequest < $this->maxEmailsPerRequest
					&&
					// tento hash jsme ještě neposlali
					!in_array($messageHash, $log['hashes'], TRUE)
					&& (
						// dnes je to první email
						$log['date'] !== $today
						||
						// ještě se vejdeme do limitu
						$log['counter'] < $this->maxEmailsPerDay
					)
				);

				if ($log['date'] !== $today) {
					// změnilo se datum, resetujeme počítadlo a datum aktualizujeme
					$log['date'] = $today;
					$log['counter'] = 0;
					$saveLog = TRUE;
				}

				if ($sendEmail) {
					// zalogujeme hash a inkrementujeme počítadlo
					$log['hashes'][] = $messageHash;
					$log['counter']++;
					$saveLog = TRUE;
				}

				if ($saveLog) {
					$logContents = json_encode($log);
					@file_put_contents($this->logFile, $logContents, LOCK_EX);
				}

				if ($sendEmail) {
					// sestavíme zprávu
					if (is_array($message)) {
						$stringMessage = implode(' ', $message);
					} else {
						$stringMessage = $message;
					}

					$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
					if (count($backtrace) > 3) { //pokud jsou 3 tak jde pouze o exception a je ulozena nette chybova stranka
						$backtraceString = "";

						for ($i = 0; $i < count($backtrace); $i++) {
							$backtraceData = $backtrace[$i] + [
									'file' => '_unknown_',
									'line' => '_unknown_',
									'function' => '_unknown_',
								];

							$backtraceString = "#$i {$backtraceData['file']}({$backtraceData['line']}): "
								. (isset($backtraceData['class']) ? $backtraceData['class'] . '::' : '')
								. "{$backtraceData['function']}()\n";
						}

						$stringMessage .= "\n\n" . $backtraceString;
					}

					// přidáme doplnující info - referer, browser...
					$stringMessage .= "\n\n" .
						(isset($_SERVER['HTTP_HOST']) ? 'LINK:' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "\n" : '') .
						'SERVER:' . Dumper::toText($_SERVER) . "\n\n" .
						'GET:' . Dumper::toText($_GET, [ Dumper::DEPTH => 10 ]) . "\n\n" .
						'POST:' . Dumper::toText($_POST, [ Dumper::DEPTH => 10 ]) . "\n\n" .
						($this->securityUser ? 'securityUser:' . Dumper::toText($this->securityUser->identity, [ Dumper::DEPTH => 1 ]) . "\n\n" : '');


					if (($git = $this->container->getByType('\ADT\TracyGit\Git', FALSE)) !== NULL && ($gitInfo = $git->getInfo())) {

						$stringMessage .= "\n\n";

						foreach ($git->getInfo() as $key => $value) {
							$stringMessage .= $key . ": " . $value . "\n";
						}
					}

					// odešleme chybu emailem
					call_user_func($this->mailer, $stringMessage, implode(', ', (array)$this->email), $exceptionFile);

					$this->sentEmailsPerRequest++;
				}
			}
		}

		return $exceptionFile;
	}


	/**
	 * Default mailer.
	 * @param  string|\Exception|\Throwable
	 * @param  string
	 * @return void
	 * @internal
	 */
	public function defaultMailer($message, $email, $attachment = NULL)
	{
    if ($attachment === NULL) {
      return parent::defaultMailer($message, $email);
    }

		$host = preg_replace('#[^\w.-]+#', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : php_uname('n'));

		$separator = md5(time());
		$eol = "\n";

		$filename = basename($attachment);
		$content = file_get_contents($attachment);
    $content = chunk_split(base64_encode($content));

		$parts = str_replace(
			["\r\n", "\n"],
			["\n", PHP_EOL],
			[
				'headers' => implode("\n", [
					'From: ' . ($this->fromEmail ?: "noreply@$host"),
					'X-Mailer: Tracy',
					'MIME-Version: 1.0',
					'Content-Type: multipart/mixed; boundary="' . $separator . '"',
					'Content-Transfer-Encoding: 7bit',
				]) . "\n",
				'subject' => "PHP: An error occurred on the server $host",
				'body' =>
					"--" . $separator . $eol.

					// Text email
					"Content-Type: text/plain; charset=\"UTF-8\"" . $eol.
					"Content-Transfer-Encoding: 8bit" . $eol.$eol.
					$this->formatMessage($message) . "\n\nsource: " . Helpers::getSource() . $eol.
					"--" . $separator . $eol.

					// Attachment
					"Content-Type: application/octet-stream; name=\"" . $filename . "\"" . $eol.
					"Content-Transfer-Encoding: base64" . $eol.
					"Content-Disposition: attachment" . $eol.$eol.
					$content . $eol.
					"--" . $separator . "--",
			]
		);

    mail($email, $parts['subject'], $parts['body'], $parts['headers']);
	}

}
