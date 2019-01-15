<?php

function urlsafeB64Decode($input)
{
	$remainder = strlen($input) % 4;
	if ($remainder) {
		$padlen = 4 - $remainder;
		$input .= str_repeat('=', $padlen);
	}
	return base64_decode(strtr($input, '-_', '+/'));
}

function jwtDecode($jwt, $key)
{
	$timestamp = time();
	if (empty($key)) 
	{
		return false;
	}
	
	$tks = explode('.', $jwt);
	if (count($tks) != 3) 
	{
		return false;
	}
	
	list($headb64, $bodyb64, $cryptob64) = $tks;
	
	$header = json_decode(urlsafeB64Decode($headb64));
	if (!$header)
	{
		return false;
	}
	
	$payload = json_decode(urlsafeB64Decode($bodyb64));
	if (!$payload) 
	{
		return false;
	}
	
	$sig = urlsafeB64Decode($cryptob64);
	if (!$sig)
	{
		return false;
	}
	
	if (!isset($header->alg))
	{
		return false;
	}

	$supported_algs = array(
		'HS256' => 'SHA256',
		'HS512' => 'SHA512',
		'HS384' => 'SHA384',
	);
	if (!isset($supported_algs[$header->alg]))
	{
		return false;
	}
	
	$algorithm = $supported_algs[$header->alg];
	$hash = hash_hmac($algorithm, "$headb64.$bodyb64", $key, true);
	if (!hash_equals($sig, $hash))
	{
		return false;
	}

	if (isset($payload->nbf) && $payload->nbf > $timestamp) 
	{
		return false;
	}

	if (isset($payload->iat) && $payload->iat > $timestamp) 
	{
		return false;
	}

	if (isset($payload->exp) && $timestamp >= $payload->exp) 
	{
		return false;
	}
	return $payload;
}
