<?php
/**
 * FluxBB Mailer - Lightweight email library with transport abstraction
 * Copyright (C) 2011-2012 FluxBB.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * @category	FluxBB
 * @package		Mailer
 * @copyright	Copyright (c) 2011-2012 FluxBB (http://fluxbb.org)
 * @license		http://www.gnu.org/licenses/lgpl.html	GNU Lesser General Public License
 */

namespace FluxBB\Mailer\Transport;

use FluxBB\Mailer\Email,
	FluxBB\Mailer\Exception,
	FluxBB\Mailer\Mailer,
	FluxBB\Mailer\Transport\SMTP_Connection;

/**
 * Sends email using SMTP.
 */
class SMTP extends Mailer
{
	const DEFAULT_HOST = 'localhost';
	const DEFAULT_PORT = 25;
	const DEFAULT_SSL = false;
	const DEFAULT_TIMEOUT = 10;
	const DEFAULT_STARTTLS = true;

	private static function getHostName()
	{
		if (function_exists('gethostname'))
			return gethostname();

		return php_uname('n');
	}

	private $connection;
	private $authMethods;

	/**
	 * Initialise a new SMTP mailer.
	 */
	public function __construct($config)
	{
		$host = isset($config['host']) ? $config['host'] : self::DEFAULT_HOST;
		$port = isset($config['port']) ? $config['port'] : self::DEFAULT_PORT;
		$ssl = isset($config['ssl']) ? $config['ssl'] : self::DEFAULT_SSL;
		$timeout = isset($config['timeout']) ? $config['timeout'] : self::DEFAULT_TIMEOUT;
		$localhost = isset($config['localhost']) ? $config['localhost'] : self::getHostName();

		$username = isset($config['username']) ? $config['username'] : null;
		$password = isset($config['password']) ? $config['password'] : null;
		$starttls = isset($config['starttls']) ? $config['starttls'] : self::DEFAULT_STARTTLS;

		// Create connection to the SMTP server
		$this->connection = new SMTP_Connection($host, $port, $ssl, $timeout);

		// Check we received a valid welcome message (code 220)
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::SERVICE_READY)
			throw new Exception('Invalid connection response code received: '.$result['code']);

		// Negotiate and fetch a list of server supported extensions, if any
		$extensions = $this->negotiate($localhost);

		// If requested STARTTLS, and it is available (both here and the server), and we aren't already using SSL
		if ($starttls && extension_loaded('openssl') && !empty($extensions['STARTTLS']) && !$this->connection->isSecure())
		{
			$this->connection->write('STARTTLS');
			$result = $this->connection->readResponse();
			if ($result['code'] != SMTP_Connection::SERVICE_READY)
				throw new Exception('STARTTLS was not accepted, response code: '.$result['code']);

			// Enable TLS
			$this->connection->enableCrypto(true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

			// Renegotiate now that we have enabled TLS to get a new list of auth methods
			$extensions = $this->negotiate($localhost);
		}

		// Extract a list of supported auth methods
		$this->authMethods = empty($extensions['AUTH']) ? array() : explode(' ', $extensions['AUTH']);

		// If the server reported a maximum message size, use it
		if (!empty($extensions['SIZE']))
			$this->connection->setMaxbuf($extensions['SIZE']);

		// If a username and password is given, attempt to authenticate
		if ($username !== null && $password !== null)
		{
			$result = $this->auth($username, $password);
			if ($result === false)
				throw new Exception('Failed to login to SMTP server, invalid credentials.');
		}
	}

