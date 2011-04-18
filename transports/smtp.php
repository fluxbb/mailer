<?php

/**
 * Sends email using SMTP
 *
 * Copyright (C) 2011 FluxBB (http://fluxbb.org)
 * License: LGPL - GNU Lesser General Public License (http://www.gnu.org/licenses/lgpl.html)
 */

class SMTPMailTransport extends MailTransport
{
	const DEFAULT_HOST = 'localhost';
	const DEFAULT_PORT = 25;
	const DEFAULT_SSL = false;
	const DEFAULT_TIMEOUT = 10;
	const DEFAULT_STARTTLS = true;

	private static function get_hostname()
	{
		if (function_exists('gethostname'))
			return gethostname();

		return php_uname('n');
	}

	private $localhost;
	private $connection;
	private $extensions;

	/**
	* Initialise a new SMTP mailer.
	*/
	public function __construct($config)
	{
		$host = isset($config['host']) ? $config['host'] : self::DEFAULT_HOST;
		$port = isset($config['port']) ? $config['port'] : self::DEFAULT_PORT;
		$ssl = isset($config['ssl']) ? $config['ssl'] : self::DEFAULT_SSL;
		$timeout = isset($config['timeout']) ? $config['timeout'] : self::DEFAULT_TIMEOUT;
		$this->localhost = isset($config['localhost']) ? $config['localhost'] : self::get_hostname();

		$username = isset($config['username']) ? $config['username'] : null;
		$password = isset($config['password']) ? $config['password'] : null;
		$starttls = isset($config['starttls']) ? $config['starttls'] : self::DEFAULT_STARTTLS;

		// Create connection to the SMTP server
		$this->connection = new SMTPConnection($host, $port, $ssl, $timeout);

		// Check we received a valid welcome message (code 220)
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::SERVICE_READY)
			throw new Exception('Invalid connection response code received: '.$result['code']);

		// Negotiate and fetch a list of server supported extensions, if any
		$this->extensions = $this->negotiate();

