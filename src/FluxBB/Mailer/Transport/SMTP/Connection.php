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

use FluxBB\Mailer\Exception;

class SMTP_Connection
{
	const DEBUG = false;

	// Response codes. See http://www.greenend.org.uk/rjk/2000/05/21/smtp-replies.html
	const ERROR = -1;
	const SERVICE_READY = 220;
	const SERVICE_CLOSING = 221;
	const AUTH_SUCCESS = 235;
	const OKAY = 250;
	const WILL_FORWARD = 251;
	const SERVER_CHALLENGE = 334;
	const START_INPUT = 354;
	const AUTH_FAILURE = 535;

	private $addr;
	private $socket;
	private $maxbuf;

	public function __construct($hostname, $port, $secure, $timeout)
	{
		// Create a socket address
		$this->addr = ($secure ? 'ssl' : 'tcp').'://'.$hostname.':'.$port;

		$errno = null;
		$errstr = null;
		$this->socket = stream_socket_client($this->addr, $errno, $errstr, $timeout);
		if ($this->socket === false)
			throw new Exception($errstr);

		$this->maxbuf = 65536;
	}

	public function setMaxbuf($maxbuf)
	{
		// Only update if the new limit is smaller than the existing limit
		if ($maxbuf < $this->maxbuf)
			$this->maxbuf = $maxbuf;
	}

	public function getMaxbuf()
	{
		return $this->maxbuf;
	}

	public function isSecure()
	{
		return parse_url($this->addr, PHP_URL_SCHEME) == 'ssl';
	}

	public function getHost()
	{
		return parse_url($this->addr, PHP_URL_HOST);
	}

	public function getPort()
	{
		return parse_url($this->addr, PHP_URL_PORT);
	}

	public function enableCrypto($enabled, $crypto_type)
	{
		return stream_socket_enable_crypto($this->socket, $enabled, $crypto_type);
	}

	public function readLine()
	{
		$line = fgets($this->socket);
		if ($line === false)
			return null;

		$line = rtrim($line, "\r\n");
		if (self::DEBUG)
			echo $line.PHP_EOL;

		return $line;
	}

	public function readResponse()
	{
		$code = self::ERROR;
		$values = array();

		while (($line = $this->readLine()) !== null)
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
		// Check the data + newline doesn't exceed the maximium buffer size
		if (strlen($line) + 2 > $this->maxbuf)
			throw new Exception('Message size exceeds server limit of '.$this->maxbuf);

		if (self::DEBUG)
			echo $line.PHP_EOL;

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