	private function negotiate($localhost)
	{
		// Attempt to send EHLO command
		$this->connection->write('EHLO '.$localhost);
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::OKAY)
		{
			// EHLO was rejected, try a HELO
			$this->connection->write('HELO '.$localhost);
			$result = $this->connection->readResponse();
			if ($result['code'] != SMTP_Connection::OKAY)
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

	private function auth($username, $password)
	{
		// Check if auth is actually supported
		if (empty($this->authMethods))
			return true;

		// If we have DIGEST-MD5 available, use it
		if (in_array('DIGEST-MD5', $this->authMethods))
			$result = $this->authDigestMD5($username, $password);
		// If we have CRAM-MD5 available, use it
		else if (in_array('CRAM-MD5', $this->authMethods))
			$result = $this->authCramMD5($username, $password);
		// If we have LOGIN available, use it
		else if (in_array('LOGIN', $this->authMethods))
			$result = $this->authLogin($username, $password);
		// Otherwise use PLAIN
		else if (in_array('PLAIN', $this->authMethods))
			$result = $this->authPlain($username, $password);
		// If we get this far we have no options, anonymous allows no auth though
		else if (in_array('ANONYMOUS', $this->authMethods))
			return true;
		// This shouldn't happen hopefully...
		else
			throw new Exception('No supported authentication methods.');

		// Handle the returned result
		switch ($result['code'])
		{
			// Authentication Succeeded
			case SMTP_Connection::AUTH_SUCCESS: return true;
			// Authentication credentials invalid
			case SMTP_Connection::AUTH_FAILURE: return false;
			// Other
			default: throw new Exception('Unrecognized response to auth attempt: '.$result['code']);
		}
	}

	private function authDigestMD5($username, $password)
	{
		$this->connection->write('AUTH DIGEST-MD5');
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Parse the challenge and check it was valid
		$challenge = $this->authDigestMD5_parseChallenge(base64_decode($result['value']));
		if (empty($challenge))
			throw new Exception('Received invalid challenge from AUTH DIGEST-MD5 attempt.');

		// If we have a maximum buffer size reported then use it
		if (!empty($challenge['maxbuf']))
			$this->connection->setMaxbuf($challenge['maxbuf']);

		// Generate a client nonce
		$cnonce = uniqid('', true); // Generate a client nonce

		// Check which QOP method to use
		$qopMethods = explode(',', $challenge['qop']);
		if (in_array('auth', $qopMethods))
			$qopMethod = 'auth';
		else
			throw new Exception('No supported qop method available, server reported: '.$challenge['qop']);

		// Generate the response digest
		$digest = base64_encode($this->authDigestMD5_generateDigest($username, $password, $challenge['realm'], $challenge['nonce'], $cnonce, $qopMethod));

		// Send the digest
		$this->connection->write($digest);
		$result = $this->connection->readResponse();

		// We received a negative response so return now
		if ($result['code'] == SMTP_Connection::AUTH_FAILURE)
			return $result;

		// If we got this far, check it was the correct response and continue
		if ($result['code'] != SMTP_Connection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to AUTH DIGEST-MD5 attempt: '.$result['code']);

		// SMTP doesn't allow subsequent authentication so we don't use this step
		$this->connection->write('');
		return $this->connection->readResponse();
	}

	private function authDigestMD5_generateDigest($username, $password, $realm, $nonce, $cnonce, $qopMethod)
	{
		$digest = array(
			'username'		=> $username,
			'realm'			=> $realm,
			'nonce'			=> $nonce,
			'cnonce'		=> $cnonce,
			'nc'			=> str_pad(1, 8, '0', STR_PAD_LEFT),
			'qop'			=> $qopMethod,
			'digest-uri'	=> 'smtp/'.$this->connection->getHost(),
			'maxbuf'		=> $this->connection->getMaxbuf(),
		);

		$HA1 = md5(md5($digest['username'].':'.$digest['realm'].':'.$password, true).':'.$digest['nonce'].':'.$digest['cnonce']);
		$HA2 = md5('AUTHENTICATE:'.$digest['digest-uri'].($digest['qop'] == 'auth' ? '' : str_repeat('0', 32)));
		$digest['response'] = md5($HA1.':'.$digest['nonce'].':'.$digest['nc'].':'.$digest['cnonce'].':'.$digest['qop'].':'.$HA2);
		unset ($HA1, $HA2);

		$temp = array();
		foreach ($digest as $key => $value)
			$temp[] = $key.'="'.$value.'"';

		return implode(',', $temp);
	}

	private function authDigestMD5_parseChallenge($challenge)
	{
		// Attempt to parse the challenge
		if (!preg_match_all('%([a-z-]+)=("[^"]+(?<!\\\)"|[^,]+)%i', $challenge, $matches, PREG_SET_ORDER))
			return array();

		$tokens = array();
		foreach ($matches as $match)
			$tokens[$match[1]] = trim($match[2], '"');

		// Check for required fields
		if (empty($tokens['nonce']) || empty($tokens['algorithm']))
			return array();

		// rfc2831 says to ignore these...
		unset ($tokens['opaque'], $tokens['domain']);

		// If there's no realm default to blank
		if (!isset($tokens['realm']))
			$tokens['realm'] = '';

		// If there's no maximum buffer size, set default
		if (empty($tokens['maxbuf']))
			$tokens['maxbuf'] = 65536;

		// If there's no qop default to auth
		if (empty($tokens['qop']))
			$tokens['qop'] = 'auth';

		return $tokens;
	}

	private function authCramMD5($username, $password)
	{
		$this->connection->write('AUTH CRAM-MD5');
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		$challenge = base64_decode($result['value']);
		$digest = base64_encode($username.' '.hash_hmac('md5', $challenge, $password));

		// Send the digest
		$this->connection->write($digest);
		return $this->connection->readResponse();
	}

	private function authLogin($username, $password)
	{
		$this->connection->write('AUTH LOGIN');
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Send the username
		$this->connection->write(base64_encode($username));
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Send the password
		$this->connection->write(base64_encode($password));
		return $this->connection->readResponse();
	}

	private function authPlain($username, $password)
	{
		$this->connection->write('AUTH PLAIN');
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Send the username and password
		$this->connection->write(base64_encode("\0".$username."\0".$password));
		return $this->connection->readResponse();
	}

	public function send($from, $recipients, $message, $headers)
	{
		$this->connection->write('MAIL FROM: <'.$from.'>');
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::OKAY)
			throw new Exception('Invalid response to mail attempt: '.$result['code']);

		// Add all the recipients
		foreach ($recipients as $recipient)
		{
			$this->connection->write('RCPT TO: <'.$recipient.'>');
			$result = $this->connection->readResponse();
			if ($result['code'] != SMTP_Connection::OKAY && $result['code'] != SMTP_Connection::WILL_FORWARD)
				throw new Exception('Invalid response to mail attempt: '.$result['code']);
		}

		// If we have a Bcc header, unset it so that it isn't sent!
		unset ($headers['Bcc']);

		// Start with a blank message
		$data = '';

		// Append the header strings
		$data .= Email::createHeaderStr($headers);

		// Append the header divider
		$data .= "\r\n";

		// Append the message body
		$data .= $message."\r\n";

		// Append the DATA terminator
		$data .= '.';

		// Inform the server we are about to send data
		$this->connection->write('DATA');
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::START_INPUT)
			throw new Exception('Invalid response to data request: '.$result['code']);

		// Send the mail DATA
		$this->connection->write($data);
		$result = $this->connection->readResponse();
		if ($result['code'] != SMTP_Connection::OKAY)
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