		// If a username and password is given, attempt to authenticate
		if ($username !== null && $password !== null)
		{
			$result = $this->auth($username, $password, $starttls);
			if ($result === false)
				throw new Exception('Failed to login to SMTP server, invalid credentials.');
		}
	}

	private function negotiate()
	{
		// Attempt to send EHLO command
		$this->connection->write('EHLO '.$this->localhost);
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::OKAY)
		{
			// EHLO was rejected, try a HELO
			$this->connection->write('HELO '.$this->localhost);
			$result = $this->connection->read_response();
			if ($result['code'] != SMTPConnection::OKAY)
				throw new Exception('HELO was not accepted, response code: '.$result['code']);
		}

		// Check which extensions are enabled, if any
		$lines = explode("\r\n", $result['value']);
		array_shift($lines); // Throw away the first line, it's just a greeting

		$extensions = array();

		// The remaining lines are the extensions which are enabled
		foreach ($lines as $line)
		{
			$line = strtoupper($line);
			$delim = strpos($line, ' ');
			if ($delim === false)
				$extensions[$line] = true;
			else
			{
				$verb = substr($line, 0, $delim);
				$arg = substr($line, $delim + 1);

				$extensions[$verb] = $arg;
			}
		}

		return $extensions;
	}

	private function auth($username, $password, $starttls = true)
	{
		// If requested STARTTLS, and it is available (both here and the server), and we aren't already using SSL
		if ($starttls && extension_loaded('openssl') && !empty($this->extensions['STARTTLS']) && !$this->connection->is_secure())
		{
			$this->connection->write('STARTTLS');
			$result = $this->connection->read_response();
			if ($result['code'] != SMTPConnection::SERVICE_READY)
				throw new Exception('STARTTLS was not accepted, response code: '.$result['code']);

			// Enable TLS
			$this->connection->enable_crypto(true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

			// Renegotiate now that we have enabled TLS to get a new list of auth methods
			$this->extensions = $this->negotiate();
		}

		// Check if auth is actually supported
		if (empty($this->extensions['AUTH']))
			return false;

		$methods = explode(' ', $this->extensions['AUTH']);

		// If we have DIGEST-MD5 available, use it
		if (in_array('DIGEST-MD5', $methods))
			$result = $this->authDigestMD5($username, $password);
		// If we have CRAM-MD5 available, use it
		else if (in_array('CRAM-MD5', $methods))
			$result = $this->authCramMD5($username, $password);
		// If we have LOGIN available, use it
		else if (in_array('LOGIN', $methods))
			$result = $this->authLogin($username, $password);
		// Otherwise use PLAIN
		else if (in_array('PLAIN', $methods))
			$result = $this->authPlain($username, $password);
		// This shouldn't happen since at least PLAIN should be supported
		else
			throw new Exception('No supported authentication methods.');

		// Handle the returned result
		switch ($result['code'])
		{
			// Authentication Succeeded
			case 235: return true;
			// Authentication credentials invalid
			case 535: return false;
			// Other
			default: throw new Exception('Unrecognized response to auth attempt: '.$result['code']);
		}
	}

	private function authDigestMD5($username, $password)
	{
		// TODO
	}

	private function authCramMD5($username, $password)
	{
		// TODO
	}

	private function authLogin($username, $password)
	{
		$this->connection->write('AUTH LOGIN');
		$result = $this->connection->read_response();
		if ($result['code'] != 334)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Send the username
		$this->connection->write(base64_encode($username));
		$result = $this->connection->read_response();
		if ($result['code'] != 334)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Send the password
		$this->connection->write(base64_encode($password));
		return $this->connection->read_response();
	}

	private function authPlain($username, $password)
	{
		$this->connection->write('AUTH PLAIN');
		$result = $this->connection->read_response();
		if ($result['code'] != 334)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Send the username and password
		$this->connection->write(base64_encode(''.chr(0).$username.chr(0).$password));
		return $this->connection->read_response();
	}

	public function send($email, $to)
	{
		$message = $email->get_message();
		$headers = $email->get_headers();

		// TODO: Sanitize $to

		// Extract the from since SMTP wants it explicitly
		$from = $headers['From'];

		// TODO: Handle UTF8-decoding? TODO: Sanitize?

		// Add the to header
		$headers['To'] = Email::encode_utf8($to);

		$this->connection->write('MAIL FROM: <'.$from.'>');
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::OKAY)
			throw new Exception('Invalid response to mail attempt: '.$result['code']);

		$this->connection->write('RCPT TO: <'.$to.'>');
		$result = $this->connection->read_response();
		if ($result['code'] != 250 && $result['code'] != 251)
			throw new Exception('Invalid response to mail attempt: '.$result['code']);

		// Start with a blank message
		$data = '';

		// Append the header strings
		foreach ($headers as $key => $value)
			$data .= $key.': '.$value."\r\n";

		// Append the header divider
		$data .= "\r\n";

		// Append the message body
		$data .= $message."\r\n";

		// Append the DATA terminator
		$data .= '.';

		if (!empty($this->extensions['SIZE']) && (strlen($data) + 2) > $this->extensions['SIZE'])
			throw new Exception('Message size exceeds server limit: '.(strlen($data) + 2).' > '.$this->extensions['SIZE']);

		// Inform the server we are about to send data
		$this->connection->write('DATA');
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::START_INPUT)
			throw new Exception('Invalid response to data request: '.$result['code']);

		// Send the mail DATA
		$this->connection->write($data);
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::OKAY)
			throw new Exception('Invalid response to data terminaton: '.$result['code']);

		return true;
	}

	public function __destruct()
	{
		try
		{
			// Send the QUIT command
			$this->connection->write('QUIT');

			// Close the connection
			$this->connection->close();
		}
		catch (Exception $e) { } // Ignore errors since we are terminating anyway
	}
}

class SMTPConnection
{
	// Response codes. See http://www.greenend.org.uk/rjk/2000/05/21/smtp-replies.html
	const ERROR = -1;
	const SERVICE_READY = 220;
	const SERVICE_CLOSING = 221;
	const OKAY = 250;
	const START_INPUT = 354;

	private $socket;
	private $secure;

	public function __construct($hostname, $port, $secure, $timeout)
	{
		$this->secure = $secure;

		// Create a socket address
		$addr = $hostname.':'.$port;
		if ($this->secure)
			$addr = 'ssl://'.$addr;

		$errno = null;
		$errstr = null;
		$this->socket = stream_socket_client($addr, $errno, $errstr, $timeout);
		if ($this->socket === false)
			throw new Exception($errstr);
	}

	public function is_secure()
	{
		return $this->secure;
	}

	public function enable_crypto($enabled, $crypto_type)
	{
		return stream_socket_enable_crypto($this->socket, $enabled, $crypto_type);
	}

	public function read_line()
	{
		$line = fgets($this->socket);
		if ($line === false)
			return null;

		return rtrim($line, "\r\n");
	}

	public function read_response()
	{
		$code = self::ERROR;
		$values = array();

		while (($line = $this->read_line()) !== null)
		{
			$code = intval(substr($line, 0, 3));
			$values[] = trim(substr($line, 4));

			// If this is not a multiline response we're done
			if ($line{3} != '-')
				break;
		}

		return array('code' => $code, 'value' => implode("\r\n", $values));
	}

	public function write($line)
	{
		return fwrite($this->socket, $line."\r\n");
	}

	public function close()
	{
		if ($this->socket === null)
			return;

		fclose($this->socket);
		$this->socket = null;
	}
}
