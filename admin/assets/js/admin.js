/**
 * Media Optimizer by Webxperthub — Bulk Optimize admin UI.
 *
 * Wires the "Start Bulk Optimize" button to the queue AJAX endpoints
 * and drives the progress bar from real server-reported numbers only.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $startButton    = $( '#mopw-start-bulk' );
		var $progressWrap   = $( '#mopw-progress-wrap' );
		var $progressBar    = $( '#mopw-progress-bar' );
		var $progressText   = $( '#mopw-progress-text' );

		// Only run on the Bulk Optimize screen — this script is
		// enqueued on both admin pages, but the button only exists here.
		if ( ! $startButton.length ) {
			return;
		}

		var pollTimer          = null;
		var isRequestInFlight   = false;
		var totalQueuedAtStart  = 0;

		$startButton.on( 'click', function () {
			$startButton.prop( 'disabled', true ).text( mopwAdmin.startingLabel || 'Starting…' );

			$.post( mopwAdmin.ajaxUrl, {
				action: 'mopw_start_bulk',
				nonce:  mopwAdmin.nonce
			} ).done( function ( response ) {

				if ( ! response.success ) {
					handleFatalError( response.data && response.data.message );
					return;
				}

				totalQueuedAtStart = response.data.queued;

				if ( totalQueuedAtStart === 0 ) {
					$progressText.text( 'Nothing to optimize — your media library is already up to date.' );
					$startButton.prop( 'disabled', false ).text( 'Start Bulk Optimize' );
					return;
				}

				$progressWrap.show();
				$progressBar.attr( 'max', totalQueuedAtStart ).attr( 'value', 0 );
				$progressText.text( 'Queued ' + totalQueuedAtStart + ' images. Processing…' );

				pollTimer = setInterval( runNextBatch, 2000 );

			} ).fail( function () {
				handleFatalError( 'Could not reach the server. Please try again.' );
			} );
		} );

		function runNextBatch() {
			// Skip this tick entirely if the previous request hasn't
			// returned yet — never let two batches run concurrently.
			if ( isRequestInFlight ) {
				return;
			}

			isRequestInFlight = true;

			$.post( mopwAdmin.ajaxUrl, {
				action: 'mopw_run_batch',
				nonce:  mopwAdmin.nonce
			} ).done( function ( response ) {

				if ( ! response.success ) {
					handleFatalError( response.data && response.data.message );
					return;
				}

				var stats     = response.data;
				var completed = totalQueuedAtStart - stats.remaining;

				$progressBar.attr( 'value', completed );
				$progressText.text(
					'Processed ' + completed + ' of ' + totalQueuedAtStart +
					' (' + stats.failed + ' failed this batch)'
				);

				if ( stats.remaining <= 0 ) {
					clearInterval( pollTimer );
					$progressText.text( 'Done! ' + totalQueuedAtStart + ' images processed.' );
					$startButton.prop( 'disabled', false ).text( 'Start Bulk Optimize' );
				}

			} ).fail( function () {
				handleFatalError( 'Lost connection during processing. Refresh and click Start again to resume — already-processed images will be skipped automatically.' );
			} ).always( function () {
				isRequestInFlight = false;
			} );
		}

		function handleFatalError( message ) {
			if ( pollTimer ) {
				clearInterval( pollTimer );
			}
			$progressText.text( 'Error: ' + ( message || 'Something went wrong.' ) );
			$startButton.prop( 'disabled', false ).text( 'Start Bulk Optimize' );
		}

	} );

}( jQuery ) );