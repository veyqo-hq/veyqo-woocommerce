<?php
/**
 * A small Veyqo API client on the WordPress HTTP API.
 */

defined( 'ABSPATH' ) || exit;

class Veyqo_Api {
	const BASE_URL = 'https://api.veyqo.org/api/v1';

	/** @var string */
	private $secret_key;

	public function __construct( $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * @return array{ok: bool, status: int, data: mixed, error: ?array}
	 */
	public function request( $method, $path, $body = null, $idempotency_key = null ) {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->secret_key,
			'Accept'        => 'application/json',
			'User-Agent'    => 'veyqo-woocommerce/' . VEYQO_WC_VERSION,
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( 'POST' === $method ) {
			$headers['Idempotency-Key'] = $idempotency_key ? $idempotency_key : wp_generate_uuid4();
		}

		$response = wp_remote_request(
			self::BASE_URL . $path,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => null === $body ? null : wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0, 'data' => null, 'error' => array( 'code' => 'NETWORK_ERROR', 'message' => $response->get_error_message() ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$ok      = $status < 400 && ( ! is_array( $decoded ) || false !== ( $decoded['success'] ?? true ) );

		return array(
			'ok'     => $ok,
			'status' => $status,
			'data'   => is_array( $decoded ) ? ( $decoded['data'] ?? null ) : null,
			'error'  => $ok ? null : ( is_array( $decoded['error'] ?? null ) ? $decoded['error'] : array( 'code' => 'HTTP_' . $status, 'message' => 'Veyqo answered ' . $status ) ),
		);
	}

	/**
	 * Checks a Veyqo webhook. Returns the event, or null if it is not genuine.
	 */
	public static function verify_webhook( $raw_body, $timestamp, $signature_header, $secret, $tolerance = 300 ) {
		if ( '' === $secret || ! ctype_digit( (string) $timestamp ) || abs( time() - (int) $timestamp ) > $tolerance ) {
			return null;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $signature_header ) ) ) as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				$event = json_decode( $raw_body, true );
				return is_array( $event ) ? $event : null;
			}
		}
		return null;
	}

	/** Amount in the smallest unit, as Veyqo takes it. */
	public static function minor_units( $amount, $currency ) {
		$zero_decimal = array( 'JPY', 'KRW', 'UGX', 'RWF', 'XAF', 'XOF', 'VND', 'CLP' );
		return in_array( strtoupper( $currency ), $zero_decimal, true ) ? (int) round( $amount ) : (int) round( $amount * 100 );
	}
}
