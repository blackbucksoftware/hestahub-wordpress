<?php
/**
 * @package HestaHub
 */

namespace HestaHub\Tests\Support;

/**
 * Answers the plugin's HTTP requests from a queue, through pre_http_request,
 * and records them. Call fake_hestahub() in set_up.
 */
trait FakeHestaHub {

	/** @var array[] Requests made, oldest first: url and args. */
	private $requests = array();

	/** @var array[] Responses still to hand out, oldest first. */
	private $responses = array();

	private function fake_hestahub(): void {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * pre_http_request callback: records the request, hands out the next response.
	 */
	public function answer( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( ! $this->responses ) {
			$this->fail( "Unexpected request to $url" );
		}
		return array_shift( $this->responses );
	}

	/**
	 * Queues a response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	private function respond( int $status, string $body = '' ): void {
		$this->responses[] = array(
			'headers'  => array( 'content-type' => 'application/json; charset=utf-8' ),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A vendored example body, optionally changed.
	 *
	 * @param string        $name   Example name: meta, apartments, availability, clinics, error.
	 * @param callable|null $change Receives the decoded body, returns it changed.
	 */
	private function example( string $name, $change = null ): string {
		$body = json_decode( file_get_contents( dirname( __DIR__ ) . "/fixtures/api/$name.example.json" ), true );
		if ( $change ) {
			$body = $change( $body );
		}
		return wp_json_encode( $body );
	}
}
